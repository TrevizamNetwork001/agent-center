import importlib.util
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from agent_center.lifecycle.releases import ReleaseError
from agent_center.lifecycle.updaters import ENTRYPOINTS, Updaters, derive
from agent_center.state import atomic_write


class UpdaterComponentsTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temp = tempfile.TemporaryDirectory()
        root = Path(cls.temp.name)
        spec = importlib.util.spec_from_file_location("builder", Path(__file__).resolve().parents[1] / "packaging/build.py")
        builder = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(builder)
        builder.build(root / "agent.pyz")
        cls.artifact = (root / "agent.pyz").read_bytes()
        cls.expected = {}
        for name in ENTRYPOINTS:
            builder.build(root / name, "remote-updater" if name.startswith("remote") else "updater")
            cls.expected[name] = (root / name).read_bytes()

    @classmethod
    def tearDownClass(cls):
        cls.temp.cleanup()

    def fixture(self, root):
        base, config, backup = root / "base", root / "config", root / "backup"
        for directory in (base, config, backup):
            directory.mkdir(mode=0o700)
        for name in ENTRYPOINTS:
            (base / name).write_bytes(b"previous-" + name.encode())
        atomic_write(config / "lifecycle-policy.json", {
            "schema_version": 1, "device_operations_frozen": True,
            "components": ["lifecycle-updater"], "profiles": ["lifecycle-updater"],
        })
        def trust(path):
            self.assertFalse(path.is_symlink())
            return path
        return Updaters(base, config, trust=trust), base, config, backup

    def test_exact_derivation_and_closed_entrypoints(self):
        for name in ENTRYPOINTS:
            self.assertEqual(self.expected[name], derive(self.artifact, name))
        with self.assertRaises(ReleaseError):
            derive(self.artifact, "../../arbitrary.pyz")

    def test_partial_install_rolls_back_both_files_and_preserves_new_state(self):
        with tempfile.TemporaryDirectory() as directory:
            updater, base, config, backup = self.fixture(Path(directory))
            state = base / "journal.sqlite3"
            state.write_bytes(b"new-state-after-backup")
            old = {name: (base / name).read_bytes() for name in ENTRYPOINTS}
            updater.precheck(self.artifact)
            updater.snapshot(backup)
            original = updater.write
            count = 0
            def fail_second(*args):
                nonlocal count
                count += 1
                if count == 2:
                    raise OSError("injected")
                return original(*args)
            with patch.object(updater, "write", side_effect=fail_second):
                with self.assertRaises(OSError):
                    updater.install()
            updater.rollback(backup)
            self.assertTrue(updater.rollback_health(backup))
            self.assertEqual(old, {name: (base / name).read_bytes() for name in ENTRYPOINTS})
            self.assertEqual(b"new-state-after-backup", state.read_bytes())

    def test_denied_policy_and_tampered_backup_refuse_before_writes(self):
        with tempfile.TemporaryDirectory() as directory:
            updater, base, config, backup = self.fixture(Path(directory))
            updater.precheck(self.artifact)
            updater.snapshot(backup)
            updater.install()
            (backup / "updaters" / "updater.pyz").write_bytes(b"tampered")
            before = {name: (base / name).read_bytes() for name in ENTRYPOINTS}
            with self.assertRaisesRegex(ReleaseError, "invalid_updater_backup"):
                updater.rollback(backup)
            self.assertEqual(before, {name: (base / name).read_bytes() for name in ENTRYPOINTS})
            atomic_write(config / "lifecycle-policy.json", {"schema_version": 1})
            with self.assertRaisesRegex(ReleaseError, "permission_not_authorized"):
                updater.precheck(self.artifact)


if __name__ == "__main__":
    unittest.main()
