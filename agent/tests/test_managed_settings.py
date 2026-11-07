import copy
import ipaddress
import struct
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from agent_center.capabilities import KNOWN, execute, public_ip
from agent_center.dns_transport import InvalidResponse, parse, query
from agent_center.managed_settings import apply, normalize
from agent_center.policy import Policy
from agent_center.state import StateError, read_private


class ManagedTests(unittest.TestCase):
    def settings(self):
        return dict(
            revision=1,
            source_ips=["2001:db8::1"],
            auto_public_targets=True,
            allowed_networks=["192.168.2.0/24"],
            allowed_dns_names=["router.internal"],
        )

    def policy(self, settings):
        return dict(
            schema_version=1,
            agent_id="test",
            device_operations_frozen=True,
            agent_settings=settings,
        )

    def test_settings_atomic_private_idempotent_and_revision_monotonic(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d)
            s = self.settings()
            self.assertTrue(apply(p, self.policy(s), "test"))
            self.assertEqual(0o600, (p / "managed-settings.json").stat().st_mode & 0o777)
            self.assertTrue(apply(p, self.policy(s), "test"))
            changed = copy.deepcopy(s)
            changed["revision"] = 2
            self.assertTrue(apply(p, self.policy(changed), "test"))
            self.assertFalse(apply(p, self.policy(s), "test"))
            self.assertEqual(2, read_private(p / "managed-settings.json")["revision"])
            changed["auto_public_targets"] = False
            with self.assertRaises(StateError):
                apply(p, self.policy(changed), "test")

    def test_settings_reject_command_unknown_fields_wrong_agent_and_bad_ips(self):
        with tempfile.TemporaryDirectory() as d:
            self.assertFalse(apply(Path(d), self.policy(self.settings()), "other"))
        for key, value in [
            ("command", "systemctl stop anything"),
            ("source_ips", ["0.0.0.0/0"]),
            ("revision", True),
            ("allowed_networks", ["bad"]),
        ]:
            s = self.settings()
            s[key] = value
            with self.assertRaises(StateError):
                normalize(s)

    def test_public_only_no_private_loopback_metadata_or_multicast(self):
        policy = Policy(True, KNOWN, True, True)
        for value in [
            "127.0.0.1",
            "10.0.0.1",
            "169.254.169.254",
            "224.0.0.1",
            "::1",
            "fc00::1",
            "fe80::1",
            "::ffff:127.0.0.1",
        ]:
            with patch("socket.create_connection") as connect:
                self.assertFalse(public_ip(value))
                self.assertEqual(
                    "policy_denied",
                    execute(
                        "tcp_connect:v1", {"target": value, "port": 443}, policy, auto_public=True
                    )["error"],
                )
                connect.assert_not_called()
        self.assertTrue(public_ip("8.8.8.8"))
        self.assertTrue(public_ip("2001:4860:4860::8888"))

    def test_public_tcp_has_exact_ephemeral_target_and_default_policy_stays_strict(self):
        policy = Policy(True, KNOWN, True, True)
        with patch(
            "agent_center.capabilities.HANDLERS",
            {"tcp_connect:v1": lambda args, nets: {"networks": nets}},
        ):
            r = execute(
                "tcp_connect:v1", {"target": "8.8.8.8", "port": 443}, policy, auto_public=True
            )
            self.assertEqual(("8.8.8.8/32",), r["data"]["networks"])
        with patch("socket.create_connection") as connect:
            self.assertEqual(
                "policy_denied",
                execute("tcp_connect:v1", {"target": "8.8.8.8", "port": 443}, policy)["error"],
            )
            connect.assert_not_called()

    def test_udp_dns_cannot_target_unconfigured_server(self):
        with (
            patch("agent_center.dns_transport.resolvers", return_value=["127.0.0.53"]),
            patch("socket.socket") as sock,
        ):
            self.assertEqual(
                {"ok": False, "error": "policy_denied"},
                query(
                    {
                        "resolver": "10.0.0.1",
                        "target": "google.com",
                        "record_type": "A",
                        "transport": "udp",
                    }
                ),
            )
            sock.assert_not_called()

    def packet(self, identifier=123, flags=0x8180):
        name = b"\x06google\x03com\0"
        question = name + struct.pack("!HH", 1, 1)
        return (
            struct.pack("!6H", identifier, flags, 1, 1, 0, 0)
            + question
            + b"\xc0\x0c"
            + struct.pack("!2HIH", 1, 1, 60, 4)
            + ipaddress.ip_address("8.8.8.8").packed
        )

    def test_dns_response_requires_matching_transaction_name_type_and_valid_compression(self):
        self.assertEqual((["8.8.8.8"], 0, False), parse(self.packet(), 123, "google.com", 1))
        for packet, identifier, name, kind in [
            (self.packet(), 124, "google.com", 1),
            (self.packet(), 123, "example.com", 1),
            (self.packet(), 123, "google.com", 28),
            (self.packet(flags=0x0100), 123, "google.com", 1),
            (b"\0", 123, "google.com", 1),
        ]:
            with self.assertRaises(InvalidResponse):
                parse(packet, identifier, name, kind)
        loop = struct.pack("!6H", 123, 0x8180, 1, 0, 0, 0) + b"\xc0\x0c" + struct.pack("!HH", 1, 1)
        with self.assertRaises(InvalidResponse):
            parse(loop, 123, "google.com", 1)
