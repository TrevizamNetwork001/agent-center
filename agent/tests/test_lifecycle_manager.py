import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch
from uuid import uuid4

from agent_center.lifecycle.manager import Manager
from agent_center.lifecycle.releases import ReleaseError
from agent_center.state import InstanceLock, StateError, read_private


class LifecycleTransactions(unittest.TestCase):
    def run_case(self, healthy):
        with tempfile.TemporaryDirectory() as directory:
            adapter = Mock()
            adapter.snapshot.return_value = "backup-1"
            adapter.health.return_value = healthy
            adapter.rollback_health.return_value = True
            manager = Manager(directory, adapter, b"public", "debian13")
            release = {"sequence": 1, "sha256": "a" * 64}
            operation = str(uuid4())
            with patch("agent_center.lifecycle.manager.verify", return_value=release) as verify:
                result = manager.apply(operation, b"manifest", b"signature", b"artifact")
                self.assertEqual(result["state"], "COMPLETED" if healthy else "FAILED")
                self.assertEqual(
                    manager.apply(operation, b"manifest", b"signature", b"artifact"), result
                )
                self.assertEqual(verify.call_count, 1)
                with self.assertRaisesRegex(ReleaseError, "operation_conflict"):
                    manager.apply(operation, b"other", b"signature", b"artifact")
            adapter.install.assert_called_once()
            if healthy:
                self.assertEqual(read_private(Path(directory) / "installed.json"), release)
                adapter.rollback.assert_not_called()
            else:
                adapter.rollback.assert_called_once_with("backup-1")
                self.assertFalse((Path(directory) / "installed.json").exists())

    def test_commit_and_idempotency(self):
        self.run_case(True)

    def test_failed_health_rolls_back(self):
        self.run_case(False)

    def test_concurrent_update_is_refused_before_adapter(self):
        with tempfile.TemporaryDirectory() as directory:
            lock = InstanceLock(Path(directory) / "lifecycle.lock")
            adapter = Mock()
            try:
                with self.assertRaises(StateError):
                    Manager(directory, adapter, b"key", "debian13").apply(
                        str(uuid4()), b"x", b"y", b"z"
                    )
                adapter.precheck.assert_not_called()
            finally:
                lock.close()

    def test_component_registry_survives_core_only_update_and_downgrade_is_refused(self):
        from agent_center.state import atomic_write

        with tempfile.TemporaryDirectory() as directory:
            control = Path(directory)
            old = {"sequence": 1, "components": [{"id": "mtr", "version": "1.1.0"}]}
            atomic_write(control / "installed.json", old)
            adapter = Mock()
            adapter.snapshot.return_value = "backup"
            adapter.health.return_value = True
            manager = Manager(control, adapter, b"public", "debian13")
            with patch(
                "agent_center.lifecycle.manager.verify",
                return_value={"sequence": 2, "sha256": "a" * 64, "version": "0.6.2"},
            ):
                result = manager.apply(str(uuid4()), b"manifest", b"signature", b"artifact")
            self.assertEqual(result["state"], "COMPLETED")
            self.assertEqual(
                read_private(control / "installed.json")["components"], old["components"]
            )
            adapter.reset_mock()
            with patch(
                "agent_center.lifecycle.manager.verify",
                return_value={
                    "sequence": 3,
                    "sha256": "b" * 64,
                    "version": "0.6.2",
                    "components": [{"id": "mtr", "version": "1.0.0"}],
                },
            ):
                with self.assertRaisesRegex(ReleaseError, "component_downgrade"):
                    manager.apply(str(uuid4()), b"other", b"signature", b"artifact")
            adapter.precheck.assert_not_called()


class LifecycleRecovery(unittest.TestCase):
    def setup_case(self, directory):
        import hashlib

        from agent_center.state import atomic_write
        control = Path(directory)
        operation = str(uuid4())
        record = {"operation_id": operation, "state": "UNCERTAIN", "backup": operation,
                  "sequence": 3, "request_digest": hashlib.sha256(b"manifest").hexdigest(),
                  "rollback": False, "error": "install_failed"}
        atomic_write(control / (operation + ".json"), record)
        atomic_write(control / "installed.json", {"sequence": 2})
        adapter = Mock()
        adapter.restored_health.return_value = True
        return Manager(control, adapter, b"key", "debian13"), adapter, operation, record

    def test_unrelated_operation_cannot_bypass_uncertain_hold(self):
        with tempfile.TemporaryDirectory() as directory:
            manager, adapter, operation, record = self.setup_case(directory)
            with self.assertRaisesRegex(ReleaseError, "recovery_required"):
                manager.apply(str(uuid4()), b"manifest", b"signature", b"artifact")
            adapter.precheck.assert_not_called()

    def test_verified_recovery_preserves_original_and_does_not_install_or_restore(self):
        with tempfile.TemporaryDirectory() as directory:
            manager, adapter, operation, record = self.setup_case(directory)
            result = manager.reconcile_restored(operation, record["request_digest"])
            self.assertEqual(result["state"], "FAILED")
            self.assertTrue(result["recovery_verified"])
            self.assertFalse(result["rollback"])
            self.assertEqual(read_private(Path(directory) / "recovery-evidence" / (operation + ".json")), record)
            self.assertEqual(manager.reconcile_restored(operation, record["request_digest"]), result)
            adapter.install.assert_not_called()
            adapter.rollback.assert_not_called()

    def test_failed_health_or_wrong_digest_preserves_hold(self):
        with tempfile.TemporaryDirectory() as directory:
            manager, adapter, operation, record = self.setup_case(directory)
            with self.assertRaisesRegex(ReleaseError, "operation_conflict"):
                manager.reconcile_restored(operation, "wrong")
            adapter.restored_health.assert_not_called()
            adapter.restored_health.return_value = False
            with self.assertRaisesRegex(ReleaseError, "rollback_failed"):
                manager.reconcile_restored(operation, record["request_digest"])
            self.assertEqual(read_private(Path(directory) / (operation + ".json")), record)

    def test_committed_sequence_cannot_be_closed_as_restored(self):
        from agent_center.state import atomic_write
        with tempfile.TemporaryDirectory() as directory:
            manager, adapter, operation, record = self.setup_case(directory)
            atomic_write(Path(directory) / "installed.json", {"sequence": 3})
            with self.assertRaisesRegex(ReleaseError, "recovery_required"):
                manager.reconcile_restored(operation, record["request_digest"])
            adapter.restored_health.assert_not_called()

    def test_interrupted_install_and_check_can_be_verified_without_replay(self):
        from agent_center.state import atomic_write
        for state in ("INSTALLING", "CHECKING"):
            with self.subTest(state=state), tempfile.TemporaryDirectory() as directory:
                manager, adapter, operation, record = self.setup_case(directory)
                record["state"] = state
                record.pop("error")
                record.pop("rollback")
                atomic_write(Path(directory) / (operation + ".json"), record)
                result = manager.reconcile_restored(operation, record["request_digest"])
                self.assertEqual(result["state"], "FAILED")
                self.assertEqual(result["error"], "interrupted")
                self.assertTrue(result["recovery_verified"])
                self.assertEqual(read_private(Path(directory) / "recovery-evidence" / (operation + ".json")), record)
                adapter.install.assert_not_called()
                adapter.rollback.assert_not_called()
                adapter.restored_health.assert_called_once_with(operation)

    def test_interrupted_transaction_still_requires_intact_restored_health(self):
        from agent_center.state import atomic_write
        with tempfile.TemporaryDirectory() as directory:
            manager, adapter, operation, record = self.setup_case(directory)
            record["state"] = "CHECKING"
            atomic_write(Path(directory) / (operation + ".json"), record)
            adapter.restored_health.return_value = False
            with self.assertRaisesRegex(ReleaseError, "rollback_failed"):
                manager.reconcile_restored(operation, record["request_digest"])
            self.assertEqual(read_private(Path(directory) / (operation + ".json")), record)
            self.assertFalse((Path(directory) / "recovery-evidence").exists())
