import json
import struct
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

from agent_center import mtr, mtr_helper, observability
from agent_center.state import atomic_write


class ObservabilityTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.state = Path(self.temp.name)
        self.policy = {
            "schema_version": 1,
            "agent_id": "agent",
            "device_operations_frozen": True,
            "allowed_capabilities": [mtr.CAPABILITY],
            "tasks_enabled": True,
            "task_protocol": 1,
        }
        self.settings = {
            "revision": 1,
            "source_ips": [],
            "auto_public_targets": False,
            "allowed_networks": ["8.8.8.0/24"],
            "allowed_dns_names": ["google.com"],
        }
        atomic_write(self.state / "managed-settings.json", self.settings)
        self.request = {
            "id": "request",
            "capability": mtr.CAPABILITY,
            "destination": "8.8.8.8",
            "protocol": "tcp",
            "revision": 1,
            "digest": observability.digest(observability.managed(self.state)),
        }
        self.config = Mock(
            state_dir=self.state,
            allowed_networks=("8.8.8.0/24",),
            allowed_dns_names=("google.com",),
        )
        self.args = {
            "target": "8.8.8.8",
            "destination": "8.8.8.8",
            "protocol": "tcp",
            "cycles": 5,
            "max_hops": 16,
            "interval": 1,
        }

    def decision(self, response):
        with (
            patch("agent_center.observability.Config.load", return_value=self.config),
            patch("agent_center.mtr_client.exchange", return_value=response),
            patch("agent_center.mtr.collect", side_effect=AssertionError("Probe forbidden")),
        ):
            return observability.check(self.request, self.state / "config", self.policy, "agent")

    def test_allowed_and_exact_managed_authorizer(self):
        with patch("agent_center.mtr.authorize", wraps=mtr.authorize) as auth:
            self.assertEqual("ALLOWED", self.decision({"ok": True, "schema_version": 1})["state"])
            auth.assert_called_once_with(
                self.args, self.config.allowed_networks, self.config.allowed_dns_names
            )

    def test_policy_layers_and_helper_health(self):
        for layer, reason in [
            ("base", "POLICY_BASE_DENIED"),
            ("ceiling", "ADMIN_CEILING_DENIED"),
            ("helper", "HELPER_UNHEALTHY"),
        ]:
            with self.subTest(layer=layer):
                result = self.decision(
                    {"ok": False, "schema_version": 1, "reason": reason, "layer": layer}
                )
                self.assertEqual(
                    ("DENIED", reason, layer), (result["state"], result["reason"], result["layer"])
                )
        self.config.allowed_networks = ()
        result = self.decision({"ok": True, "schema_version": 1})
        self.assertEqual("MANAGED_POLICY_DENIED", result["reason"])

    def test_policy_not_applied_and_freeze(self):
        self.request["revision"] = 2
        self.assertEqual(
            "POLICY_NOT_APPLIED", self.decision({"ok": True, "schema_version": 1})["reason"]
        )
        self.request["capability"] = "device_shell"
        self.assertEqual("DEVICE_OPERATIONS_FROZEN", self.decision({})["reason"])

    def test_incompatible_helper_never_allows(self):
        self.assertEqual("UNKNOWN", self.decision({"ok": True})["state"])

    def test_missing_helper_and_sanitized_report(self):
        with patch(
            "agent_center.mtr_client.exchange", side_effect=mtr.MtrError("secret raw traceback")
        ):
            report = observability.snapshot(self.state, self.policy)
            self.assertEqual("HELPER_UNAVAILABLE", report["last_error"])
            self.assertNotIn("secret", json.dumps(report))
            self.assertLess(len(json.dumps(report).encode()), observability.LIMIT)

    def test_installed_but_blocked(self):
        self.policy["allowed_capabilities"] = []
        with patch("agent_center.mtr_client.exchange", side_effect=mtr.MtrError("unavailable")):
            report = observability.snapshot(self.state, self.policy)
        cap = next(c for c in report["capabilities"] if c["name"] == mtr.CAPABILITY)
        self.assertTrue(cap["installed"])
        self.assertEqual("BLOCKED", cap["state"])

    def test_shared_helper_authorization_for_collect_and_check(self):
        local = {"allowed_networks": ["8.8.8.0/24"], "allowed_dns_names": []}
        denied = {"allowed_networks": [], "allowed_dns_names": []}
        for base, ceiling, expected in [
            (local, local, None),
            (denied, local, "base"),
            (local, denied, "ceiling"),
        ]:
            self.assertEqual(expected, mtr_helper.authorize_layers(self.args, base, ceiling))
        # Exercise both actual socket request paths; only collect may reach mtr.collect.
        connection = Mock()
        connection.getsockopt.return_value = struct.pack("3i", 1, 1001, 1001)
        for op in ("check", "collect"):
            with (
                patch("agent_center.mtr_helper.os.geteuid", return_value=1002),
                patch(
                    "agent_center.mtr_helper.receive", return_value={"op": op, "input": self.args}
                ),
                patch("agent_center.mtr_helper.policies", return_value=(local, denied)),
                patch("agent_center.mtr.collect", side_effect=AssertionError("Probe forbidden")),
            ):
                if op == "check":
                    self.assertEqual(
                        "ADMIN_CEILING_DENIED",
                        mtr_helper.serve(connection, self.state, self.state, 1001)["reason"],
                    )
                else:
                    with self.assertRaisesRegex(mtr.MtrError, "policy_denied"):
                        mtr_helper.serve(connection, self.state, self.state, 1001)

    def test_allowed_helper_check_and_executor_share_all_layers_without_probe(self):
        policy = {"allowed_networks": ["8.8.8.0/24"], "allowed_dns_names": []}
        connection = Mock()
        connection.getsockopt.return_value = struct.pack("3i", 1, 1001, 1001)
        for op in ("check", "collect"):
            with (
                patch("agent_center.mtr_helper.os.geteuid", return_value=1002),
                patch(
                    "agent_center.mtr_helper.receive", return_value={"op": op, "input": self.args}
                ),
                patch("agent_center.mtr_helper.policies", return_value=(policy, policy)),
                patch("agent_center.mtr.raw_ready", return_value=True),
                patch("agent_center.mtr_helper.locked_rate") as rate,
                patch("agent_center.mtr.collect", return_value={"evidence": "mock"}) as collect,
            ):
                self.assertTrue(mtr_helper.serve(connection, self.state, self.state, 1001)["ok"])
                if op == "check":
                    collect.assert_not_called()
                    rate.assert_not_called()
                else:
                    collect.assert_called_once()

    def test_capability_not_installed_is_never_available(self):
        with (
            patch("agent_center.observability.HANDLERS", {}),
            patch("agent_center.mtr_client.exchange", side_effect=mtr.MtrError("unavailable")),
        ):
            report = observability.snapshot(self.state, self.policy)
        cap = next(c for c in report["capabilities"] if c["name"] == mtr.CAPABILITY)
        self.assertFalse(cap["installed"])
        self.assertEqual("CAPABILITY_UNAVAILABLE", cap["reason"])

    def test_last_application_is_recorded_only_after_success_and_is_stable(self):
        with patch("agent_center.mtr_client.exchange", side_effect=mtr.MtrError("unavailable")):
            self.assertIsNone(observability.snapshot(self.state, self.policy)["policy_applied_at"])
            observability.record_applied(self.state)
            first = observability.snapshot(self.state, self.policy)["policy_applied_at"]
            self.assertIsNotNone(first)
            observability.record_applied(self.state)
            self.assertEqual(
                first, observability.snapshot(self.state, self.policy)["policy_applied_at"]
            )

    def test_real_config_path_from_runtime_is_a_string(self):
        path = self.state / "config.json"
        atomic_write(
            path,
            {
                "central_url": "https://central.example.com",
                "state_dir": str(self.state),
                "log_dir": str(self.state / "logs"),
                "identity_file": str(self.state / "identity.json"),
                "allowed_networks": ["8.8.8.0/24"],
                "allowed_dns_names": ["google.com"],
                "auto_public_targets": False,
            },
        )
        with patch(
            "agent_center.mtr_client.exchange", return_value={"ok": True, "schema_version": 1}
        ):
            self.assertEqual(
                "ALLOWED",
                observability.check(self.request, str(path), self.policy, "agent")["state"],
            )

    def test_standard_capabilities_share_authorization_with_executor(self):
        from agent_center.capabilities import KNOWN, execute
        from agent_center.config import Config
        from agent_center.policy import Policy

        self.policy["allowed_capabilities"] = list(KNOWN)
        parsed = Policy.parse(self.policy, "agent", KNOWN)
        path = self.state / "config.json"
        base = {
            "central_url": "https://central.example.com",
            "state_dir": str(self.state),
            "log_dir": str(self.state / "logs"),
            "identity_file": str(self.state / "identity.json"),
            "allowed_networks": ["8.8.8.0/24"],
            "allowed_dns_names": ["google.com"],
            "auto_public_targets": False,
        }
        for name in KNOWN:
            if name == mtr.CAPABILITY:
                continue
            for allowed in (True, False):
                if name == "agent_info" and not allowed:
                    continue
                with self.subTest(capability=name, allowed=allowed):
                    settings = self.settings | {
                        "allowed_networks": ["8.8.8.0/24"] if allowed else [],
                        "allowed_dns_names": ["google.com"] if allowed else [],
                    }
                    atomic_write(path, base)
                    atomic_write(self.state / "managed-settings.json", settings)
                    request = self.request | {
                        "capability": name,
                        "destination": "google.com" if name == "dns_lookup:v1" else "8.8.8.8",
                        "digest": observability.digest(observability.managed(self.state)),
                    }
                    config = Config.load(path)
                    handler = Mock(return_value={"fixture": True})
                    with (
                        patch("agent_center.capabilities.capability_available", return_value=True),
                        patch("agent_center.observability.capability_available", return_value=True),
                        patch.dict("agent_center.capabilities.HANDLERS", {name: handler}),
                    ):
                        decision = observability.check(request, str(path), self.policy, "agent")
                        handler.assert_not_called()
                        args = {"target": request["destination"]}
                        if name == "dns_lookup:v1":
                            args["record_type"] = "A"
                        if name == "tcp_connect:v1":
                            args["port"] = 443
                        if name == "http_probe:v1":
                            args["url"] = "https://8.8.8.8/"
                        actual = execute(
                            name,
                            args,
                            parsed,
                            config.allowed_networks,
                            config.allowed_dns_names,
                            config.auto_public_targets,
                        )
                        self.assertEqual(actual["ok"], decision["state"] == "ALLOWED")
                        if not allowed:
                            self.assertEqual("MANAGED_POLICY_DENIED", decision["reason"])
                            handler.assert_not_called()

    def test_changed_policy_during_config_loading_is_unknown(self):
        from agent_center.config import Config

        path = self.state / "config.json"
        atomic_write(
            path,
            {
                "central_url": "https://central.example.com",
                "state_dir": str(self.state),
                "log_dir": str(self.state / "logs"),
                "identity_file": str(self.state / "identity.json"),
                "allowed_networks": ["8.8.8.0/24"],
                "allowed_dns_names": ["google.com"],
            },
        )
        old_config = Config.load(path)
        changed = self.settings | {"revision": 2, "allowed_networks": []}
        atomic_write(self.state / "managed-settings.json", changed)
        request = self.request | {
            "revision": 2,
            "digest": observability.digest(observability.managed(self.state)),
        }
        with (
            patch("agent_center.observability.Config.load", return_value=old_config),
            patch(
                "agent_center.mtr_client.exchange",
                side_effect=AssertionError("Must not contact helper"),
            ),
        ):
            decision = observability.check(request, str(path), self.policy, "agent")
            self.assertEqual("UNKNOWN", decision["state"])
            self.assertEqual("POLICY_NOT_APPLIED", decision["reason"])

    def test_failed_communication_never_allows(self):
        with (
            patch("agent_center.observability.Config.load", return_value=self.config),
            patch(
                "agent_center.mtr_client.exchange", side_effect=mtr.MtrError("mtr_execution_failed")
            ),
        ):
            self.assertEqual(
                "UNKNOWN", observability.check(self.request, None, self.policy, "agent")["state"]
            )

    def test_disabled_tasks_never_allow(self):
        self.policy["tasks_enabled"] = False
        self.assertEqual(
            "POLICY_NOT_APPLIED", self.decision({"ok": True, "schema_version": 1})["reason"]
        )
