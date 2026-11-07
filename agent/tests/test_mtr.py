import json
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

from agent_center import mtr

FIXTURES = Path(__file__).parent / "fixtures" / "mtr"


class MtrTests(unittest.TestCase):
    def args(self, **kwargs):
        return {
            "target": "8.8.8.8",
            "destination": "8.8.8.8",
            "protocol": "icmp",
            "cycles": 5,
            "max_hops": 16,
            "interval": 1,
            **kwargs,
        }

    def test_fixture_contract_and_absent_asn(self):
        for file in FIXTURES.glob("*.json"):
            target = "2001:4860:4860::8888" if file.stem == "ipv6" else "8.8.8.8"
            hops, reached, raw = mtr.parse(file.read_bytes(), target, 5, 16)
            self.assertTrue(hops)
            self.assertEqual(reached, file.stem != "destination-filtered")
            self.assertTrue(all(h["asn"] is None and h["hostname"] is None for h in hops))
            self.assertNotIn("src", json.dumps(raw))

    def test_bad_empty_overlimit_and_nonfinite_output(self):
        for raw in (b"", b"bad", b"{}", b"x" * (mtr.LIMIT + 1), b'{"report":{"hubs":[]}}'):
            with self.assertRaises(mtr.MtrError):
                mtr.parse(raw, "8.8.8.8", 5, 16)
        raw = json.loads((FIXTURES / "healthy.json").read_bytes())
        raw["report"]["hubs"][0]["Avg"] = float("nan")
        with self.assertRaises(mtr.MtrError):
            mtr.parse(json.dumps(raw), "8.8.8.8", 5, 16)

    def test_explicit_policy_required_even_auto_public_elsewhere(self):
        with self.assertRaisesRegex(mtr.MtrError, "policy_denied"):
            mtr.authorize(self.args(), [], [])
        self.assertEqual(mtr.authorize(self.args(), ["8.8.8.8/32"], []), "8.8.8.8")
        for target in ("127.0.0.1", "10.1.1.1", "169.254.169.254"):
            with self.assertRaises(mtr.MtrError):
                mtr.authorize(self.args(target=target, destination=target), [], [])

    @patch("agent_center.mtr.socket.getaddrinfo")
    def test_dns_rebinding_and_mixed_private_answers(self, resolver):
        args = self.args(destination="google.com")
        resolver.return_value = [(2, 1, 6, "", ("8.8.8.8", 443))]
        self.assertEqual(mtr.authorize(args, [], ["google.com"]), "8.8.8.8")
        resolver.return_value.append((2, 1, 6, "", ("10.0.0.1", 443)))
        with self.assertRaisesRegex(mtr.MtrError, "policy_denied"):
            mtr.authorize(args, [], ["google.com"])
        resolver.return_value = [(2, 1, 6, "", ("1.1.1.1", 443))]
        with self.assertRaises(mtr.MtrError):
            mtr.authorize(args, [], ["google.com"])

    def test_no_arbitrary_flags_udp_or_excessive_limits(self):
        for changes in (
            {"flags": "--udp"},
            {"protocol": "udp"},
            {"cycles": 100},
            {"max_hops": 255},
            {"interval": 0},
        ):
            with self.assertRaises(mtr.MtrError):
                mtr.authorize(self.args(**changes), ["8.8.8.8/32"], [])

    @patch("agent_center.mtr.shutil.which", return_value=None)
    def test_missing_executable(self, _):
        with self.assertRaisesRegex(mtr.MtrError, "mtr_not_installed"):
            mtr.installation()

    @patch("agent_center.mtr.os.geteuid", return_value=0)
    def test_refuses_root(self, _):
        with self.assertRaisesRegex(mtr.MtrError, "mtr_permission_denied"):
            mtr.collect(self.args(), ["8.8.8.8/32"], [])

    @patch("agent_center.mtr.os.geteuid", return_value=987)
    @patch("agent_center.mtr.installation", return_value="/usr/bin/mtr")
    @patch("agent_center.mtr.bounded")
    def test_tcp_arguments_and_sanitized_result(self, run, *_):
        run.return_value = (0, (FIXTURES / "healthy.json").read_bytes(), b"")
        result = mtr.collect(self.args(protocol="tcp"), ["8.8.8.8/32"], [])
        command = run.call_args.args[0]
        self.assertEqual(command[-2:], ["--", "8.8.8.8"])
        self.assertIn("--tcp", command)
        self.assertEqual(command[command.index("--port") + 1], "443")
        self.assertTrue(result["destination_reached"])
        run.return_value = (1, b"", b"secret")
        with self.assertRaisesRegex(mtr.MtrError, "mtr_execution_failed"):
            mtr.collect(self.args(), ["8.8.8.8/32"], [])

    def test_timeout_and_output_cap(self):
        with self.assertRaisesRegex(mtr.MtrError, "execution_timeout"):
            mtr.bounded([sys.executable, "-c", "import time;time.sleep(3)"], 0.05)
        with self.assertRaisesRegex(mtr.MtrError, "mtr_output_limit"):
            mtr.bounded([sys.executable, "-c", "print('x'*100000)"], 2)
        self.assertNotIn("MTR_PACKET", mtr.ENV)
        self.assertNotIn("MTR_OPTIONS", mtr.ENV)
