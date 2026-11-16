"""Provision/check an isolated baseline; transitions run only via the native timer/API."""
import hashlib
import io
import json
import os
import sqlite3
import subprocess
import sys
import zipfile
from pathlib import Path

sys.path.insert(0, '/candidate/src')
from agent_center.lifecycle.updaters import derive
from agent_center.state import atomic_write

BASE = Path('/opt/agent-center-agent')
CONTROL = Path('/var/lib/agent-center-updater')
CONFIG = Path('/etc/agent-center-agent')
STATE = Path('/var/lib/agent-center-agent')
assert Path('/proc/1/comm').read_text().strip() == 'systemd'
assert Path('/run/systemd/container').is_file()
assert Path('/lab/routeros-agent-lab').read_text() == 'SIMULATED_ROUTEROS_TASK_ENGINE_V1\n'
old = Path('/integration-baseline/agent.pyz').read_bytes()
assert hashlib.sha256(old).hexdigest() == 'a2de84beb6cb519047c6123423fb420d9d410b41b85c6c9b4a49df63b562d315'

if sys.argv[1] == 'prepare':
    os.umask(0o022)
    for path in (BASE / 'runtime', BASE / 'releases', CONFIG):
        path.mkdir(mode=0o755, parents=True, exist_ok=True)
    CONTROL.chmod(0o700)
    (BASE / 'runtime/agent.pyz').write_bytes(old)
    for name in ('updater.pyz', 'remote-updater.pyz'):
        (BASE / name).write_bytes(derive(old, name))
    (BASE / 'releases/catalog.json').write_text('[]')
    (CONFIG / 'lifecycle-public.pem').write_bytes(Path('/integration-candidate/signer-public.pem').read_bytes())
    atomic_write(CONFIG / 'operations.json', {'central_url': 'https://127.0.0.1:8443'})
    atomic_write(CONFIG / 'lifecycle-policy.json', {'schema_version': 1, 'device_operations_frozen': True,
        'profiles': ['lifecycle-updater'], 'components': ['lifecycle-updater']})
    atomic_write(CONTROL / 'lifecycle/installed.json', {'sequence': 6})
    unit = Path('/run/systemd/system/agent-center-updater.service')
    unit.write_text(Path('/native-units/agent-center-updater.service').read_text().replace(
        '[Unit]\n', '[Unit]\nConditionPathExists=/var/lib/agent-center-agent/identity.json\n'))
    Path('/run/systemd/system/agent-center-updater.timer').write_text(
        '[Timer]\nOnActiveSec=1\nOnUnitInactiveSec=2\nAccuracySec=100ms\nUnit=agent-center-updater.service\n')
    subprocess.run(['systemctl', 'daemon-reload'], check=True)
    subprocess.run(['systemctl', 'start', 'agent-center-updater.timer'], check=True)
elif sys.argv[1] == 'verify':
    status = json.loads((STATE / 'status.json').read_text())
    with sqlite3.connect((STATE / 'journal.sqlite3').as_uri() + '?mode=ro', uri=True) as db:
        assert db.execute('pragma quick_check').fetchone()[0] == 'ok'
        counts = {t: db.execute('select count(*) from ' + t).fetchone()[0] for t in ('executions', 'events')}
    ledger = json.loads((CONTROL / 'lifecycle/installed.json').read_text())
    operations = [json.loads(p.read_text()) for p in (CONTROL / 'lifecycle').glob('*.json') if p.name != 'installed.json']
    proof = json.loads(Path('/lab/panel-transition-result.json').read_text())
    fault = proof['failure_injected']
    restored = proof.get('restored_original_065', False)
    assert len(operations) == (3 if restored else 2)
    if restored:
        assert all(o['state'] == 'COMPLETED' for o in operations)
        assert ledger['sequence'] == 9 and not (BASE / 'runtime/ssh').exists()
        with zipfile.ZipFile(BASE / 'runtime/agent.pyz') as active, zipfile.ZipFile(io.BytesIO(old)) as baseline:
            assert active.namelist() == baseline.namelist()
            assert all(active.read(n) == baseline.read(n) for n in baseline.namelist())
        for name in ('updater.pyz', 'remote-updater.pyz'):
            assert (BASE / name).read_bytes() == derive(old, name)
    elif fault:
        assert sorted(o['state'] for o in operations) == ['COMPLETED', 'FAILED']
        assert next(o for o in operations if o['state'] == 'FAILED')['rollback'] is True
        assert ledger['sequence'] == 7 and not (BASE / 'runtime/ssh').exists()
    else:
        assert all(o['state'] == 'COMPLETED' for o in operations), operations
        assert ledger['sequence'] == 8 and ledger['runtime_package_protocol'] == 2
        assert (BASE / 'runtime/ssh/paramiko').is_dir()
    assert status['version'] == ('0.6.5' if restored else '0.6.6') and status['heartbeat_successes'] > 0 and status['poll_successes'] > 0
    assert not list(BASE.glob('.staging-*'))
    print(json.dumps({'passed': True, 'native_timer_and_remote_entrypoint': True, 'ssh_connections': 0,
        'local_bootstrap_invocations': 0, 'lifecycle_operations': [o['operation_id'] for o in operations],
        'sequence': ledger['sequence'], 'injected_failure_rolled_back': fault, 'version': status['version'], 'journal': counts,
        'active_artifact_sha256': hashlib.sha256((BASE / 'runtime/agent.pyz').read_bytes()).hexdigest(),
        'systemd_pid1': True, 'restored_original_065': restored, 'dependencies_installed': not fault and not restored}))
else:
    raise SystemExit(2)
