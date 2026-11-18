import hashlib
import shutil
import sys
import tempfile
import unittest
import uuid
from pathlib import Path
from unittest.mock import patch

from agent_center import __version__
from agent_center.api import Outcome
from agent_center.state import atomic_write
from agent_center.update.engine import Engine, UpdateError
from agent_center.update.remote import Remote


class Service:
    running = True

    def active(self):
        return self.running

    def stop(self):
        self.running = False

    def start(self):
        self.running = True


class Client:
    def __init__(self, operation):
        self.operation, self.reports = operation, []

    def call(self, operation, data):
        if operation == "operations/claim":
            op, self.operation = self.operation, None
            return Outcome(True, 200, {"operation": op})
        self.reports.append(data)
        return Outcome(True, 200, {"status": "ok"})


class RemoteTests(unittest.TestCase):
    def setUp(self):
        import subprocess

        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        self.root.chmod(0o755)
        self.base, self.state, self.control = (self.root / n for n in ("base", "state", "control"))
        (self.base / "runtime").mkdir(parents=True)
        (self.base / "releases").mkdir()
        self.state.mkdir()
        (self.state / "journal").write_text("CURRENT JOURNAL")
        artifact = self.root / "agent.pyz"
        subprocess.run(
            [sys.executable, "packaging/build.py", str(artifact)], check=True, capture_output=True
        )
        self.sha = hashlib.sha256(artifact.read_bytes()).hexdigest()
        self.release = {"sha256": self.sha, "version": __version__, "device_operations_frozen": True}
        shutil.copyfile(artifact, self.base / "runtime/agent.pyz")
        shutil.copyfile(artifact, self.base / "releases" / (self.sha + ".pyz"))
        self.engine = Engine(self.base, self.state, Service())

    def tearDown(self):
        self.tmp.cleanup()

    def remote(self, kind="update", phase="precheck"):
        client = Client(dict(id=str(uuid.uuid4()), kind=kind, phase=phase, release=self.release))
        return Remote(
            self.engine, client, [dict(self.release)], self.control, postcheck=lambda: True
        ), client

    def test_precheck_does_not_stop_and_apply_preserves_journal_and_backup(self):
        remote, client = self.remote()
        self.assertTrue(remote.run())
        self.assertEqual(client.reports[-1]["status"], "PLANNED")
        self.assertTrue(self.engine.service.active())
        remote, client = self.remote(phase="apply")
        self.assertTrue(remote.run())
        self.assertEqual(client.reports[-1]["status"], "COMPLETED")
        backup = self.control / "backups" / client.reports[-1]["evidence"]["backup"]
        self.assertEqual((backup / "state/journal").read_text(), "CURRENT JOURNAL")
        self.assertEqual((self.state / "journal").read_text(), "CURRENT JOURNAL")
        remote, client = self.remote(kind="rollback", phase="apply")
        self.assertTrue(remote.run())
        self.assertEqual(client.reports[-1]["status"], "COMPLETED")
        self.assertEqual((self.state / "journal").read_text(), "CURRENT JOURNAL")

    def test_unstaged_release_wrong_sha_and_device_unfreeze_are_refused(self):
        for mutate in (
            lambda r: r.catalog.clear(),
            lambda r: r.catalog[0].update(device_operations_frozen=False),
        ):
            remote, client = self.remote(phase="apply")
            mutate(remote)
            remote.run()
            self.assertEqual(client.reports[-1]["status"], "FAILED")
            self.assertTrue(self.engine.service.active())
        remote, client = self.remote()
        (self.base / "releases" / (self.sha + ".pyz")).write_bytes(b"BAD")
        remote.run()
        self.assertEqual(client.reports[-1]["error"], "sha256_mismatch")

    def test_interrupted_marker_is_reported_uncertain_without_reexecution(self):
        self.control.mkdir()
        oid = str(uuid.uuid4())
        atomic_write(self.control / (oid + ".json"), {"operation_id": oid, "status": "PROCESSING"})
        client = Client(None)
        Remote(self.engine, client, [self.release], self.control).run()
        self.assertEqual(client.reports[0]["status"], "UNCERTAIN")
        self.assertEqual(client.reports[0]["error"], "interrupted")

    def test_failed_rollback_is_failed_and_unverified_recovery_is_uncertain(self):
        remote, client = self.remote(kind="rollback", phase="apply")
        remote.run()
        self.assertEqual(client.reports[-1]["status"], "FAILED")
        remote, client = self.remote(phase="apply")
        remote.postcheck = lambda: False
        remote.run()
        self.assertEqual(client.reports[-1]["status"], "UNCERTAIN")
        self.assertEqual(client.reports[-1]["error"], "rollback_failed")
        self.assertFalse(client.reports[-1]["evidence"]["rollback"])

    def test_version_inconsistency_and_unknown_operations_are_refused(self):
        remote, client = self.remote()
        with patch("agent_center.update.remote.self_check", side_effect=UpdateError("incompatible")):
            remote.run()
        self.assertEqual(client.reports[-1]["error"], "incompatible")
        remote, client = self.remote(kind="device_future")
        remote.run()
        self.assertEqual(client.reports[-1]["status"], "FAILED")
