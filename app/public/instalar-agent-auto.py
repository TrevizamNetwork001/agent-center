#!/usr/bin/env python3
"""Fixed Agent bootstrap tooling. No tenant credential is accepted from the operator."""
import argparse
import contextlib
import fcntl
import hashlib
import json
import os
import pty
import re
import select
import socket
import stat
import subprocess
import sys
import tempfile
import termios
import time
import urllib.error
import urllib.request
import uuid
from pathlib import Path

ORIGIN = 'https://agente.trevizamnetwork.com.br'
MANIFEST = 'eb2d98ab911ebb8a808d3462dce1b30a1d90ef8694c8b48baec1031ea027ed30'
STATE = Path('/var/lib/agent-center-bootstrap')
DNS_RELEASE_SHA = '5adb4d7bc1884ed7fe7d469543bbf1f07f557aa21662b31071eb6aa93eb4ae99'


def dns_release(kit):
    """Return the exact approved runtime; never rebuild code on the target host."""
    baseline = kit / 'agent.pyz'
    if not verified(baseline, DNS_RELEASE_SHA):
        raise ValueError('baseline_integrity_failed')
    return baseline.read_bytes()


def trusted_root_path(path, boundary=Path('/')):
    for part in (path, *path.parents):
        info = part.lstat()
        if part.is_symlink() or info.st_uid != 0 or info.st_mode & 0o022:
            raise ValueError('untrusted_updater_path')
        if part == boundary:
            return
    raise ValueError('invalid_path_boundary')


def atomic_file(path, data, mode=0o644):
    fd, temporary = tempfile.mkstemp(prefix='.repair-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchmod(stream.fileno(), mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def updater_command(arguments):
    return subprocess.run(['/usr/bin/systemctl', *arguments], check=False,
                          stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=30)


@contextlib.contextmanager
def maintenance_lock(control):
    fd = os.open(control / 'helper.lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(fd)
        if info.st_uid != 0 or not stat.S_ISREG(info.st_mode) or info.st_mode & 0o077:
            raise ValueError('unsafe_updater_lock')
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield
    finally:
        os.close(fd)


def repair_updater(kit, root=Path('/'), command=updater_command):
    control = root / 'var/lib/agent-center-updater'
    trusted_root_path(control, root)
    with maintenance_lock(control):
        return repair_locked(kit, root, command)


def repair_locked(kit, root, command):
    """Stage one pinned release and enable the installed companion; never enroll."""
    base = root / 'opt/agent-center-agent'
    config = root / 'etc/agent-center-agent'
    state = root / 'var/lib/agent-center-agent'
    control = root / 'var/lib/agent-center-updater'
    catalog = base / 'releases/catalog.json'
    operations = config / 'operations.json'
    for path in (base, base / 'releases', config, control, catalog, operations):
        trusted_root_path(path, root)
    for name, path in (
        ('remote-updater.pyz', base / 'remote-updater.pyz'),
        ('updater.pyz', base / 'updater.pyz'),
        ('agent-center-updater.service', root / 'etc/systemd/system/agent-center-updater.service'),
        ('agent-center-updater.timer', root / 'etc/systemd/system/agent-center-updater.timer'),
    ):
        trusted_root_path(path, root)
        expected = json.loads((kit / 'manifest.json').read_text())[name]
        legacy = {'remote-updater.pyz': ('516c609f26f2e40bd23f6f7bf12567161623d39cc30f3fc79e44a25d3e516b3e','50dad8e874230b1bb7bf24d58c521a6bafe308a88a40e2ad51d720503f373c77','9b2fd16c5f24be7e05f81e926905e4ff9d4d5e037e8876dbc2ebda95a0383852','de43790fda66a7397b2c9d6c9fbb5534938aeec070ce756dd03e86147b35e100','754a5a5cb3b0fe127f1b3274b918bd55512fc62c9fdfbf4476b2528e3a35a0f3','b6e2c74fa319226e3ce7e13bda8b7f76a026f3167d3909f106bf7fdb8b03a198', 'c49019450609ad1ee4aaed5e8c59f33ee981ec993fff029e1d320f8a55761160'), 'updater.pyz': ('caeb4f1e63670273cfdeb6b45381b2211ff5973f56b3176a28cfc0dbd5a1ffb0','6a23257fc2700b7bb0ba21858aaf880ef6627ab6e2b16422071285c2f62c4b26','3d3f109fd2ee2ae64113632ab1376a6e134bae48aa70210319ea770218121d2d','53ebb9cf0fa93e210e6acf82e80e5165db6b61a67b6c64cc5d6220e207994d2f','a6f4846fdfeab99e53d76cb4911c7db0ced2bf796ede6942f42e0d1486bb3589','60439eb3837ce458b159a2e0b620c2e1aac066dc2a4bb51f86c54606810f005e', 'cf799651123cb8d611b74ee398286abef9b74f297d2a5e07f53896df11071407'), 'agent-center-updater.service': ('d593fecf25fd966f66fda435c87d928cb0a9e2a65b75d17484480d1ebaf140d6',)}.get(name, ())
        if not verified(path, expected) and not any(verified(path, previous) for previous in legacy):
            raise ValueError('installed_updater_requires_review')
    if json.loads(operations.read_text()).get('central_url') != ORIGIN:
        raise ValueError('operations_origin_mismatch')
    import pwd
    account = pwd.getpwnam('agent-center-agent')
    trusted_root_path(state.parent, root)
    state_info = state.lstat()
    if state.is_symlink() or state_info.st_uid != account.pw_uid or stat.S_IMODE(state_info.st_mode) != 0o700:
        raise ValueError('existing_state_requires_review')
    unchanged = {}
    for path in (config / 'config.json', state / 'identity.json'):
        info = path.lstat()
        if path.is_symlink() or not stat.S_ISREG(info.st_mode) or info.st_uid != account.pw_uid or stat.S_IMODE(info.st_mode) != 0o600:
            raise ValueError('existing_identity_or_config_requires_review')
        unchanged[path] = hashlib.sha256(path.read_bytes()).hexdigest()
    current = json.loads((config / 'config.json').read_text())
    if current.get('central_url') != ORIGIN or current.get('device_operations_frozen') is not True:
        raise ValueError('existing_configuration_requires_review')
    runtime = base / 'runtime/agent.pyz'
    trusted_root_path(runtime, root)
    runtime_sha = hashlib.sha256(runtime.read_bytes()).hexdigest()
    if runtime_sha not in ('7f3923e2d3f62fb3593b5a557d3d40540f23c8a6e0fd44a3cbcf86d29788f794', '95d8e2d4531ed642030947717b434f454343330f016707f79d4c42b885d243e3', 'e0bf349dff2f87c4476a32300e368e57958306d0fe308119bd72b0b8223bea02', 'ee00aed3e687b7429decf2c056754119ce485e01b3a2f980eea1615d893750d2', '8fbd94ded4efbebfabe078a982435c7f684ad24c542f45d3c25166fe72bc9cd7', '64c1cec31db9227bacf7103ca2ba5ea5036e169b2c1d5c009e0176861754da5c', 'bb8c0274b9fc2121c283136b2ad66a9b67037c0c7d32e44f79f44b5d33f5cb19', '769980dd97751c44cf486e0f21fc399cf4448142c18f0b3f08966d45521e12a1', DNS_RELEASE_SHA):
        raise ValueError('runtime_requires_review')
    package = dns_release(kit)
    previous = catalog.read_bytes()
    entries = json.loads(previous)
    if not isinstance(entries, list):
        raise ValueError('invalid_local_catalog')
    release = dict(version='0.6.1', sha256=DNS_RELEASE_SHA, device_operations_frozen=True)
    existing = [entry for entry in entries if entry.get('sha256') == DNS_RELEASE_SHA]
    if existing and any(entry.get('version') != '0.6.1' or entry.get('device_operations_frozen') is not True for entry in existing):
        raise ValueError('local_release_conflict')
    candidate = base / 'releases' / (DNS_RELEASE_SHA + '.pyz')
    existed = candidate.exists() or candidate.is_symlink()
    if existed and not verified(candidate, DNS_RELEASE_SHA):
        raise ValueError('staged_release_conflict')
    # Capture timer state before changes; disabling a preexisting timer is forbidden.
    enabled = command(['is-enabled', 'agent-center-updater.timer']).stdout.decode().strip()
    if enabled not in ('enabled', 'enabled-runtime', 'disabled'):
        raise ValueError('updater_timer_requires_review')
    active = command(['is-active', 'agent-center-updater.timer']).returncode == 0
    if command(['is-active', 'agent-center-updater.service']).returncode == 0:
        raise ValueError('updater_busy')
    repairs = control / 'repairs'
    if not repairs.exists():
        repairs.mkdir(mode=0o700)
    trusted_root_path(repairs, root)
    snapshot = repairs / str(uuid.uuid4())
    snapshot.mkdir(mode=0o700)
    atomic_file(snapshot / 'catalog.before.json', previous, 0o600)
    atomic_file(snapshot / 'rollback.json', json.dumps({'timer_enabled': enabled, 'timer_active': active, 'candidate_existed': existed, 'candidate_sha256': DNS_RELEASE_SHA}).encode(), 0o600)
    component_paths = {name: base / name for name in ('updater.pyz', 'remote-updater.pyz')}
    component_paths['agent-center-updater.service'] = root / 'etc/systemd/system/agent-center-updater.service'
    components = {name: path.read_bytes() for name, path in component_paths.items()}
    for name, data in components.items():
        atomic_file(snapshot / (name + '.before'), data, 0o600)
    try:
        if command(['stop', 'agent-center-updater.timer']).returncode != 0:
            raise ValueError('updater_pause_failed')
        for name in components:
            atomic_file(component_paths[name], (kit / name).read_bytes())
        if not existed:
            atomic_file(candidate, package)
        if not existing:
            atomic_file(catalog, (json.dumps([release, *entries], indent=2) + '\n').encode())
        if command(['daemon-reload']).returncode != 0:
            raise ValueError('updater_reload_failed')
        activation = ['enable', '--now', 'agent-center-updater.timer'] if enabled == 'disabled' else ['start', 'agent-center-updater.timer']
        if command(activation).returncode != 0:
            raise ValueError('updater_activation_failed')
        if command(['is-active', 'agent-center-updater.timer']).returncode != 0:
            raise ValueError('updater_timer_inactive')
        if any(hashlib.sha256(path.read_bytes()).hexdigest() != digest for path, digest in unchanged.items()):
            raise ValueError('identity_or_configuration_changed')
    except Exception:
        if not active:
            command(['stop', 'agent-center-updater.timer'])
        if enabled not in ('enabled', 'enabled-runtime', 'linked', 'linked-runtime'):
            command(['disable', 'agent-center-updater.timer'])
        atomic_file(catalog, previous)
        for name, data in components.items():
            atomic_file(component_paths[name], data)
        command(['daemon-reload'])
        if active:
            command(['start', 'agent-center-updater.timer'])
        # Keep the verified candidate: an in-flight precheck may still hold it.
        raise
    print('Reparo concluído. Identidade e configuração preservadas.', flush=True)
    print('No painel, clique Atualizar Agent e confirme. Acompanhe a instalação; ao concluir, o painel volta à página do Agent.', flush=True)
    print('Backup do reparo: ' + str(snapshot), flush=True)
    return 0


def call(endpoint, payload):
    request = urllib.request.Request(ORIGIN + '/api/v1/agent/bootstrap/' + endpoint,
                                    data=json.dumps(payload).encode(),
                                    headers={'Content-Type': 'application/json', 'Accept': 'application/json'}, method='POST')
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.load(response)


def verified(path, expected):
    return (not path.is_symlink() and path.is_file() and path.stat().st_uid == 0
            and not (path.stat().st_mode & 0o022)
            and hashlib.sha256(path.read_bytes()).hexdigest() == expected)


def existing_installation(kit):
    base = Path('/opt/agent-center-agent')
    config = Path('/etc/agent-center-agent/config.json')
    if not base.exists():
        return False
    if Path('/var/lib/agent-center-agent/identity.json').exists() or Path('/var/lib/agent-center-agent/enrollment.json').exists():
        raise ValueError('existing_identity_requires_review')
    manifest = json.loads((kit / 'manifest.json').read_text())
    if not verified(base / 'runtime/agent.pyz', manifest['agent.pyz']) or not verified(base / 'complete-enrollment.sh', manifest['complete-enrollment.sh']):
        raise ValueError('existing_installation_does_not_match_approved_kit')
    data = json.loads(config.read_text())
    if data.get('central_url') != ORIGIN or data.get('device_operations_frozen') is not True:
        raise ValueError('existing_configuration_requires_review')
    return True


def run_installer(command, code):
    """Supply the one-use credential only through a private terminal, never argv/env/log."""
    master, slave = pty.openpty()
    settings = termios.tcgetattr(slave)
    settings[3] &= ~termios.ECHO
    termios.tcsetattr(slave, termios.TCSANOW, settings)
    process = subprocess.Popen(command, stdin=slave, stdout=slave, stderr=slave, start_new_session=True)
    os.close(slave)
    pending = b''
    supplied = False
    deadline = time.monotonic() + 600
    try:
        while True:
            if time.monotonic() >= deadline:
                raise TimeoutError('installer_timeout')
            readable, _, _ = select.select([master], [], [], 1)
            if readable:
                try:
                    chunk = os.read(master, 65536)
                except OSError:
                    break
                if not chunk:
                    break
                pending += chunk
                marker = b'Enrollment code: '
                if not supplied and marker in pending:
                    os.write(master, code.encode() + b'\n')
                    supplied = True
                # Child output is technical; retain only enough bytes to detect a split prompt.
                pending = b'' if supplied else pending[-len(marker):]
            if process.poll() is not None and not readable:
                break
        return process.wait(timeout=10)
    finally:
        os.close(master)
        if process.poll() is None:
            process.terminate()
            try:
                process.wait(timeout=10)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--kit', type=Path, required=True)
    parser.add_argument('--repair-updater', action='store_true')
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise ValueError('root_required')
    os.umask(0o077)
    kit = args.kit.resolve()
    if not verified(kit / 'manifest.json', MANIFEST):
        raise ValueError('manifest_integrity_failed')
    for name, expected in json.loads((kit / 'manifest.json').read_text()).items():
        if Path(name).name != name or not verified(kit / name, expected):
            raise ValueError('artifact_integrity_failed')
    if not verified(kit / 'install-agent.py', 'd6cf9c8b100da109004a66e38817dcaa7bbe8fa084dcdfd5509644450ea7d848'):
        raise ValueError('installer_integrity_failed')
    if args.repair_updater:
        return repair_updater(kit)
    resume = existing_installation(kit)
    command = ['/usr/bin/python3', str(kit / 'install-agent.py'), '--kit', str(kit), '--central-url', ORIGIN, '--manifest-sha256', MANIFEST]
    if not resume:
        subprocess.run(command + ['--check'], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    STATE.mkdir(mode=0o700, exist_ok=True)
    if STATE.is_symlink() or STATE.stat().st_uid != 0 or stat.S_IMODE(STATE.stat().st_mode) != 0o700:
        raise ValueError('bootstrap_directory_requires_review')
    state = STATE / 'request.json'
    if state.exists():
        if state.is_symlink() or state.stat().st_uid != 0 or stat.S_IMODE(state.stat().st_mode) != 0o600:
            raise ValueError('bootstrap_receipt_requires_review')
        pending = json.loads(state.read_text())
    else:
        machine = Path('/etc/machine-id').read_bytes()
        pending = call('request', {'hostname': socket.gethostname(), 'fingerprint': hashlib.sha256(machine).hexdigest()})
        with state.open('x') as file:
            json.dump(pending, file)
    receipt = pending['receipt']
    if not isinstance(receipt, str) or not re.fullmatch(r'[a-f0-9-]{36}\.[a-f0-9]{64}', receipt):
        raise ValueError('invalid_bootstrap_receipt')
    print('Aguardando autorização...', flush=True)
    deadline = time.monotonic() + 1800
    while time.monotonic() < deadline:
        result = call('status', {'receipt': receipt})
        if result.get('state') == 'APPROVED':
            code = result['code']
            if not isinstance(code, str) or not re.fullmatch(r'[a-f0-9-]{36}\.[a-f0-9]{64}', code):
                raise ValueError('invalid_approval')
            if resume:
                command = ['/bin/sh', '/opt/agent-center-agent/complete-enrollment.sh']
            status = run_installer(command, code)
            if status != 0:
                print('Não foi possível concluir o cadastro. Solicite suporte.', flush=True)
                return status
            state.unlink()
            print('Agent cadastrado com sucesso.', flush=True)
            return 0
        time.sleep(10)
    print('Tempo de autorização esgotado. Solicite suporte.', flush=True)
    return 4


if __name__ == '__main__':
    try:
        raise SystemExit(main())
    except urllib.error.HTTPError as error:
        print('Cadastro interrompido pela Central (HTTP %s). Solicite revisao da solicitacao no painel.' % error.code, flush=True)
        raise SystemExit(2)
    except (OSError, ValueError, KeyError, TimeoutError, subprocess.SubprocessError):
        if '--repair-updater' in sys.argv:
            print('Reparo interrompido. Não reinstale nem apague a identidade; solicite revisão da instalação existente.', flush=True)
        else:
            print('Cadastro interrompido. Dados preservados; revise a conectividade e a instalacao.', flush=True)
        raise SystemExit(2)
