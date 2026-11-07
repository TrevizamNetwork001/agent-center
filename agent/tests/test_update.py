import hashlib
import tempfile
import unittest
import zipapp
from pathlib import Path

from agent_center import __version__
from agent_center.state import InstanceLock
from agent_center.update.engine import Engine, UpdateError


class Service:
    def __init__(self, active=True):
        self.running = active
        self.started = 0
        self.stopped = 0

    def active(self):
        return self.running

    def stop(self):
        self.running = False
        self.stopped += 1

    def start(self):
        self.running = True
        self.started += 1


class UpdateTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.base = self.root / "install"
        self.base.mkdir()
        (self.base / "runtime").mkdir()
        (self.base / "runtime" / "agent.pyz").write_text("OLD")
        self.state = self.root / "state"
        self.state.mkdir()
        (self.state / "journal").write_text("PRESERVE")
        package = self.root / "package"
        (package / "agent_center").mkdir(parents=True)
        (package / "__main__.py").write_text("pass")
        (package / "agent_center" / "main.py").write_text("pass")
        self.artifact = self.root / "agent.pyz"
        zipapp.create_archive(package, self.artifact)
        self.sha = hashlib.sha256(self.artifact.read_bytes()).hexdigest()
        self.service = Service()
        self.engine = Engine(self.base, self.state, self.service, lambda _: {})

    def tearDown(self):
        self.tmp.cleanup()

    def test_dry_run_checks_hash_and_does_not_stop_or_swap(self):
        self.assertTrue(self.engine.dry_run(self.artifact, self.sha)["valid"])
        self.assertEqual(self.service.stopped, 0)
        self.assertEqual((self.base / "runtime" / "agent.pyz").read_text(), "OLD")
        with self.assertRaises(UpdateError):
            self.engine.dry_run(self.artifact, "0" * 64)

    def test_update_preserves_journal_and_old_runtime_then_rollback(self):
        self.engine.apply(self.artifact, self.sha)
        self.assertEqual((self.base / ".previous" / "agent.pyz").read_text(), "OLD")
        self.assertEqual((self.state / "journal").read_text(), "PRESERVE")
        self.assertTrue(self.engine.rollback()["valid"])
        self.engine.rollback(False)
        self.assertEqual((self.base / "runtime" / "agent.pyz").read_text(), "OLD")
        self.assertTrue((self.base / ".failed").exists())
        self.assertEqual((self.state / "journal").read_text(), "PRESERVE")

    def test_active_process_lock_prevents_swap_even_after_service_stop(self):
        lock = InstanceLock(self.state / "instance.lock")
        try:
            with self.assertRaises(Exception):
                self.engine.apply(self.artifact, self.sha)
            self.assertEqual((self.base / "runtime" / "agent.pyz").read_text(), "OLD")
        finally:
            lock.close()

    def test_failed_new_self_check_restores_previous_without_old_state_restore(self):
        def check(path):
            if path.parent.name == "runtime" and path.read_bytes() != b"OLD":
                raise UpdateError("simulated")

        self.engine.checker = check
        with self.assertRaises(UpdateError):
            self.engine.apply(self.artifact, self.sha)
        self.assertEqual((self.base / "runtime" / "agent.pyz").read_text(), "OLD")
        self.assertTrue((self.base / ".failed").exists())
        self.assertEqual((self.state / "journal").read_text(), "PRESERVE")

    def test_crash_between_renames_recovers_old_runtime(self):
        (self.base / "runtime").rename(self.base / ".previous")
        self.engine.apply(self.artifact, self.sha)
        self.assertEqual((self.base / ".previous" / "agent.pyz").read_text(), "OLD")

    def test_archive_path_traversal_is_rejected(self):
        import zipfile

        with zipfile.ZipFile(self.artifact, "a") as z:
            z.writestr("../escape.py", "pass")
        bad_sha = hashlib.sha256(self.artifact.read_bytes()).hexdigest()
        with self.assertRaises(UpdateError):
            self.engine.dry_run(self.artifact, bad_sha)

    def test_real_packaged_dry_run_and_exit_codes(self):
        import json
        import subprocess
        import sys

        build = Path(__file__).resolve().parents[1] / "packaging" / "build.py"
        artifact = self.root / "real.pyz"
        subprocess.run([sys.executable, str(build), str(artifact)], check=True, capture_output=True)
        repeated = self.root / "repeated.pyz"
        subprocess.run([sys.executable, str(build), str(repeated)], check=True, capture_output=True)
        self.assertEqual(artifact.read_bytes(), repeated.read_bytes())
        result = subprocess.run(
            [sys.executable, "-I", str(artifact), "--self-check"], check=True, capture_output=True
        )
        info = json.loads(result.stdout)
        self.assertEqual(info["version"], __version__)
        self.assertEqual(info["journal_schema"], 1)
        failure = subprocess.run(
            [sys.executable, "-I", str(artifact), "--config", "/missing/config.json"],
            capture_output=True,
        )
        self.assertEqual(failure.returncode, 2)
        engine = Engine(self.base, self.state)
        self.assertTrue(
            engine.dry_run(artifact, hashlib.sha256(artifact.read_bytes()).hexdigest())["valid"]
        )
