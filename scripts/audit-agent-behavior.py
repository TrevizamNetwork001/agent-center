"""Offline fault injection against the immutable 0.5.3 companion and 0.5.4 package.

Uses temporary state, fake service/API, and loop-free bounded cases. Never imports
host identity, contacts the Central, or operates a real systemd service/device.
Run in the Python tooling container with only SETUID/SETGID for real self-checks.
"""

import argparse
import hashlib
import io
import importlib
import json
import shutil
import sys
import tempfile
import uuid
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

root = Path(__file__).resolve().parents[1]
companion = root / 'artifacts/agentkit/0.5.3/remote-updater.pyz'
candidate = root / 'artifacts/agentkit/0.5.4/agent.pyz'
sha = 'bb8c0274b9fc2121c283136b2ad66a9b67037c0c7d32e44f79f44b5d33f5cb19'
assert hashlib.sha256(candidate.read_bytes()).hexdigest() == sha
assert hashlib.sha256(companion.read_bytes()).hexdigest() == 'b6e2c74fa319226e3ce7e13bda8b7f76a026f3167d3909f106bf7fdb8b03a198'
sys.path.insert(0, str(companion))

# Import only after validating and selecting the immutable packaged source.
Outcome = importlib.import_module('agent_center.api').Outcome
Executor = importlib.import_module('agent_center.execution').Executor
Journal = importlib.import_module('agent_center.journal').Journal
InstanceLock = importlib.import_module('agent_center.state').InstanceLock
engine_module = importlib.import_module('agent_center.update.engine')
Engine, self_check = engine_module.Engine, engine_module.self_check
Remote = importlib.import_module('agent_center.update.remote').Remote


class Service:
    def __init__(self):
        self.running = True

    def active(self):
        return self.running

    def stop(self):
        self.running = False

    def start(self):
        self.running = True


class Client:
    def __init__(self, phase):
        self.operation = {'id': str(uuid.uuid4()), 'kind': 'update', 'phase': phase,
                          'release': {'version': '0.5.4', 'sha256': sha}}
        self.report = None

    def call(self, operation, data):
        if operation == 'operations/claim':
            return Outcome(True, 200, {'operation': self.operation})
        assert operation == 'operations/report'
        self.report = data
        return Outcome(True, 200, {})


def fixture(directory, phase='precheck'):
    directory.mkdir(mode=0o755)
    base = directory / 'base'
    (base / 'runtime').mkdir(parents=True, mode=0o755)
    (base / 'runtime/agent.pyz').write_bytes(b'OLD-AUDIT-RUNTIME')
    (base / 'releases').mkdir(mode=0o755)
    target = base / 'releases' / (sha + '.pyz')
    shutil.copyfile(candidate, target)
    target.chmod(0o644)
    state = directory / 'state'
    state.mkdir(mode=0o700)
    (state / 'preserve').write_bytes(b'AUDIT-STATE')
    engine = Engine(base, state, Service(), checker=lambda path: {})
    client = Client(phase)
    remote = Remote(engine, client, [{'version': '0.5.4', 'sha256': sha,
                                    'device_operations_frozen': True}], directory / 'control')
    return engine, client, remote


proof = {}
with tempfile.TemporaryDirectory(prefix='agent-source-audit-') as temporary:
    workspace = Path(temporary)
    workspace.chmod(0o755)

    engine, client, remote = fixture(workspace / 'happy')
    engine.checker = self_check
    assert remote.run() and client.report['status'] == 'PLANNED'
    assert (engine.base / 'runtime/agent.pyz').read_bytes() == b'OLD-AUDIT-RUNTIME'
    proof['actual_companion_precheck'] = {'status': client.report['status'], 'runtime_preserved': True}

    engine, client, remote = fixture(workspace / 'denied')
    with patch('agent_center.update.remote.self_check', side_effect=PermissionError('AUDIT_PRIVATE_SENTINEL')):
        result = remote.run()
    assert client.report['error'] == 'precheck_failed' and result is True
    assert 'AUDIT_PRIVATE_SENTINEL' not in json.dumps(client.report)
    proof['A02_failure_masked_and_exit_success'] = {
        'operation_status': client.report['status'], 'error': client.report['error'],
        'run_return': result, 'main_exit_implied': 0, 'exception_details_discarded': True,
    }

    engine, client, remote = fixture(workspace / 'postcheck-failed', 'apply')
    remote.postcheck = lambda: False
    remote.run()
    assert client.report['error'] == 'postcheck_failed'
    assert hashlib.sha256((engine.base / 'runtime/agent.pyz').read_bytes()).hexdigest() == sha
    assert (engine.base / '.previous/agent.pyz').read_bytes() == b'OLD-AUDIT-RUNTIME'
    assert (engine.state / 'preserve').read_bytes() == b'AUDIT-STATE'
    proof['A03_no_rollback_on_failed_postcheck'] = {
        'operation_status': client.report['status'], 'new_runtime_still_installed': True,
        'new_service_still_active': engine.service.active(), 'previous_runtime_preserved': True,
        'backup_exists': (remote.control / 'backups' / client.operation['id']).is_dir(),
        'backup_reference_in_failed_report': 'evidence' in client.report,
    }

    engine, client, remote = fixture(workspace / 'wrong-version')
    (engine.state / 'status.json').write_text(json.dumps({
        'version': '0.5.3', 'state': 'ONLINE', 'heartbeat_successes': 1, 'poll_successes': 1,
    }))
    with patch('agent_center.update.remote.time.time', return_value=0):
        accepted = remote._postcheck()
    assert accepted is True
    proof['A04_postcheck_ignores_expected_version'] = {'reported_version': '0.5.3', 'desired_version': '0.5.4', 'accepted': accepted}

    engine, client, remote = fixture(workspace / 'busy-lock')
    lock = InstanceLock(engine.state / 'instance.lock')
    try:
        try:
            engine.apply(candidate, sha)
        except Exception as error:
            failure = type(error).__name__
        else:
            raise AssertionError('busy lock unexpectedly accepted')
        assert not engine.service.active()
        assert (engine.base / 'runtime/agent.pyz').read_bytes() == b'OLD-AUDIT-RUNTIME'
        proof['A05_direct_apply_stops_service_before_lock_failure'] = {'exception': failure, 'service_left_stopped': True, 'runtime_preserved': True}
    finally:
        lock.close()

    directory = workspace / 'executor'
    directory.mkdir(mode=0o700)
    journal = Journal(directory / 'journal.sqlite3')
    execution = str(uuid.uuid4())
    task = {'task_id': str(uuid.uuid4()), 'execution_id': execution, 'generation': 1,
            'lease_token': 'a' * 64, 'capability': 'agent_info', 'input': {}, 'timeout': 20}
    journal.reserve(execution, task['task_id'])
    clock = SimpleNamespace(now=0.0)
    killed = []
    child = SimpleNamespace(stdin=io.BytesIO(), stdout=io.BytesIO(), returncode=None)
    child.poll = lambda: child.returncode

    class Tick:
        def is_set(self):
            return False

        def wait(self, seconds):
            clock.now += 1

    class ExecutionClient:
        def call(self, operation, data):
            if operation == 'renew':
                clock.now += 25
            return Outcome(True, 200, {'status': 'ok'})

    executor = Executor(SimpleNamespace(state_dir=directory, config_path='/unused', timeout=5), journal, ExecutionClient(), Tick())
    executor.cancel = Tick()

    def kill(child):
        if child.returncode is None:
            killed.append(clock.now)
        child.returncode = -9

    with patch('agent_center.execution.subprocess.Popen', return_value=child), patch('agent_center.execution.time.monotonic', side_effect=lambda: clock.now), patch.object(executor, 'kill', side_effect=kill):
        executor.run(task)
    assert killed[0] > task['timeout']
    proof['A06_renew_can_exceed_execution_deadline'] = {'timeout_seconds': task['timeout'], 'kill_virtual_second': killed[0], 'real_network_or_process_executed': False}
    pending_execution = str(uuid.uuid4())
    journal.reserve(pending_execution, str(uuid.uuid4()))
    executor.client = SimpleNamespace(call=lambda *args: Outcome(True, 200, {'executions': [None]}))
    try:
        executor.recover()
    except AttributeError:
        proof['A07_malformed_reconcile_entry_crashes_recovery'] = {'exception': 'AttributeError', 'reexecuted': False}
    else:
        raise AssertionError('malformed reconcile was not reproduced')
    journal.close()

parser = argparse.ArgumentParser()
parser.add_argument('--output', type=Path)
args = parser.parse_args()
encoded = json.dumps(proof, indent=2) + '\n'
if args.output:
    args.output.write_text(encoded)
print(encoded)
