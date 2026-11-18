import base64
import contextlib
import io
import json
import os
import subprocess
import sys
import tempfile
import threading
import time
import unittest
import uuid
import zipfile
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

from agent_center.api import Outcome
from agent_center.execution import Executor
from agent_center.journal import Journal
from agent_center.policy import Policy
from agent_center.state import InstanceLock, atomic_write
from agent_center.update.engine import Engine, UpdateError, self_check
from agent_center.update.remote import Remote


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
    def __init__(self, sha, phase="precheck"):
        self.operation = {"id": str(uuid.uuid4()), "kind": "update", "phase": phase,
                          "from_version": "0.5.4", "release": {"sha256": sha, "version": "0.5.5"}}
        self.report = None

    def call(self, operation, data):
        if operation == "operations/claim":
            return Outcome(True, 200, {"operation": self.operation})
        self.report = data
        return Outcome(True, 200, {})


class UpdateRegressions(unittest.TestCase):
    def setUp(self):
        import hashlib
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.base = self.root / "base"
        (self.base / "runtime").mkdir(parents=True)
        (self.base / "runtime/agent.pyz").write_bytes(b"OLD")
        (self.base / "releases").mkdir()
        self.state = self.root / "state"
        self.state.mkdir()
        (self.state / "preserve").write_bytes(b"PRESERVE")
        self.artifact = self.root / "agent.pyz"
        with zipfile.ZipFile(self.artifact, "w") as archive:
            archive.writestr("__main__.py", "pass")
            archive.writestr("agent_center/main.py", "pass")
        self.sha = hashlib.sha256(self.artifact.read_bytes()).hexdigest()
        (self.base / "releases" / (self.sha + ".pyz")).write_bytes(self.artifact.read_bytes())
        self.service = Service()
        self.engine = Engine(self.base, self.state, self.service, checker=lambda path: {})
        self.client = Client(self.sha)
        self.remote = Remote(self.engine, self.client, [{"sha256": self.sha, "version": "0.5.5", "device_operations_frozen": True}], self.root / "control")

    def test_typed_self_check_errors_do_not_expose_exception_details(self):
        for failure, code in [
            (PermissionError("PRIVATE-SENTINEL"), "self_check_permission_denied"),
            (subprocess.TimeoutExpired("PRIVATE-SENTINEL", 15), "self_check_timeout"),
            (subprocess.CalledProcessError(2, "PRIVATE-SENTINEL"), "self_check_exec_failed"),
            (subprocess.SubprocessError("PRIVATE-SENTINEL"), "self_check_privilege_failed"),
        ]:
            with self.subTest(code=code), patch("agent_center.update.engine.subprocess.run", side_effect=failure):
                with self.assertRaises(UpdateError) as caught:
                    self_check(self.artifact)
                self.assertEqual(str(caught.exception), code)
                self.assertNotIn("PRIVATE", str(caught.exception))

    def test_signed_snapshot_failure_does_not_claim_a_complete_backup(self):
        self.client.operation['phase'] = 'apply'
        self.client.operation['release']['lifecycle'] = {
            'manifest': base64.b64encode(b'fixture manifest').decode(), 'signature': 'fixture',
        }
        self.remote.signed_release = {'version': '0.5.5', 'sha256': self.sha}
        key = self.root / 'public.pem'
        key.write_bytes(b'fixture public key')
        with (patch.object(self.remote, '_candidate', return_value=(self.artifact, self.sha)),
              patch('agent_center.update.remote.trusted', return_value=key),
              patch('agent_center.lifecycle.manager.Manager') as manager,
              contextlib.redirect_stdout(io.StringIO())):
            manager.return_value.apply.return_value = {'state': 'FAILED', 'error': 'backup_failed'}
            self.assertFalse(self.remote.run())
        self.assertEqual(self.client.report['error'], 'backup_failed')
        self.assertNotIn('backup', self.client.report['evidence'])
        self.assertEqual((self.state / 'preserve').read_bytes(), b'PRESERVE')

    def test_self_check_uses_native_subprocess_credentials_without_python_preexec(self):
        metadata = {"version": "0.5.6", "state_schema": 1, "journal_schema": 1,
                    "device_operations_frozen": True, "capabilities": ["agent_info"]}
        with patch("agent_center.update.engine.os.geteuid", return_value=0), patch("agent_center.update.engine.pwd.getpwnam", return_value=SimpleNamespace(pw_uid=123, pw_gid=456)), patch("agent_center.update.engine.subprocess.run", return_value=SimpleNamespace(stdout=json.dumps(metadata).encode())) as run:
            self_check(self.artifact, "0.5.6")
            self.assertNotIn("preexec_fn", run.call_args.kwargs)
            self.assertEqual(run.call_args.kwargs["user"], 123)
            self.assertEqual(run.call_args.kwargs["group"], 456)
            self.assertEqual(run.call_args.kwargs["extra_groups"], [])

    def test_failed_precheck_has_failure_exit_semantics_and_safe_diagnostic(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output), patch("agent_center.update.remote.self_check", side_effect=UpdateError("self_check_privilege_failed")):
            self.assertFalse(self.remote.run())
        self.assertEqual(self.client.report["error"], "self_check_privilege_failed")
        self.assertEqual(self.client.report["status"], "FAILED")
        self.assertIn("maintenance_failed", output.getvalue())
        self.assertEqual((self.base / "runtime/agent.pyz").read_bytes(), b"OLD")

    def test_failed_postcheck_rolls_back_preserves_state_and_reports_backup(self):
        self.client.operation["phase"] = "apply"
        self.remote.postcheck = unittest.mock.Mock(side_effect=[False, True])
        with contextlib.redirect_stdout(io.StringIO()), patch("agent_center.update.remote.self_check", return_value={}):
            self.assertFalse(self.remote.run())
        self.assertEqual(self.client.report["status"], "FAILED")
        self.assertEqual(self.client.report["error"], "postcheck_failed")
        self.assertTrue(self.client.report["evidence"]["rollback"])
        self.assertFalse(self.client.report["evidence"]["postcheck"])
        self.assertTrue((self.remote.control / "backups" / self.client.report["evidence"]["backup"]).is_dir())
        self.assertEqual((self.base / "runtime/agent.pyz").read_bytes(), b"OLD")
        self.assertEqual((self.state / "preserve").read_bytes(), b"PRESERVE")
        self.assertTrue(self.service.active())

    def test_failed_rollback_blocks_automatic_replay_as_uncertain(self):
        self.client.operation["phase"] = "apply"
        self.remote.postcheck = lambda: False
        with contextlib.redirect_stdout(io.StringIO()), patch("agent_center.update.remote.self_check", return_value={}):
            self.assertFalse(self.remote.run())
        self.assertEqual(self.client.report["status"], "UNCERTAIN")
        self.assertEqual(self.client.report["error"], "rollback_failed")
        self.assertIn("backup", self.client.report["evidence"])

    def test_postcheck_requires_fresh_expected_version(self):
        self.remote.expected_version = "0.5.5"
        self.remote.started_at = 0
        value = {"schema_version": 1, "state": "ONLINE", "version": "0.5.4", "heartbeat_successes": 1, "poll_successes": 1}
        atomic_write(self.state / "status.json", value)
        with patch("agent_center.update.remote.time.sleep"):
            self.assertFalse(self.remote._postcheck())
            atomic_write(self.state / "status.json", value | {"version": "0.5.5"})
            self.assertTrue(self.remote._postcheck())

    def test_apply_lock_failure_restores_original_service(self):
        lock = InstanceLock(self.state / "instance.lock")
        try:
            with self.assertRaises(Exception):
                self.engine.apply(self.artifact, self.sha)
            self.assertTrue(self.service.active())
            self.assertEqual((self.base / "runtime/agent.pyz").read_bytes(), b"OLD")
        finally:
            lock.close()

    def test_rollback_lock_failure_restores_original_service(self):
        (self.base / ".previous").mkdir()
        (self.base / ".previous/agent.pyz").write_bytes(b"PREVIOUS")
        lock = InstanceLock(self.state / "instance.lock")
        try:
            with self.assertRaises(Exception):
                self.engine.rollback(False)
            self.assertTrue(self.service.active())
            self.assertEqual((self.base / "runtime/agent.pyz").read_bytes(), b"OLD")
        finally:
            lock.close()


class ExecutorRegressions(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.state = Path(self.tmp.name)
        self.journal = Journal(self.state / "journal.sqlite3")
        self.addCleanup(self.journal.close)
        self.config = SimpleNamespace(state_dir=self.state, config_path="/unused", timeout=5)

    def test_malformed_reconcile_entries_are_skipped_and_valid_ack_is_processed(self):
        execution = str(uuid.uuid4())
        self.journal.reserve(execution, str(uuid.uuid4()))
        client = SimpleNamespace(call=lambda *args: Outcome(True, 200, {"executions": [None, {}, {"execution_id": []}, {"execution_id": execution, "disposition": "acknowledged"}]}))
        Executor(self.config, self.journal, client, threading.Event()).recover()
        self.assertEqual(self.journal.pending(), [])

    def test_watchdog_kills_child_on_deadline_while_renew_is_blocked(self):
        calls, killed = [], []
        began = time.monotonic()

        def call(operation, data):
            calls.append((operation, data))
            if operation == "renew":
                time.sleep(1.3)
            return Outcome(True, 200, {"status": "ok"})

        executor = Executor(self.config, self.journal, SimpleNamespace(call=call), threading.Event())
        executor.RENEW_INTERVAL = 0.01
        task = {"task_id": str(uuid.uuid4()), "execution_id": str(uuid.uuid4()), "generation": 1, "lease_token": "a" * 64, "capability": "agent_info", "input": {}, "timeout": 1}
        original_kill = os.killpg

        def kill(pid, signal):
            killed.append(time.monotonic() - began)
            original_kill(pid, signal)

        with patch.object(executor, "child_command", return_value=[sys.executable, "-c", "import time; time.sleep(10)"]), patch("agent_center.execution.os.killpg", side_effect=kill):
            self.assertTrue(executor.accept(task, Policy(True, ("agent_info",), True, True)))
            executor.thread.join(timeout=4)
        self.assertFalse(executor.busy)
        self.assertTrue(killed)
        self.assertLess(killed[0], 1.7)
        results = [data["result"] for operation, data in calls if operation == "result"]
        self.assertEqual(results, [{"ok": False, "error": "execution_timeout"}])
