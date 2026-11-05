import json
import socket
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from agent_center import mtr, mtr_client, mtr_helper


class HelperTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.root = Path(self.directory.name)
        self.local = {"allowed_networks": ["8.8.8.8/32"], "allowed_dns_names": []}
        self.ceiling = {"schema_version": 1, **self.local}
        self.write()
        self.args = {
            "target": "8.8.8.8",
            "destination": "8.8.8.8",
            "protocol": "tcp",
            "cycles": 5,
            "max_hops": 16,
            "interval": 1,
        }

    def tearDown(self):
        self.directory.cleanup()

    def write(self):
        (self.root / "local-policy").write_text(json.dumps(self.local))
        (self.root / "ceiling").write_text(json.dumps(self.ceiling))

    def request(self, request, **changes):
        left, right = socket.socketpair()
        right.sendall(json.dumps(request).encode() + b"\n")
        try:
            with (
                patch("agent_center.mtr_helper.os.geteuid", return_value=988),
                patch("agent_center.mtr_helper.struct.unpack", return_value=(1, 987, 987)),
                patch("agent_center.mtr.raw_ready", return_value=True),
                patch("agent_center.mtr_helper.locked_rate"),
                patch("agent_center.mtr.collect", return_value={"bounded": True}) as run,
            ):
                result = mtr_helper.serve(left, self.root, self.root, 987)
                return result, run.call_args
        finally:
            left.close()
            right.close()

    def test_only_closed_ready_or_collect_protocol(self):
        self.assertTrue(self.request({"op": "ready", "protocol_version": 1})[0]["ok"])
        self.assertEqual(
            self.request({"op": "collect", "input": self.args})[0],
            {"ok": True, "data": {"bounded": True}},
        )
        for request in (
            {"op": "shell", "command": "id"},
            {"op": "collect", "input": self.args, "command": "id"},
            {"op": "ready", "protocol_version": 2},
        ):
            with self.assertRaises(mtr.MtrError):
                self.request(request)

    def test_intersection_rechecks_local_and_root_ceiling(self):
        self.ceiling["allowed_networks"] = []
        self.write()
        with self.assertRaisesRegex(mtr.MtrError, "policy_denied"):
            self.request({"op": "collect", "input": self.args})
        self.ceiling["allowed_networks"] = ["8.8.8.8/32"]
        self.local["allowed_networks"] = []
        self.write()
        with self.assertRaisesRegex(mtr.MtrError, "policy_denied"):
            self.request({"op": "collect", "input": self.args})

    def test_eof_or_extra_request_cancels(self):
        left, right = socket.socketpair()
        self.assertFalse(mtr_helper.disconnected(left))
        right.sendall(b"x")
        self.assertTrue(mtr_helper.disconnected(left))
        right.close()
        self.assertTrue(mtr_helper.disconnected(left))
        left.close()

    def test_bounded_command_honors_disconnect_before_probe_finishes(self):
        import sys

        with self.assertRaisesRegex(mtr.MtrError, "cancelled"):
            mtr.bounded(
                [sys.executable, "-c", "import time;time.sleep(30)"], 2, cancel=lambda: True
            )

    def test_peer_uid_and_nonroot_enforced(self):
        left, right = socket.socketpair()
        try:
            with patch("agent_center.mtr_helper.os.geteuid", return_value=0):
                with self.assertRaisesRegex(mtr.MtrError, "mtr_permission_denied"):
                    mtr_helper.serve(left, self.root, self.root, 987)
            with (
                patch("agent_center.mtr_helper.os.geteuid", return_value=988),
                patch("agent_center.mtr_helper.struct.unpack", return_value=(1, 989, 989)),
            ):
                with self.assertRaisesRegex(mtr.MtrError, "mtr_permission_denied"):
                    mtr_helper.serve(left, self.root, self.root, 987)
        finally:
            left.close()
            right.close()

    def test_lock_prevents_concurrent_work_and_enforces_cooldown(self):
        first = mtr_helper.locked_rate(self.root)
        try:
            with self.assertRaisesRegex(mtr.MtrError, "mtr_rate_limited"):
                mtr_helper.locked_rate(self.root)
        finally:
            first.close()
        with self.assertRaisesRegex(mtr.MtrError, "mtr_rate_limited"):
            mtr_helper.locked_rate(self.root)

    def test_untrusted_socket_and_unavailable_helper_fail_closed(self):
        with self.assertRaises(mtr.MtrError):
            mtr_client.exchange({"op": "ready"}, self.root / "missing.sock")
        self.assertFalse(mtr_client.ready())

    def test_wire_input_bounded_and_requires_complete_single_json(self):
        for data in (b"{}\n{}\n", b"x" * 4097, b"[]\n"):
            left, right = socket.socketpair()
            right.sendall(data)
            try:
                with self.assertRaises((mtr.MtrError, ValueError)):
                    mtr_helper.receive(left)
            finally:
                left.close()
                right.close()

    def test_probe_children_cannot_read_socket_or_inherit_custom_environment(self):
        import subprocess
        import sys

        original = subprocess.Popen
        with patch("agent_center.mtr.subprocess.Popen", wraps=original) as spawn:
            self.assertEqual(mtr.bounded([sys.executable, "-c", "pass"], 2)[0], 0)
        kwargs = spawn.call_args.kwargs
        self.assertEqual(kwargs["stdin"], subprocess.DEVNULL)
        self.assertEqual(kwargs["env"], mtr.ENV)
        self.assertFalse(kwargs.get("shell", False))
        self.assertFalse(kwargs.get("start_new_session", False))

    def test_readiness_cache_does_not_repeat_socket_activation_before_collection(self):
        mtr_client._READINESS = (0.0, False)
        try:
            with patch(
                "agent_center.mtr_client.exchange",
                return_value={"ok": True, "protocol_version": 1, "capability": mtr.CAPABILITY},
            ) as exchange:
                self.assertTrue(mtr_client.ready())
                self.assertTrue(mtr_client.ready())
                exchange.assert_called_once()
        finally:
            mtr_client._READINESS = (0.0, False)

    def test_closed_output_pipes_cannot_bypass_process_timeout(self):
        import sys

        with self.assertRaisesRegex(mtr.MtrError, "execution_timeout"):
            mtr.bounded(
                [sys.executable, "-c", "import os,time;os.close(1);os.close(2);time.sleep(5)"], 0.1
            )

    def test_ipv6_effective_address_is_canonicalized_in_client_contract(self):
        args = {
            **self.args,
            "target": "2001:4860:4860:0:0:0:0:8888",
            "destination": "2001:4860:4860:0:0:0:0:8888",
        }
        data = {
            "resolved_ip": "2001:4860:4860::8888",
            "destination": args["destination"],
            "protocol": "tcp",
            "cycles": 5,
            "raw_sanitized": {},
        }
        with (
            patch("agent_center.mtr_client.exchange", return_value={"ok": True, "data": data}),
            patch("agent_center.mtr.parse") as parse,
        ):
            self.assertEqual(mtr_client.collect(args, ["2001:4860:4860::8888/128"], []), data)
            self.assertEqual(parse.call_args.args[1], "2001:4860:4860::8888")
