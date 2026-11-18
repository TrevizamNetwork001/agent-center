import http.server
import logging
import socket
import tempfile
import threading
import time
import unittest
import uuid
from pathlib import Path
from unittest.mock import patch

from agent_center.api import Client, Outcome
from agent_center.capabilities import KNOWN, available, execute, self_check
from agent_center.config import Config
from agent_center.heartbeat import Heartbeat
from agent_center.identity import Identity
from agent_center.journal import Journal
from agent_center.logging import SafeFormatter
from agent_center.main import Runtime
from agent_center.policy import Policy
from agent_center.state import InstanceLock, StateError, atomic_write, read_private


class Handler(http.server.BaseHTTPRequestHandler):
    status = 200
    body = b'{"status":"ok"}'

    def do_POST(self):
        self.rfile.read(int(self.headers.get("Content-Length", "0")))
        if self.path.endswith("/start"):
            time.sleep(0.2)
        self.send_response(type(self).status)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        try:
            self.wfile.write(type(self).body)
        except OSError:
            pass

    def log_message(self, *args):
        pass


class Base(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.path = Path(self.tmp.name)
        self.agent_id = str(uuid.uuid4())
        self.identity = Identity(self.agent_id, self.agent_id + "." + "a" * 64)

    def tearDown(self):
        self.tmp.cleanup()

    def config(self, **extra):
        value = dict(
            central_url="http://127.0.0.1:1",
            allow_http_loopback=True,
            state_dir=str(self.path / "state"),
            log_dir=str(self.path / "logs"),
            identity_file=str(self.path / "state" / "identity.json"),
            device_operations_frozen=True,
        )
        value.update(extra)
        p = self.path / "config.json"
        atomic_write(p, value)
        return Config.load(p)


class ConfigStateTests(Base):
    def test_config_secure_defaults_and_invalid_config(self):
        c = self.config()
        self.assertTrue(c.device_operations_frozen)
        for extra in [
            dict(central_url="http://public.example.test"),
            dict(device_operations_frozen=False),
            dict(poll_interval="bad"),
            dict(central_url="https://user:secret@central.test"),
            dict(identity_file="/tmp/wrong/identity.json"),
        ]:
            with self.assertRaises(StateError):
                self.config(**extra)

    def test_identity_and_permissions(self):
        c = self.config()
        self.identity.save(c.identity_file)
        self.assertEqual(Identity.load(c.identity_file).agent_id, self.agent_id)
        with self.assertRaises(StateError):
            self.identity.save(c.identity_file)
        c.identity_file.chmod(0o644)
        with self.assertRaises(StateError):
            Identity.load(c.identity_file)
        with self.assertRaises(StateError):
            Identity.validate({"agent_id": self.agent_id, "token": "secret"})

    def test_atomic_failure_preserves_previous_state(self):
        p = self.path / "state.json"
        atomic_write(p, {"value": 1})
        with patch("os.replace", side_effect=OSError("simulated")):
            with self.assertRaises(OSError):
                atomic_write(p, {"value": 2})
        self.assertEqual(read_private(p), {"value": 1})

    def test_invalid_json_never_overwrites_state_and_instance_lock_excludes_duplicate(self):
        p = self.path / "bad.json"
        p.write_text("{bad")
        p.chmod(0o600)
        with self.assertRaises(StateError):
            read_private(p)
        lock = InstanceLock(self.path / "instance.lock")
        try:
            with self.assertRaises(StateError):
                InstanceLock(self.path / "instance.lock")
        finally:
            lock.close()

    def test_symlink_state_is_refused(self):
        p = self.path / "link"
        p.symlink_to(self.path / "destination")
        with self.assertRaises(StateError):
            atomic_write(p, {"value": 1})


class NetworkTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()
        cls.url = "http://127.0.0.1:" + str(cls.server.server_port)

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join()

    def setUp(self):
        Handler.status = 200
        Handler.body = b'{"status":"ok"}'

    def test_http_statuses_are_controlled(self):
        for code in [401, 403, 409, 429, 500, 503]:
            Handler.status = code
            r = Client(self.url, retries=0).call("claim")
            self.assertFalse(r.ok)
            self.assertEqual(r.status, code)
            self.assertEqual(r.retryable, code == 429 or code >= 500)

    def test_invalid_json_incomplete_body_and_wrong_envelope(self):
        for raw in [b"{bad", b"", b"[]"]:
            Handler.body = raw
            self.assertFalse(Client(self.url, retries=0).call("claim").ok)

    def test_timeout_dns_and_connection_refusal_do_not_raise(self):
        self.assertFalse(Client(self.url, timeout=0.03, retries=0).call("start").ok)
        self.assertFalse(Client("http://127.0.0.1:1", timeout=0.1, retries=0).call("claim").ok)
        with patch("socket.getaddrinfo", side_effect=socket.gaierror()):
            self.assertFalse(Client("https://not-a-real-host.test", retries=0).call("claim").ok)

    def test_enrollment_is_never_automatically_retried(self):
        c = Client(self.url, retries=4)
        with patch.object(c.opener, "open", side_effect=OSError()) as mocked:
            self.assertFalse(c.call("enroll").ok)
            self.assertEqual(mocked.call_count, 1)

    def test_tls_verification_and_redirects_are_not_disabled(self):
        c = Client("https://127.0.0.1", retries=0)
        handler = next(
            h
            for h in c.opener.handlers
            if isinstance(h, __import__("urllib.request", fromlist=["HTTPSHandler"]).HTTPSHandler)
        )
        self.assertTrue(handler._context.check_hostname)
        self.assertEqual(handler._context.verify_mode, __import__("ssl").CERT_REQUIRED)


class JournalPolicyTests(Base):
    def test_journal_recovery_has_order_and_never_duplicates(self):
        p = self.path / "journal.db"
        j = Journal(p)
        self.assertTrue(j.reserve("execution", "task"))
        self.assertFalse(j.reserve("execution", "task"))
        self.assertTrue(j.transition("execution", "executing"))
        j.close()
        j = Journal(p)
        pending = j.recover()
        self.assertEqual(pending[0]["state"], "uncertain")
        self.assertFalse(j.transition("execution", "executing"))
        self.assertFalse(j.reserve("execution", "task"))
        self.assertTrue(j.check())
        self.assertEqual(
            j.db.execute("SELECT state FROM events ORDER BY seq").fetchall(),
            [("claimed",), ("executing",), ("uncertain",)],
        )
        j.close()

    def test_run_once_persists_result_and_replay_never_executes_action_twice(self):
        p = self.path / "result.db"
        j = Journal(p)
        calls = []

        def action():
            calls.append(1)
            return {"status": "safe"}

        self.assertEqual(j.run_once("execution", "task", action), {"status": "safe"})
        j.close()
        j = Journal(p)
        j.recover()
        self.assertIsNone(j.run_once("execution", "task", action))
        self.assertEqual(j.result("execution"), {"status": "safe"})
        self.assertEqual(len(calls), 1)
        j.close()

    def test_committed_result_is_not_reexecuted_on_replay(self):
        j = Journal(self.path / "j.db")
        j.reserve("x", "t")
        j.transition("x", "executing")
        j.transition("x", "result_pending")
        self.assertEqual(j.recover()[0]["state"], "result_pending")
        self.assertFalse(j.transition("x", "executing"))
        j.close()

    def test_policy_missing_invalid_identity_and_future_device_actions_fail_closed(self):
        for raw in [None, {}, [], {"schema_version": 1, "agent_id": "wrong"}]:
            p = Policy.parse(raw, self.agent_id, available())
            self.assertFalse(p.valid)
        p = Policy.parse(
            {"schema_version": 1, "agent_id": self.agent_id, "allowed_capabilities": list(KNOWN)},
            self.agent_id,
            available(),
        )
        self.assertTrue(p.valid)
        for name in ["device_unknown", "device_future:v99", "device_", "untrusted_command"]:
            self.assertFalse(execute(name, {}, p)["ok"])
        self.assertTrue(execute("agent_info", {}, p)["ok"])

    def test_self_check_exact_advertised_capabilities_and_no_network_by_default(self):
        self.assertEqual(self_check(), available())
        p = Policy(True, available())
        self.assertFalse(execute("tcp_connect:v1", {"target": "127.0.0.1", "port": 1}, p)["ok"])

    def test_legacy_self_check_does_not_contact_optional_mtr_helper(self):
        with patch('agent_center.capabilities.mtr.ready', side_effect=AssertionError('optional helper contacted')):
            capabilities = self_check()
        self.assertNotIn('mtr_path_analysis:v1', capabilities)
        self.assertIn('agent_info', capabilities)

    def test_logging_never_interpolates_secret_or_exception(self):
        record = logging.LogRecord(
            "agent", 40, "x", 1, "Authorization: Bearer %s", ("secret-token",), None
        )
        self.assertNotIn("secret", SafeFormatter().format(record))


class TimingTests(Base):
    def test_heartbeat_is_independent_from_slow_poll_journal_and_status(self):
        class Fast:
            def call(self, *args):
                return Outcome(True, 200, {"status": "ok"})

        stop = threading.Event()
        hb = Heartbeat(Fast(), 0.04, stop)
        j = Journal(self.path / "j.db")
        try:
            hb.start()
            with j.lock:
                # Simulates poll/network/status/journal blocking the execution thread.
                time.sleep(0.35)
            self.assertGreaterEqual(hb.successes, 6)
            self.assertLess(hb.max_gap, 0.15)
        finally:
            stop.set()
            hb.join()
            j.close()

    def test_cold_start_network_down_stays_alive_and_poll_backoff_is_bounded(self):
        c = self.config(heartbeat_interval=1, poll_interval=1, timeout=0.1)
        r = Runtime(c, self.identity)
        t = threading.Thread(target=r.run)
        t.start()
        try:
            deadline = time.monotonic() + 3
            while r.state != "DEGRADED" and time.monotonic() < deadline:
                time.sleep(0.05)
            self.assertTrue(t.is_alive())
            self.assertEqual(r.state, "DEGRADED")
            self.assertLessEqual(r.poller.delay(5), 60)
        finally:
            r.stop.set()
            t.join(3)
        self.assertFalse(t.is_alive())


class ActionContractTests(Base):
    def test_loopback_tcp_and_http_are_read_only_and_target_is_checked(self):
        class Head(http.server.BaseHTTPRequestHandler):
            def do_HEAD(self):
                self.send_response(200)
                self.end_headers()

            def log_message(self, *args):
                pass

        server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Head)
        worker = threading.Thread(target=server.serve_forever, daemon=True)
        worker.start()
        try:
            policy = Policy(True, available())
            net = ("127.0.0.0/8",)
            self.assertTrue(
                execute(
                    "tcp_connect:v1",
                    {"target": "127.0.0.1", "port": server.server_port},
                    policy,
                    net,
                )["ok"]
            )
            self.assertTrue(
                execute(
                    "http_probe:v1",
                    {"url": "http://127.0.0.1:" + str(server.server_port)},
                    policy,
                    net,
                )["ok"]
            )
            self.assertFalse(
                execute(
                    "tcp_connect:v1",
                    {"target": "127.0.0.1;command", "port": server.server_port},
                    policy,
                    net,
                )["ok"]
            )
        finally:
            server.shutdown()
            server.server_close()
            worker.join()

    def test_fixed_subprocess_arguments_never_pass_shell_or_device_commands(self):
        from types import SimpleNamespace

        with (
            patch("shutil.which", return_value="/usr/bin/known-probe"),
            patch(
                "subprocess.run",
                return_value=SimpleNamespace(
                    returncode=0, stdout=b"5 packets transmitted, 5 received, 0% packet loss\n"
                ),
            ) as process,
        ):
            policy = Policy(True, KNOWN)
            for capability in ["icmp_ping:v1", "traceroute:v1"]:
                self.assertTrue(
                    execute(capability, {"target": "127.0.0.1"}, policy, ("127.0.0.0/8",))["ok"]
                )
                argv = process.call_args.args[0]
                self.assertEqual(argv[-2:], ["--", "127.0.0.1"])
                self.assertFalse(process.call_args.kwargs.get("shell", False))

    def test_boolean_schema_version_is_not_an_integer_policy_version(self):
        self.assertFalse(
            Policy.parse(
                {"agent_id": self.agent_id, "schema_version": True}, self.agent_id, available()
            ).valid
        )


class RuntimeIsolationTests(Base):
    def test_blocked_status_write_does_not_block_runtime_heartbeat(self):
        from dataclasses import replace

        blocked = threading.Event()
        release = threading.Event()
        config = replace(self.config(), heartbeat_interval=0.04, poll_interval=0.04)
        identity = self.identity

        class FakeClient:
            def __init__(self, *args):
                pass

            def call(self, operation, data):
                if operation == "heartbeat":
                    return Outcome(True, 200, {"status": "ok"})
                if operation == "capabilities":
                    return Outcome(True, 200, {"capabilities": list(available())})
                return Outcome(
                    True,
                    200,
                    {
                        "task": None,
                        "policy": {
                            "schema_version": 1,
                            "agent_id": identity.agent_id,
                            "allowed_capabilities": list(available()),
                        },
                    },
                )

        def slow_write(path, data):
            if data["state"] == "ONLINE":
                blocked.set()
                release.wait(2)
            atomic_write(path, data)

        runtime = Runtime(config, identity, FakeClient)
        with patch("agent_center.main.atomic_write", side_effect=slow_write):
            thread = threading.Thread(target=runtime.run)
            thread.start()
            try:
                self.assertTrue(blocked.wait(2))
                time.sleep(0.3)
                self.assertGreaterEqual(runtime.heartbeat.successes, 6)
                self.assertLess(runtime.heartbeat.max_gap, 0.15)
            finally:
                runtime.stop.set()
                release.set()
                thread.join(3)
            self.assertFalse(thread.is_alive())
