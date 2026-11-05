import contextlib
import importlib.util
import io
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

path = Path(__file__).resolve().parents[2] / 'app/public/instalar-agent-auto.py'
spec = importlib.util.spec_from_file_location('bootstrap_installer', path)
bootstrap = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bootstrap)


class TerminalHandoffTests(unittest.TestCase):
    def test_hidden_credential_reaches_getpass_without_echo_or_command_argument(self):
        secret = 'fixture-only-' + 'a' * 64
        child = "import getpass; value=getpass.getpass('Enrollment code: '); assert value.startswith('fixture-only-') and len(value)==77; print('ENROLLED')"
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            result = bootstrap.run_installer([sys.executable, '-c', child], secret)
        self.assertEqual(result, 0)
        self.assertEqual('', output.getvalue())
        self.assertNotIn(secret, output.getvalue())

    def test_split_prompt_is_detected_and_failure_status_preserved(self):
        secret = 'fixture-only'
        child = "import sys,time; print('checks passed'); sys.stdout.write('Enrollment'); sys.stdout.flush(); time.sleep(.1); sys.stdout.write(' code: '); sys.stdout.flush(); assert sys.stdin.readline().strip()=='fixture-only'; sys.exit(4)"
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            result = bootstrap.run_installer([sys.executable, '-c', child], secret)
        self.assertEqual(result, 4)
        self.assertNotIn(secret, output.getvalue())


class FreshInstallTests(unittest.TestCase):
    def test_verified_kit_installs_corrected_service_in_empty_lab(self):
        kit = Path(__file__).resolve().parents[2] / 'artifacts/agentkit/0.6.1'
        with tempfile.TemporaryDirectory() as directory:
            result = subprocess.run([sys.executable, str(kit / 'install-agent.py'), '--kit', str(kit), '--central-url', bootstrap.ORIGIN, '--manifest-sha256', bootstrap.MANIFEST, '--lab-root', directory], capture_output=True, text=True, check=True)
            self.assertTrue(json.loads(result.stdout)['installed_layout'])
            service = Path(directory) / 'etc/systemd/system/agent-center-updater.service'
            self.assertEqual(service.read_bytes(), (kit / service.name).read_bytes())
            self.assertIn('AmbientCapabilities=CAP_SETUID', service.read_text())
            self.assertIn('NoNewPrivileges=true', service.read_text())
            self.assertIn('PrivateDevices=true', service.read_text())
            self.assertFalse((Path(directory) / 'var/lib/agent-center-agent/identity.json').exists())


class UpdaterRepairTests(unittest.TestCase):
    def test_repair_dispatch_does_not_request_enrollment_or_bootstrap_credentials(self):
        kit = Path(__file__).resolve().parents[2] / 'artifacts/agentkit/0.6.1'
        with patch.object(sys, 'argv', ['installer', '--kit', str(kit), '--repair-updater']), patch.object(bootstrap, 'repair_updater', return_value=0) as repair, patch.object(bootstrap, 'call', side_effect=AssertionError('bootstrap forbidden')), patch.object(bootstrap, 'run_installer', side_effect=AssertionError('enrollment forbidden')):
            self.assertEqual(bootstrap.main(), 0)
            repair.assert_called_once_with(kit)

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.kit = Path(__file__).resolve().parents[2] / 'artifacts/agentkit/0.6.1'
        baseline = Path(__file__).resolve().parents[2] / 'artifacts/agentkit/0.5.3'
        self.base = self.root / 'opt/agent-center-agent'
        self.config = self.root / 'etc/agent-center-agent'
        self.state = self.root / 'var/lib/agent-center-agent'
        self.control = self.root / 'var/lib/agent-center-updater'
        self.units = self.root / 'etc/systemd/system'
        for directory in (self.base / 'runtime', self.base / 'releases', self.config, self.state, self.control, self.units):
            directory.mkdir(parents=True, exist_ok=True)
        self.state.chmod(0o700)
        shutil.copyfile(baseline / 'agent.pyz', self.base / 'runtime/agent.pyz')
        shutil.copyfile(baseline / 'remote-updater.pyz', self.base / 'remote-updater.pyz')
        shutil.copyfile(baseline / 'updater.pyz', self.base / 'updater.pyz')
        shutil.copyfile(baseline / 'catalog.json', self.base / 'releases/catalog.json')
        for name in ('agent-center-updater.service', 'agent-center-updater.timer'):
            shutil.copyfile(self.kit / name, self.units / name)
        (self.config / 'operations.json').write_text(json.dumps({'central_url': bootstrap.ORIGIN}))
        (self.config / 'config.json').write_text(json.dumps({'central_url': bootstrap.ORIGIN, 'device_operations_frozen': True, 'allowed_dns_names': ['google.com'], 'allowed_networks': ['8.8.8.8/32']}))
        (self.config / 'config.json').chmod(0o600)
        (self.state / 'identity.json').write_text('{"token":"private-fixture-identity"}')
        (self.state / 'identity.json').chmod(0o600)
        self.commands = []
        self.active = False
        self.fail_activation = False
        self.fail_reload = False
        self.account = patch('pwd.getpwnam', return_value=SimpleNamespace(pw_uid=0))
        self.account.start()
        self.addCleanup(self.account.stop)

    def systemctl(self, arguments):
        self.commands.append(arguments)
        if arguments == ['daemon-reload'] and self.fail_reload:
            return SimpleNamespace(returncode=1, stdout=b'')
        if arguments == ['is-active', 'agent-center-updater.service']:
            return SimpleNamespace(returncode=3, stdout=b'')
        if arguments[0] == 'is-enabled':
            return SimpleNamespace(returncode=0 if self.active else 1, stdout=b'enabled\n' if self.active else b'disabled\n')
        if arguments[0] == 'is-active':
            return SimpleNamespace(returncode=0 if self.active else 3, stdout=b'')
        if arguments[0] == 'enable':
            if self.fail_activation:
                return SimpleNamespace(returncode=1, stdout=b'')
            self.active = True
        if arguments[0] == 'start':
            self.active = True
        if arguments[0] == 'stop':
            self.active = False
        return SimpleNamespace(returncode=0, stdout=b'')

    def test_repair_stages_pinned_release_without_reenrollment_or_policy_changes_and_is_repeatable(self):
        preserved = {path: path.read_bytes() for path in (self.config / 'config.json', self.state / 'identity.json', self.base / 'runtime/agent.pyz')}
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            self.assertEqual(bootstrap.repair_updater(self.kit, self.root, self.systemctl), 0)
            self.assertEqual(bootstrap.repair_updater(self.kit, self.root, self.systemctl), 0)
        for path, original in preserved.items():
            self.assertEqual(path.read_bytes(), original)
        self.assertNotIn('private-fixture-identity', output.getvalue())
        candidate = self.base / 'releases' / (bootstrap.DNS_RELEASE_SHA + '.pyz')
        self.assertEqual(hashlib.sha256(candidate.read_bytes()).hexdigest(), bootstrap.DNS_RELEASE_SHA)
        catalog = json.loads((self.base / 'releases/catalog.json').read_text())
        self.assertEqual(sum(row['sha256'] == bootstrap.DNS_RELEASE_SHA for row in catalog), 1)
        self.assertTrue(self.active)
        self.assertTrue(all(command == ['daemon-reload'] or command[-1] in ('agent-center-updater.timer', 'agent-center-updater.service') for command in self.commands))
        self.assertEqual((self.base / 'remote-updater.pyz').read_bytes(), (self.kit / 'remote-updater.pyz').read_bytes())
        self.assertEqual((self.base / 'updater.pyz').read_bytes(), (self.kit / 'updater.pyz').read_bytes())
        for snapshot in (self.control / 'repairs').iterdir():
            self.assertEqual((snapshot / 'rollback.json').stat().st_mode & 0o777, 0o600)

    def test_failed_activation_restores_catalog_and_never_changes_identity(self):
        catalog = self.base / 'releases/catalog.json'
        before = catalog.read_bytes()
        self.fail_activation = True
        with self.assertRaisesRegex(ValueError, 'updater_activation_failed'):
            bootstrap.repair_updater(self.kit, self.root, self.systemctl)
        self.assertEqual(catalog.read_bytes(), before)
        self.assertIn(['stop', 'agent-center-updater.timer'], self.commands)
        self.assertIn(['disable', 'agent-center-updater.timer'], self.commands)
        self.assertEqual((self.state / 'identity.json').read_text(), '{"token":"private-fixture-identity"}')

    def test_legacy_service_is_repaired_and_reloaded_before_timer_activation(self):
        service = self.units / 'agent-center-updater.service'
        legacy = Path(__file__).resolve().parents[2] / 'artifacts/agentkit/0.5.3/agent-center-updater.service'
        shutil.copyfile(legacy, service)
        before = service.read_bytes()
        with contextlib.redirect_stdout(io.StringIO()):
            bootstrap.repair_updater(self.kit, self.root, self.systemctl)
        self.assertIn(b'AmbientCapabilities=CAP_SETUID', service.read_bytes())
        self.assertLess(self.commands.index(['daemon-reload']), self.commands.index(['enable', '--now', 'agent-center-updater.timer']))
        snapshot = next((self.control / 'repairs').iterdir())
        self.assertEqual((snapshot / 'agent-center-updater.service.before').read_bytes(), before)

    def test_reload_failure_restores_legacy_service_without_activating_timer(self):
        service = self.units / 'agent-center-updater.service'
        legacy = Path(__file__).resolve().parents[2] / 'artifacts/agentkit/0.5.3/agent-center-updater.service'
        shutil.copyfile(legacy, service)
        before = service.read_bytes()
        self.fail_reload = True
        with self.assertRaisesRegex(ValueError, 'updater_reload_failed'):
            bootstrap.repair_updater(self.kit, self.root, self.systemctl)
        self.assertEqual(service.read_bytes(), before)
        self.assertNotIn(['enable', '--now', 'agent-center-updater.timer'], self.commands)

    def test_unreviewed_service_is_rejected(self):
        (self.units / 'agent-center-updater.service').write_text('[Service]\nExecStart=/unreviewed\n')
        with self.assertRaisesRegex(ValueError, 'installed_updater_requires_review'):
            bootstrap.repair_updater(self.kit, self.root, self.systemctl)
        self.assertEqual(self.commands, [])

    def test_upgrade_from_verified_055_companion_preserves_running_runtime(self):
        previous = Path(__file__).resolve().parents[2] / 'artifacts/agentkit/0.5.5'
        for name in ('updater.pyz', 'remote-updater.pyz'):
            shutil.copyfile(previous / name, self.base / name)
        runtime = (self.base / 'runtime/agent.pyz').read_bytes()
        with contextlib.redirect_stdout(io.StringIO()):
            bootstrap.repair_updater(self.kit, self.root, self.systemctl)
        self.assertEqual((self.base / 'runtime/agent.pyz').read_bytes(), runtime)
        for name in ('updater.pyz', 'remote-updater.pyz'):
            self.assertEqual((self.base / name).read_bytes(), (self.kit / name).read_bytes())

    def test_prepare_060_from_056_preserves_identity_runtime_and_policy(self):
        previous = Path(__file__).resolve().parents[2] / 'artifacts/agentkit/0.5.6'
        for name in ('updater.pyz', 'remote-updater.pyz'):
            shutil.copyfile(previous / name, self.base / name)
        shutil.copyfile(previous / 'agent.pyz', self.base / 'runtime/agent.pyz')
        preserved = {path: path.read_bytes() for path in (self.base / 'runtime/agent.pyz', self.config / 'config.json', self.state / 'identity.json')}
        with contextlib.redirect_stdout(io.StringIO()):
            bootstrap.repair_updater(self.kit, self.root, self.systemctl)
        for path, data in preserved.items():
            self.assertEqual(path.read_bytes(), data)
        candidate = self.base / 'releases' / (bootstrap.DNS_RELEASE_SHA + '.pyz')
        self.assertEqual(candidate.read_bytes(), (self.kit / 'agent.pyz').read_bytes())
        self.assertEqual(json.loads((self.base / 'releases/catalog.json').read_text())[0]['version'], '0.6.1')

    def test_tampered_installed_updater_is_rejected_before_activation(self):
        (self.base / 'remote-updater.pyz').write_bytes(b'untrusted-fixture')
        with self.assertRaisesRegex(ValueError, 'installed_updater_requires_review'):
            bootstrap.repair_updater(self.kit, self.root, self.systemctl)
        self.assertEqual(self.commands, [])
        self.assertFalse((self.control / 'repairs').exists())

    def test_symlink_catalog_is_rejected_without_following_it(self):
        catalog = self.base / 'releases/catalog.json'
        catalog.unlink()
        catalog.symlink_to(self.state / 'identity.json')
        with self.assertRaisesRegex(ValueError, 'untrusted_updater_path'):
            bootstrap.repair_updater(self.kit, self.root, self.systemctl)
        self.assertEqual(self.commands, [])


if __name__ == '__main__':
    unittest.main()
