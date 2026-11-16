"""Disposable Debian 13/systemd proof using the existing Manager/RuntimeAdapter/Engine."""
import base64
import hashlib
import json
import os
import subprocess
import sys
import uuid
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

sys.path.insert(0, '/tmp/candidate-src')
from agent_center.journal import Journal
from agent_center.lifecycle.manager import Manager
from agent_center.lifecycle.releases import ReleaseError, verify
from agent_center.lifecycle.runtime import RuntimeAdapter
from agent_center.lifecycle.ssh_runtime import materialize
from agent_center.lifecycle.updaters import Updaters
from agent_center.state import atomic_write
from agent_center.update.engine import Engine, Service, self_check
from agent_center.update.remote import Remote

assert Path('/proc/1/comm').read_text().strip() == 'systemd'
assert Path('/run/systemd/container').is_file()
os.umask(0o022)
root = Path('/run/routeros066-lifecycle')
root.mkdir(mode=0o755, exist_ok=False)
base, state, config, control = (root / n for n in ('base', 'state', 'config', 'control'))
for path in (base / 'runtime', state, config, control):
    path.mkdir(parents=True)
bundle = Path('/tmp/bundle')
old = Path('/tmp/agent065.pyz').read_bytes()
(base / 'runtime/agent.pyz').write_bytes(old)
for name in ('updater.pyz', 'remote-updater.pyz'):
    (base / name).write_bytes(old)
atomic_write(config / 'lifecycle-policy.json', {'schema_version': 1,
    'device_operations_frozen': True, 'components': ['lifecycle-updater'],
    'profiles': ['lifecycle-updater']})
atomic_write(state / 'identity.json', {'test_identity': str(uuid.uuid4())})
journal = Journal(state / 'journal.sqlite3')
journal.reserve('preserved-execution', 'preserved-task')
journal.close()
protected = {p.name: hashlib.sha256(p.read_bytes()).hexdigest() for p in state.iterdir()}
unit = Path('/run/systemd/system/agent-center-agent.service')
unit.write_text('[Service]\nType=oneshot\nRemainAfterExit=yes\nUser=65534\nGroup=65534\n'
                'NoNewPrivileges=yes\nCapabilityBoundingSet=\nAmbientCapabilities=\nPrivateDevices=yes\n'
                f'ExecStart=/usr/bin/python3 {base}/runtime/agent.pyz --self-check\n')
subprocess.run(['systemctl', 'daemon-reload'], check=True)
service = Service()
service.start()
assert self_check(base / 'runtime/agent.pyz')['version'] == '0.6.5'
engine = Engine(base, state)
# Match the real companion's restrictive umask throughout staging and apply.
os.umask(0o077)
operation = {'kind': 'update', 'phase': 'apply', 'from_version': '0.6.5',
             'release': {'device_operations_frozen': True}}
payload = (bundle / 'manifest.json').read_bytes()
signature = (bundle / 'signature.base64').read_text().strip()
public = (bundle / 'signer-public.pem').read_bytes()
artifact = (bundle / 'agent.pyz').read_bytes()
# Prove the transition starts with 0.6.5's existing signed bootstrap, before
# loading the new Engine in the companion. No parallel update mechanism.
bootstrap_code = '''
import sys
from pathlib import Path
from agent_center.lifecycle.manager import Manager
from agent_center.lifecycle.updater_bootstrap import BootstrapAdapter
from agent_center.lifecycle.updaters import Updaters
root = Path('/run/routeros066-lifecycle')
bundle = Path('/tmp/bundle')
control = root / 'legacy-bootstrap'
adapter = BootstrapAdapter(Updaters(root / 'base', root / 'config'), control)
result = Manager(control, adapter, (bundle / 'signer-public.pem').read_bytes(), 'debian13').apply(
    sys.argv[1], (bundle / 'manifest.json').read_bytes(),
    (bundle / 'signature.base64').read_text().strip(), (bundle / 'agent.pyz').read_bytes())
assert result['state'] == 'COMPLETED', result
'''
bootstrap_id = str(uuid.uuid4())
subprocess.run(['/usr/bin/python3', '-c', bootstrap_code, bootstrap_id], check=True,
               env={'PATH': '/usr/bin:/bin', 'PYTHONPATH': '/tmp/base-src'}, timeout=30)
assert (base / 'runtime/agent.pyz').read_bytes() == old
releases = base / 'releases'
releases.mkdir()
(releases / 'catalog.json').write_text('[]')
(config / 'lifecycle-public.pem').write_bytes(public)
stager = Remote(engine, None, [], control, config)
signed_operation = {'id': str(uuid.uuid4()), 'kind': 'update', 'phase': 'precheck',
                    'release': {'version': '0.6.6', 'sha256': hashlib.sha256(artifact).hexdigest(),
                                'device_operations_frozen': True,
                                'lifecycle': {'manifest': base64.b64encode(payload).decode(),
                                              'signature': signature}}}
with patch('agent_center.update.remote.CONFIG', config / 'config.json'), patch(
    'agent_center.update.remote.download_operation', return_value=artifact
):
    assert stager._stage_release(signed_operation)['version'] == '0.6.6'
    signed_operation['phase'] = 'apply'
    assert stager._candidate(signed_operation)[0].read_bytes() == artifact
for mutation in ('artifact', 'signature', 'replay', 'platform'):
    try:
        verify(payload, 'A' * 88 if mutation == 'signature' else signature, public,
               artifact + b'x' if mutation == 'artifact' else artifact,
               minimum_sequence=2 if mutation == 'replay' else 1,
               platform='other' if mutation == 'platform' else 'debian13')
        raise AssertionError(mutation + ' accepted')
    except ReleaseError:
        pass

def make_adapter(postcheck):
    remote = Remote(engine, None, [], control, config, postcheck=postcheck)
    remote.component_system = SimpleNamespace(operational=False)
    return RuntimeAdapter(remote, bundle / 'agent.pyz', operation)


def restored():
    assert (base / 'runtime/agent.pyz').read_bytes() == old
    assert not (base / 'runtime/ssh').exists()
    assert service.active()
    assert protected == {name: hashlib.sha256((state / name).read_bytes()).hexdigest() for name in protected}


# A partial stage failure after the service stop must preserve the old runtime.
calls = 0


def partial_install(artifact_path, directory):
    global calls
    calls += 1
    if calls == 2:
        (Path(directory) / 'ssh').mkdir()
        (Path(directory) / 'ssh/partial').write_bytes(b'incomplete')
        raise OSError('lab disk failure')
    return materialize(artifact_path, directory)


with patch('agent_center.update.engine.materialize', side_effect=partial_install):
    try:
        engine.apply(bundle / 'agent.pyz', hashlib.sha256(artifact).hexdigest())
        raise AssertionError('installation failure accepted')
    except OSError:
        pass
restored()
assert calls == 2
assert not list(base.glob('.staging-*'))

# Kill the installer after its first durable rename. The existing Manager holds
# INSTALLING; restore through RuntimeAdapter and reconcile without replaying it.
interrupted_id = str(uuid.uuid4())
interrupted_adapter = make_adapter(lambda: True)
interrupted_manager = Manager(root / 'interrupted-ledger', interrupted_adapter, public, 'debian13')
child = os.fork()
if child == 0:
    original_move = Engine._move

    def interrupted_move(self, source, target):
        original_move(self, source, target)
        if source == base / 'runtime' and target == base / '.previous':
            os._exit(77)

    with patch.object(Engine, '_move', interrupted_move):
        interrupted_manager.apply(interrupted_id, payload, signature, artifact)
    os._exit(78)
_, exit_status = os.waitpid(child, 0)
assert os.waitstatus_to_exitcode(exit_status) == 77
assert not (base / 'runtime').exists() and (base / '.previous').exists()
interrupted_adapter.rollback(interrupted_id)
restored()
interrupted_record = json.loads((root / 'interrupted-ledger' / (interrupted_id + '.json')).read_text())
assert interrupted_record['state'] == 'INSTALLING'
assert interrupted_manager.reconcile_restored(interrupted_id, interrupted_record['request_digest'])['recovery_verified']

# Failure after a real systemd start invokes the native Lifecycle rollback.
adapter = make_adapter(lambda: False)
ledger = root / 'failed-ledger'
failed = Manager(ledger, adapter, public, 'debian13').apply(str(uuid.uuid4()), payload, signature, artifact)
# The injected failed postcheck also rejects rollback health. It must retain UNCERTAIN.
assert failed['state'] == 'UNCERTAIN' and failed['rollback'] is False, failed
restored()
adapter.remote.postcheck = lambda: service.active() and self_check(base / 'runtime/agent.pyz')['version'] == '0.6.5'
recovered = Manager(ledger, adapter, public, 'debian13').reconcile_restored(failed['operation_id'], failed['request_digest'])
assert recovered['state'] == 'FAILED' and recovered['recovery_verified'] is True

adapter = make_adapter(lambda: service.active() and self_check(base / 'runtime/agent.pyz')['version'] == '0.6.6')
manager = Manager(root / 'success-ledger', adapter, public, 'debian13')
operation_id = str(uuid.uuid4())
installed = manager.apply(operation_id, payload, signature, artifact)
assert installed['state'] == 'COMPLETED', installed
assert (base / 'runtime/ssh/paramiko').is_dir()
assert not (base / '.previous/ssh').exists()
assert manager.apply(operation_id, payload, signature, artifact) == installed
service.stop()
service.start()
assert self_check(base / 'runtime/agent.pyz')['version'] == '0.6.6'
# The whole runtime directory is the rollback unit; SSH is removed with it.
engine.rollback(False)
# Restore both companion binaries from the signed bootstrap's verified backup.
Updaters(base, config).rollback(root / 'legacy-bootstrap/backups' / bootstrap_id)
assert all((base / name).read_bytes() == old for name in ('updater.pyz', 'remote-updater.pyz'))
restored()
assert (base / '.failed/ssh/paramiko').is_dir()
# Quarantined failed bytes are non-executable and never on the active import path.
assert not list(base.glob('.staging-*'))
result = {'passed': True, 'version': '0.6.6', 'systemd_pid1': True, 'uid': 65534,
          'signature_tamper_platform_replay_rejected': True, 'offline_install': True,
          'global_install': False, 'native_lifecycle': True, 'systemd_restart': True,
          'legacy_065_signed_bootstrap': True, 'companions_restored': True,
          'native_signed_release_staging': True,
          'rollback_065': True, 'ssh_removed_from_active_runtime': True,
          'postcheck_failure_hold_and_reconcile': True, 'identity_journal_preserved': True,
          'interrupted_process_tested': True, 'six_queries_tested_here': False}
Path('/tmp/lifecycle-result.json').write_text(json.dumps(result, indent=2) + '\n')
print(json.dumps(result))
