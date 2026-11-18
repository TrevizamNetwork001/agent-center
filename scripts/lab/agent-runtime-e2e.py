"""Run the built Agent and its real action worker against a loopback-only fixture.

No production credential, enrollment, device operation or external probe is used.
Run as an unprivileged user; every generated file lives in a disposable directory.
"""

import http.server
import importlib.util
import json
import os
import sqlite3
import subprocess
import sys
import tempfile
import threading
import time
import uuid
from pathlib import Path


def main():
    if os.geteuid() == 0:
        print('{"error":"unprivileged_user_required"}')
        return 2
    root = Path(__file__).resolve().parents[2]
    spec = importlib.util.spec_from_file_location('agent_build', root / 'agent/packaging/build.py')
    builder = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(builder)
    identity = str(uuid.uuid4())
    token = identity + '.' + 'a' * 64
    task = {'task_id': str(uuid.uuid4()), 'execution_id': str(uuid.uuid4()),
            'generation': 1, 'lease_token': 'b' * 64, 'timeout': 3,
            'capability': 'agent_info', 'input': {}}
    policy = {'schema_version': 1, 'agent_id': identity, 'device_operations_frozen': True,
              'tasks_enabled': True, 'task_protocol': 1, 'allowed_capabilities': ['agent_info']}
    accepted = threading.Event()
    guard = threading.Lock()
    observed = {'claimed': False, 'starts': 0, 'results': [], 'inspector': False}

    class Handler(http.server.BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass

        def do_POST(self):
            length = int(self.headers.get('Content-Length', '0'))
            if not 0 < length <= 1_048_576 or self.headers.get('Authorization') != 'Bearer ' + token:
                self.send_error(400)
                return
            data = json.loads(self.rfile.read(length))
            operation = self.path.removeprefix('/api/v1/agent/')
            body = {'status': 'ok', 'policy': policy}
            with guard:
                if operation == 'capabilities':
                    body = {'capabilities': data['capabilities']}
                elif operation == 'claim':
                    body = {'task': None if observed['claimed'] else task, 'policy': policy}
                    observed['claimed'] = True
                elif operation in ('start', 'result', 'renew'):
                    if any(data.get(key) != task[key] for key in ('task_id', 'execution_id', 'generation', 'lease_token')):
                        self.send_error(409)
                        return
                    if operation == 'start':
                        observed['starts'] += 1
                    elif operation == 'result':
                        observed['results'].append(data['result'])
                        accepted.set()
                elif operation == 'reconcile':
                    body = {'executions': []}
                elif operation == 'heartbeat':
                    report = data.get('observability', {})
                    observed['inspector'] |= report.get('version') == version and bool(report.get('capabilities'))
                elif operation != 'heartbeat':
                    self.send_error(404)
                    return
            raw = json.dumps(body).encode()
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(raw)))
            self.end_headers()
            self.wfile.write(raw)

    os.umask(0o077)
    with tempfile.TemporaryDirectory(prefix='agent-runtime-e2e-') as directory:
        lab = Path(directory)
        artifact = lab / 'agent.pyz'
        artifact_hash, source_hash = builder.build(artifact)
        repeated = lab / 'repeated.pyz'
        builder.build(repeated)
        assert artifact.read_bytes() == repeated.read_bytes(), 'non_deterministic_build'
        checked = subprocess.run([sys.executable, '-I', str(artifact), '--self-check'],
                                 capture_output=True, check=True, timeout=16)
        version = json.loads(checked.stdout)['version']
        state = lab / 'state'
        state.mkdir(mode=0o700)
        (state / 'identity.json').write_text(json.dumps({'agent_id': identity, 'token': token}))
        server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        listener = threading.Thread(target=server.serve_forever, daemon=True)
        listener.start()
        config = lab / 'config.json'
        config.write_text(json.dumps({'central_url': f'http://127.0.0.1:{server.server_port}',
            'allow_http_loopback': True, 'state_dir': str(state), 'log_dir': str(lab / 'logs'),
            'identity_file': str(state / 'identity.json'), 'heartbeat_interval': 1,
            'poll_interval': 1, 'timeout': 1, 'device_operations_frozen': True,
            'allowed_networks': [], 'allowed_dns_names': []}))
        process = None
        try:
            process = subprocess.Popen([sys.executable, '-I', str(artifact), '--config', str(config)],
                                       stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            assert accepted.wait(15), 'packaged_result_not_received'
            # Receiving RESULT precedes its HTTP acknowledgement. Wait for the
            # Agent's durable completion and heartbeat before testing shutdown.
            settled = time.monotonic() + 5
            while time.monotonic() < settled:
                status = json.loads((state / 'status.json').read_text())
                with sqlite3.connect(state / 'journal.sqlite3') as journal:
                    pending = journal.execute("SELECT count(*) FROM executions WHERE state!='completed'").fetchone()[0]
                if not pending and status['heartbeat_successes'] > 0 and status['poll_successes'] > 0 and observed['inspector']:
                    break
                time.sleep(0.05)
            else:
                raise AssertionError('packaged_protocol_did_not_settle')
            process.terminate()
            stdout, stderr = process.communicate(timeout=12)
            assert process.returncode == 0, 'packaged_runtime_shutdown_failed'
            assert token.encode() not in stdout + stderr and task['lease_token'].encode() not in stdout + stderr, 'credential_in_output'
            assert observed['starts'] == 1 and len(observed['results']) == 1, 'duplicate_execution'
            result = observed['results'][0]
            assert result['ok'] is True and result['data']['version'] == version, 'wrong_packaged_result'
            assert result['data']['device_operations_frozen'] is True, 'freeze_not_preserved'
            with sqlite3.connect(state / 'journal.sqlite3') as journal:
                assert journal.execute('PRAGMA quick_check').fetchone()[0] == 'ok', 'invalid_journal'
                assert journal.execute('SELECT state FROM executions').fetchall() == [('completed',)], 'result_not_acknowledged'
                events = journal.execute('SELECT count(*) FROM events').fetchone()[0]
            assert events == 4 and not list((state / 'leases').iterdir()), 'lease_or_journal_not_finalized'
            status = json.loads((state / 'status.json').read_text())
            assert status['state'] == 'STOPPED' and status['heartbeat_successes'] > 0 and status['poll_successes'] > 0, 'invalid_final_status'
            print(json.dumps({'passed': True, 'version': version, 'unprivileged': True,
                'deterministic_build': True, 'artifact_sha256': artifact_hash,
                'source_sha256': source_hash, 'real_packaged_action_worker': True, 'real_packaged_observation_worker': observed['inspector'],
                'starts': observed['starts'], 'results': len(observed['results']),
                'journal_events': events, 'lease_removed': True, 'freeze': True,
                'credential_in_output': False, 'transport': 'loopback_fixture_only'}))
        finally:
            if process and process.poll() is None:
                process.kill()
                process.communicate(timeout=5)
            server.shutdown()
            server.server_close()
            listener.join(timeout=2)
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
