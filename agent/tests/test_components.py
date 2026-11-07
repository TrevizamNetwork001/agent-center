import hashlib
import importlib.util
import tempfile
import unittest
from pathlib import Path

from agent_center.lifecycle.components import (
    Components,
    ceiling,
    helper_from_runtime,
    specification,
)
from agent_center.lifecycle.mtr_catalog import DESTINATIONS
from agent_center.lifecycle.releases import ReleaseError


class System:
    operational = False
    """Filesystem operations are real; only the service boundary is simulated."""

    def __init__(self, root):
        self.root = Path(root)
        self.active = False
        self.envelope = {
            "schema_version": 1,
            "device_operations_frozen": True,
            "profiles": ["network-raw-helper"],
            "components": ["mtr"],
        }
        self.base = {"allowed_dns_names": ["google.com"], "allowed_networks": []}
        self.policy = {"schema_version": 1, **self.base}
        self.writes = 0
        self.fail_at = None

    def precheck(self):
        return self.envelope, self.base, self.policy

    def path(self, value):
        assert value in DESTINATIONS.values()
        return self.root / value.lstrip("/")

    def trusted(self, path):
        assert not path.is_symlink()

    def service_state(self):
        return {"active": self.active, "enabled": self.active}

    def write(self, value, data, mode, uid, gid):
        self.writes += 1
        if self.writes == self.fail_at:
            raise OSError("injected installation failure")
        path = self.path(value)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
        path.chmod(mode)

    def remove(self, value):
        self.path(value).unlink(missing_ok=True)

    def activate(self):
        self.active = True

    def stop(self):
        self.active = False

    def health(self):
        return self.active

    def restore(self, state):
        self.active = state["active"]

    def restored_health(self, state):
        return self.service_state() == state


class ComponentTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.directory = tempfile.TemporaryDirectory()
        root = Path(cls.directory.name)
        source = Path(__file__).resolve().parents[1] / "packaging/build.py"
        spec = importlib.util.spec_from_file_location("builder", source)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        module.build(root / "runtime.pyz")
        module.build(root / "helper.pyz", "mtr-helper")
        cls.runtime = (root / "runtime.pyz").read_bytes()
        cls.helper = (root / "helper.pyz").read_bytes()
        cls.release = {
            "version": "0.6.2",
            "components": [
                {
                    "id": "mtr",
                    "version": "0.6.2",
                    "profile": "network-raw-helper",
                    "dependencies": ["python3.13", "mtr0.95"],
                    "helper_sha256": hashlib.sha256(cls.helper).hexdigest(),
                }
            ],
        }

    @classmethod
    def tearDownClass(cls):
        cls.directory.cleanup()

    def test_derived_helper_is_identical_to_approved_builder(self):
        self.assertEqual(helper_from_runtime(self.runtime), self.helper)

    def test_install_update_and_rollback_only_known_component_files(self):
        with tempfile.TemporaryDirectory() as directory:
            system = System(directory)
            component = Components(system)
            component.precheck(self.release, self.runtime)
            backup = Path(directory) / "backup"
            component.snapshot(backup)
            component.install()
            self.assertTrue(component.health())
            self.assertEqual(system.path(DESTINATIONS["helper.pyz"]).read_bytes(), self.helper)
            component.rollback(backup)
            self.assertTrue(component.rollback_health(backup))
            for path in DESTINATIONS.values():
                self.assertFalse(system.path(path).exists())

    def test_partial_installation_rolls_back(self):
        with tempfile.TemporaryDirectory() as directory:
            system = System(directory)
            component = Components(system)
            component.precheck(self.release, self.runtime)
            component.snapshot(Path(directory) / "backup")
            system.fail_at = 2
            with self.assertRaises(OSError):
                component.install()
            component.rollback(Path(directory) / "backup")
            self.assertTrue(component.rollback_health(Path(directory) / "backup"))

    def test_unapproved_profile_and_acl_expansion_fail_before_writes(self):
        with tempfile.TemporaryDirectory() as directory:
            system = System(directory)
            system.envelope["profiles"] = []
            with self.assertRaisesRegex(ReleaseError, "permission_not_authorized"):
                Components(system).precheck(self.release, self.runtime)
            self.assertEqual(system.writes, 0)
            system.envelope["profiles"] = ["network-raw-helper"]
            system.policy["allowed_dns_names"] = ["not-approved.example"]
            with self.assertRaisesRegex(ReleaseError, "policy_exceeds_base"):
                Components(system).precheck(self.release, self.runtime)
            self.assertEqual(system.writes, 0)

    def test_unknown_component_and_global_ceiling_are_rejected(self):
        with self.assertRaises(ReleaseError):
            specification([{"id": "capture"}], "0.6.2")
        with self.assertRaises(ReleaseError):
            ceiling(
                {"allowed_networks": ["0.0.0.0/0"]},
                {"schema_version": 1, "allowed_dns_names": [], "allowed_networks": ["0.0.0.0/0"]},
            )

    def test_missing_dependency_refuses_before_filesystem_changes(self):
        with tempfile.TemporaryDirectory() as directory:

            class MissingDependency(System):
                def precheck(self):
                    raise ReleaseError("component_dependency_missing")

            system = MissingDependency(directory)
            with self.assertRaisesRegex(ReleaseError, "component_dependency_missing"):
                Components(system).precheck(self.release, self.runtime)
            self.assertEqual(system.writes, 0)

    def test_update_restores_previous_helper_and_service_on_rollback(self):
        with tempfile.TemporaryDirectory() as directory:
            system = System(directory)
            component = Components(system)
            component.precheck(self.release, self.runtime)
            component.snapshot(Path(directory) / "initial")
            component.install()
            old = system.path(DESTINATIONS["helper.pyz"]).read_bytes()
            component.snapshot(Path(directory) / "update")
            system.write(DESTINATIONS["helper.pyz"], b"partial fixture", 0o644, 0, 0)
            component.rollback(Path(directory) / "update")
            self.assertEqual(system.path(DESTINATIONS["helper.pyz"]).read_bytes(), old)
            self.assertTrue(component.rollback_health(Path(directory) / "update"))
            self.assertTrue(system.active)

    def test_native_health_drops_to_agent_credentials_without_shell_or_pam(self):
        from types import SimpleNamespace
        from unittest.mock import patch

        from agent_center.lifecycle.debian_components import DebianComponents

        with (
            patch(
                "agent_center.lifecycle.debian_components.pwd.getpwnam",
                return_value=SimpleNamespace(pw_uid=987, pw_gid=987),
            ),
            patch(
                "agent_center.lifecycle.debian_components.subprocess.run",
                return_value=SimpleNamespace(stdout='{"mtr_helper_ready":true}'),
            ) as run,
        ):
            self.assertTrue(DebianComponents().health())
            self.assertEqual(run.call_args.kwargs["user"], 987)
            self.assertEqual(run.call_args.kwargs["group"], 987)
            self.assertEqual(run.call_args.kwargs["extra_groups"], [])
            self.assertEqual(
                run.call_args.args[0],
                ["/usr/bin/python3", "-I", "/opt/agent-center-mtr/check-helper.py"],
            )
            self.assertNotIn("shell", run.call_args.kwargs)

    def test_large_component_backup_is_not_embedded_in_the_state_json(self):
        with tempfile.TemporaryDirectory() as directory:
            system = System(directory)
            component = Components(system)
            component.precheck(self.release, self.runtime)
            original = b"x" * 1100000
            system.write(DESTINATIONS["helper.pyz"], original, 0o644, 0, 0)
            backup = Path(directory) / "backup"
            component.snapshot(backup)
            self.assertLess((backup / "components.json").stat().st_size, 4096)
            component.install()
            component.rollback(backup)
            self.assertEqual(system.path(DESTINATIONS["helper.pyz"]).read_bytes(), original)
            self.assertTrue(component.rollback_health(backup))

    def test_native_bus_failure_is_not_reported_as_an_inactive_service(self):
        from types import SimpleNamespace
        from unittest.mock import patch

        from agent_center.lifecycle.debian_components import DebianComponents

        system = DebianComponents()
        with patch.object(system, "command", return_value=SimpleNamespace(returncode=1, stdout="")):
            with self.assertRaisesRegex(ReleaseError, "component_environment_unavailable"):
                system.service_state()

    def test_unprivileged_core_release_has_no_component_operations(self):
        with tempfile.TemporaryDirectory() as directory:
            system = System(directory)
            component = Components(system)
            component.precheck({"version": "0.6.2"}, self.runtime)
            component.snapshot(Path(directory) / "backup")
            component.install()
            self.assertTrue(component.health())
            self.assertEqual(system.writes, 0)
