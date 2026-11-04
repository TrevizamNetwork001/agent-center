import socket
import sys
import tempfile
import threading
import time
import unittest
import uuid
from pathlib import Path
from unittest.mock import patch

from agent_center.api import Outcome
from agent_center.capabilities import available, execute
from agent_center.config import Config
from agent_center.execution import Executor
from agent_center.heartbeat import Heartbeat
from agent_center.journal import Journal
from agent_center.policy import Policy
from agent_center.state import atomic_write


class ExecutionTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.path = Path(self.tmp.name)
        self.config_path = self.path / "config.json"
        self.agent_id = str(uuid.uuid4())
        data = dict(
            central_url="http://127.0.0.1:1",
            allow_http_loopback=True,
            state_dir=str(self.path / "state"),
            identity_file=str(self.path / "state" / "identity.json"),
            log_dir=str(self.path / "logs"),
            allowed_networks=["127.0.0.0/8", "::1/128"],
            allowed_dns_names=["localhost"],
        )
        atomic_write(self.config_path, data)
        self.config = Config.load(self.config_path)
        self.config.state_dir.mkdir(mode=0o700)
        self.journal = Journal(self.config.state_dir / "journal.sqlite3")
        self.stop = threading.Event()
        self.policy = Policy(True, available(), True, True)
        self.calls = []
        outer = self

        class Fake:
            def call(self, op, data):
                outer.calls.append((op, data))
                if op == "reconcile":
                    return Outcome(
                        True,
                        200,
                        {
                            "executions": [
                                {
                                    "execution_id": e["execution_id"],
                                    "disposition": "resend_result"
                                    if e["state"] == "result_pending"
                                    else "expired",
                                }
                                for e in data["executions"]
                            ]
                        },
                    )
                return Outcome(True, 200, {"status": "ok"})

        self.client = Fake()
        self.executor = Executor(self.config, self.journal, self.client, self.stop)
        self.task = dict(
            task_id=str(uuid.uuid4()),
            execution_id=str(uuid.uuid4()),
            generation=1,
            lease_token="a" * 64,
            timeout=3,
            capability="agent_info",
            input={},
        )

    def tearDown(self):
        self.stop.set()
        self.executor.close()
        self.journal.close()
        self.tmp.cleanup()

    def wait(self):
        self.executor.thread.join(5)
        self.assertFalse(self.executor.busy)

    def test_real_child_executes_once_and_journal_never_stores_lease_secret(self):
        self.assertTrue(self.executor.accept(self.task, self.policy))
        self.wait()
        self.assertEqual([c[0] for c in self.calls], ["start", "result"])
        self.assertEqual(self.calls[-1][1]["result"]["data"]["version"], "0.5.2")
        self.assertFalse(self.executor.accept(self.task, self.policy))
        self.assertEqual(self.journal.pending(), [])
        self.assertNotIn(
            "a" * 64, (self.config.state_dir / "journal.sqlite3").read_bytes().decode("latin1")
        )

    def test_crash_after_start_authorization_never_reexecutes_uncertain(self):
        self.journal.reserve(self.task["execution_id"], self.task["task_id"])
        self.journal.transition(self.task["execution_id"], "executing")
        self.journal.recover()
        self.assertFalse(self.executor.accept(self.task, self.policy))
        self.executor.recover()
        self.assertEqual(self.journal.pending(), [])
        self.assertEqual([c[0] for c in self.calls], ["reconcile"])

    def test_lost_result_ack_replays_result_without_reexecution(self):
        original = self.client.call

        def fail_result(op, data):
            if op == "result":
                return Outcome(False, 503, {})
            return original(op, data)

        with patch.object(self.client, "call", side_effect=fail_result):
            self.executor.accept(self.task, self.policy)
            self.wait()
        self.assertEqual(self.journal.pending()[0]["state"], "result_pending")
        self.executor.recover()
        self.assertEqual(self.journal.pending(), [])
        self.assertEqual(sum(op == "start" for op, _ in self.calls), 1)

    def test_lease_rejection_never_starts_child(self):
        with (
            patch.object(self.client, "call", return_value=Outcome(False, 409, {})),
            patch("subprocess.Popen") as child,
        ):
            self.executor.accept(self.task, self.policy)
            self.wait()
            child.assert_not_called()
        self.assertEqual(self.journal.pending()[0]["state"], "uncertain")

    def test_timeout_kills_child_process_group_and_preserves_heartbeat(self):
        self.task["timeout"] = 1
        hb = Heartbeat(self.client, 0.03, self.stop)
        hb.start()
        try:
            with patch.object(
                self.executor,
                "child_command",
                return_value=[sys.executable, "-c", "import time; time.sleep(10)"],
            ):
                self.executor.accept(self.task, self.policy)
                self.wait()
            self.assertGreater(hb.successes, 20)
            self.assertLess(hb.max_gap, 0.2)
            self.assertEqual(self.calls[-1][1]["result"]["error"], "execution_timeout")
        finally:
            self.stop.set()
            hb.join()

    def test_cancel_stops_child_and_reports_cancelled(self):
        with patch.object(
            self.executor,
            "child_command",
            return_value=[sys.executable, "-c", "import time; time.sleep(10)"],
        ):
            self.executor.accept(self.task, self.policy)
            time.sleep(0.15)
            self.executor.cancel.set()
            self.wait()
        self.assertEqual(self.calls[-1][1]["result"]["error"], "cancelled")

    def test_future_device_unknown_capability_and_malformed_tasks_are_rejected(self):
        for change in [
            dict(capability="device_future:v99"),
            dict(capability="shell"),
            dict(generation=True),
            dict(timeout=100),
            dict(execution_id="../../bad"),
            dict(lease_token="secret"),
        ]:
            self.assertFalse(self.executor.accept(self.task | change, self.policy))
        self.assertEqual(self.calls, [])

    def test_forward_dns_is_local_allowlisted_and_returns_separate_a_aaaa(self):
        for kind in ["A", "AAAA"]:
            result = execute(
                "dns_lookup:v1",
                dict(target="localhost", record_type=kind),
                self.policy,
                self.config.allowed_networks,
                self.config.allowed_dns_names,
            )
            self.assertTrue(result["ok"])
            self.assertEqual(result["data"]["record_type"], kind)
            self.assertIn("answers", result["data"])
        with patch("socket.getaddrinfo") as lookup:
            self.assertEqual(
                execute(
                    "dns_lookup:v1",
                    dict(target="unauthorized.invalid", record_type="A"),
                    self.policy,
                    self.config.allowed_networks,
                    self.config.allowed_dns_names,
                )["error"],
                "policy_denied",
            )
            lookup.assert_not_called()

    def test_dns_rebinding_and_out_of_scope_addresses_fail_closed(self):
        with patch(
            "socket.getaddrinfo",
            return_value=[(socket.AF_INET, socket.SOCK_STREAM, 6, "", ("192.0.2.1", 443))],
        ):
            self.assertFalse(
                execute(
                    "dns_lookup:v1",
                    dict(target="localhost", record_type="A"),
                    self.policy,
                    self.config.allowed_networks,
                    self.config.allowed_dns_names,
                )["ok"]
            )

    def test_dns_nodata_is_structured_and_timeout_does_not_mutate_global_socket_timeout(self):
        old = socket.getdefaulttimeout()
        with patch("socket.getaddrinfo", side_effect=socket.gaierror(socket.EAI_NONAME, "fixture")):
            result = execute(
                "dns_lookup:v1",
                dict(target="localhost", record_type="AAAA"),
                self.policy,
                self.config.allowed_networks,
                self.config.allowed_dns_names,
            )
        self.assertEqual(result["data"]["error"], "nodata")
        self.assertEqual(socket.getdefaulttimeout(), old)

    def test_task_policy_negotiation_and_frozen_flag_are_required(self):
        raw = dict(
            schema_version=1,
            agent_id=self.agent_id,
            task_protocol=1,
            tasks_enabled=True,
            device_operations_frozen=True,
            allowed_capabilities=list(available()),
        )
        self.assertTrue(Policy.parse(raw, self.agent_id, available()).tasks)
        self.assertFalse(
            Policy.parse(
                raw | dict(device_operations_frozen=False), self.agent_id, available()
            ).valid
        )
        self.assertFalse(
            Policy.parse(raw | dict(task_protocol=2), self.agent_id, available()).valid
        )
