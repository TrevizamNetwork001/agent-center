"""Fixed supervisor for one disposable systemd namespace; never an Agent updater."""
import hashlib
import json
import os
import sqlite3
import subprocess
import sys
import time
import uuid
from pathlib import Path

sys.path.insert(0, '/candidate/src')
from agent_center.lifecycle.manager import Manager
from agent_center.lifecycle.runtime import RuntimeAdapter
from agent_center.lifecycle.updaters import Updaters, derive
from agent_center.state import atomic_write, read_private
from agent_center.update.engine import Engine, Service
from agent_center.update.remote import Remote

ROOT = Path('/run/routeros066-upgrade')
BASE, CONFIG, CONTROL = (ROOT / n for n in ('base', 'config', 'control'))
STATE = Path('/lab/agent-private/state')
BUNDLE = Path('/integration-candidate')
OLD = Path('/integration-baseline/agent.pyz')
assert os.geteuid() == 0 and Path('/proc/1/comm').read_text().strip() == 'systemd'
assert Path('/run/systemd/container').is_file()
assert Path('/lab/routeros-agent-lab').read_text() == 'SIMULATED_ROUTEROS_TASK_ENGINE_V1\n'
assert hashlib.sha256(OLD.read_bytes()).hexdigest() == 'a2de84beb6cb519047c6123423fb420d9d410b41b85c6c9b4a49df63b562d315'


def online(version):
    deadline = time.monotonic() + 60
    while time.monotonic() < deadline:
        try:
            fd = os.open(STATE / 'status.json', os.O_RDONLY | os.O_NOFOLLOW)
            with os.fdopen(fd) as stream:
                info = os.fstat(stream.fileno())
                assert info.st_uid == 65534 and not info.st_mode & 0o077 and info.st_size < 1048576
                status = json.loads(stream.read(1048576))
            if status.get('version') == version and status.get('state') == 'ONLINE' and status.get('heartbeat_successes', 0) and status.get('poll_successes', 0):
                return
        except (OSError, ValueError):
            pass
        time.sleep(.2)
    raise RuntimeError('lab_runtime_not_online')


def journal_counts():
    with sqlite3.connect((STATE / 'journal.sqlite3').as_uri() + '?mode=ro', uri=True) as db:
        assert db.execute('pragma quick_check').fetchone()[0] == 'ok'
        return {t: db.execute('select count(*) from ' + t).fetchone()[0] for t in ('executions', 'events')}


if sys.argv[1] == 'prepare':
    os.umask(0o022)
    for directory in (BASE / 'runtime', CONFIG, CONTROL):
        directory.mkdir(mode=0o755, parents=True, exist_ok=False)
    (BASE / 'runtime/agent.pyz').write_bytes(OLD.read_bytes())
    for name in ('updater.pyz', 'remote-updater.pyz'):
        (BASE / name).write_bytes(derive(OLD.read_bytes(), name))
    atomic_write(CONFIG / 'lifecycle-policy.json', {'schema_version': 1,
        'device_operations_frozen': True, 'profiles': ['lifecycle-updater'], 'components': ['lifecycle-updater']})
    raise SystemExit(0)

engine = Engine(BASE, STATE, Service())
os.umask(0o077)
if sys.argv[1] == 'install':
    online('0.6.5')
    identity = hashlib.sha256((STATE / 'identity.json').read_bytes()).hexdigest()
    before = journal_counts()
    # Use the actual 0.6.5 archive's existing bootstrap before the new companion.
    code = '''
import sys
from pathlib import Path
sys.path.insert(0, '/integration-baseline/agent.pyz')
from agent_center.lifecycle.manager import Manager
from agent_center.lifecycle.updater_bootstrap import BootstrapAdapter
from agent_center.lifecycle.updaters import Updaters
r=Path('/run/routeros066-upgrade'); b=Path('/integration-candidate'); c=r/'bootstrap'
a=BootstrapAdapter(Updaters(r/'base',r/'config'),c)
v=Manager(c,a,(b/'signer-public.pem').read_bytes(),'debian13').apply(sys.argv[1],(b/'manifest.json').read_bytes(),(b/'signature.base64').read_text().strip(),(b/'agent.pyz').read_bytes())
assert v['state']=='COMPLETED'
'''
    bootstrap_id = str(uuid.uuid4())
    subprocess.run(['/usr/bin/python3', '-c', code, bootstrap_id], check=True, timeout=30)
    remote = Remote(engine, None, [], CONTROL, CONFIG)
    adapter = RuntimeAdapter(remote, BUNDLE / 'agent.pyz', {'kind': 'update', 'phase': 'apply',
        'from_version': '0.6.5', 'release': {'device_operations_frozen': True}})
    result = Manager(CONTROL / 'lifecycle', adapter, (BUNDLE / 'signer-public.pem').read_bytes(), 'debian13').apply(
        str(uuid.uuid4()), (BUNDLE / 'manifest.json').read_bytes(), (BUNDLE / 'signature.base64').read_text().strip(), (BUNDLE / 'agent.pyz').read_bytes())
    assert result['state'] == 'COMPLETED', result['state']
    online('0.6.6')
    assert all(journal_counts()[key] >= before[key] for key in before)
    atomic_write(ROOT / 'installed-proof.json', {'installed': True, 'postcheck': True,
        'identity_sha256': identity, 'journal_before': before, 'bootstrap_id': bootstrap_id,
        'artifact_sha256': hashlib.sha256((BUNDLE / 'agent.pyz').read_bytes()).hexdigest()})
    atomic_write(Path('/lab/agent-private/lifecycle-ready.json'), {'ready': True})
elif sys.argv[1] == 'rollback':
    proof = read_private(ROOT / 'installed-proof.json')
    before = journal_counts()
    assert before['executions'] > proof['journal_before']['executions']
    assert before['events'] > proof['journal_before']['events']
    engine.rollback(False)
    Updaters(BASE, CONFIG).rollback(ROOT / 'bootstrap/backups' / proof['bootstrap_id'])
    online('0.6.5')
    assert hashlib.sha256((STATE / 'identity.json').read_bytes()).hexdigest() == proof['identity_sha256']
    assert journal_counts() == before
    assert not (BASE / 'runtime/ssh').exists() and not list(BASE.glob('.staging-*'))
    assert all((BASE / name).read_bytes() == derive(OLD.read_bytes(), name) for name in ('updater.pyz', 'remote-updater.pyz'))
    print(json.dumps({'passed': True, 'same_systemd_instance': True, 'update': '0.6.5 -> 0.6.6',
        'actual_runtime_postcheck': True, 'queries_between_update_and_rollback': True,
        'journal_preserved': before, 'identity_preserved': True, 'companions_restored': True,
        'rollback_version': '0.6.5', 'ssh_removed': True, 'artifact_sha256': proof['artifact_sha256']}))
else:
    raise SystemExit(2)
