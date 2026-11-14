"""Fixed Debian operations for the approved MTR component only."""

import grp
import json
import os
import platform
import pwd
import subprocess
import sys
import tempfile
import time
from pathlib import Path

from ..state import read_private
from ..update.remote import trusted
from .mtr_catalog import DESTINATIONS, FILES
from .releases import ReleaseError

BOOTSTRAP_UNITS = Path("/etc/systemd/system")


class DebianComponents:
    operational = True

    def path(self, value):
        if value not in DESTINATIONS.values():
            raise ReleaseError("unsupported_component_path")
        return Path(value)

    def trusted(self, path):
        for parent in path.parents:
            if parent.exists() or parent.is_symlink():
                trusted(parent)
        if path.exists() or path.is_symlink():
            trusted(path)
            if not path.is_file():
                raise ReleaseError("unsafe_component_file")

    def command(self, *args, check=True):
        return subprocess.run(
            args,
            capture_output=True,
            text=True,
            check=check,
            timeout=25,
            env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL": "C"},
        )

    def wait_for_idle_helper(self):
        # Readiness uses short-lived socket instances too. Allow them to exit,
        # without stopping or cancelling a real probe or relaxing the busy gate.
        deadline = time.monotonic() + 2
        while True:
            busy = self.command(
                "/usr/bin/systemctl", "list-units", "agent-center-mtr@*.service",
                "--state=running", "--no-legend",
            ).stdout.strip()
            if not busy:
                return
            if time.monotonic() >= deadline:
                raise ReleaseError("component_busy")
            time.sleep(0.1)

    def precheck(self):
        if os.geteuid() != 0:
            raise ReleaseError("component_requires_lifecycle_service")
        system = platform.freedesktop_os_release()
        if (
            system.get("ID") != "debian"
            or system.get("VERSION_ID") != "13"
            or sys.version_info[:2] != (3, 13)
        ):
            raise ReleaseError("incompatible_platform")
        try:
            principal = pwd.getpwnam("agent-center-agent")
            helper = pwd.getpwnam("agent-center-mtr")
        except KeyError:
            raise ReleaseError("component_bootstrap_required") from None
        if (
            principal.pw_uid == 0
            or helper.pw_uid in (0, principal.pw_uid)
            or helper.pw_shell != "/usr/sbin/nologin"
            or helper.pw_dir != "/nonexistent"
            or helper.pw_gid in (0, principal.pw_gid)
            or helper.pw_gid != grp.getgrnam("agent-center-mtr").gr_gid
            or set(os.getgrouplist("agent-center-mtr", helper.pw_gid)) != {helper.pw_gid}
        ):
            raise ReleaseError("invalid_component_account")
        for prop, expected in [
            ("User", "agent-center-agent"),
            ("NoNewPrivileges", "yes"),
            ("PrivateDevices", "yes"),
            ("CapabilityBoundingSet", ""),
            ("AmbientCapabilities", ""),
        ]:
            value = self.command(
                "/usr/bin/systemctl", "show", "agent-center-agent.service", "--value", "-p", prop
            ).stdout.strip()
            if value != expected:
                raise ReleaseError("principal_hardening_changed")
        self.wait_for_idle_helper()
        for name in ("agent-center-mtr.socket", "agent-center-mtr@.service"):
            link = Path("/etc/systemd/system") / name
            if not link.is_symlink() or os.readlink(link) != "/opt/agent-center-mtr/" + name:
                raise ReleaseError("component_bootstrap_required")
        for name in ["agent-center-mtr.socket.d", "agent-center-mtr@.service.d"]:
            directory = Path("/etc/systemd/system") / name
            if directory.exists() and any(directory.iterdir()):
                raise ReleaseError("component_dropins_require_review")
        for name in FILES:
            path = self.path(DESTINATIONS[name])
            self.trusted(path)
            if path.exists() and path.read_bytes() != FILES[name]:
                raise ReleaseError("component_files_require_review")
        for directory in ("/run/systemd/system", "/usr/lib/systemd/system"):
            for name in ("agent-center-mtr.socket.d", "agent-center-mtr@.service.d"):
                path = Path(directory) / name
                if path.exists() and any(path.iterdir()):
                    raise ReleaseError("component_dropins_require_review")
        if not Path("/usr/bin/mtr-packet").is_file():
            raise ReleaseError("component_dependency_missing")
        if self.command("/usr/bin/mtr", "--version").stdout.splitlines()[0].strip() != "mtr 0.95":
            raise ReleaseError("component_dependency_incompatible")
        envelope = read_private(trusted(Path("/etc/agent-center-agent/lifecycle-policy.json")))
        base_path = Path("/etc/agent-center-agent/config.json")
        # Base config can be owned by the Agent; this never authorizes an
        # administrative privilege. Root envelope and ceiling remain independent.
        trusted(base_path.parent)
        fd = os.open(base_path, os.O_RDONLY | os.O_NOFOLLOW)
        try:
            info = os.fstat(fd)
            if (
                info.st_uid not in (0, principal.pw_uid)
                or info.st_mode & 0o077
                or info.st_size > 1048576
            ):
                raise ReleaseError("unsafe_base_policy")
            with os.fdopen(fd, closefd=False) as source:
                base = json.load(source)
        finally:
            os.close(fd)
        policy = read_private(trusted(Path("/etc/agent-center-mtr/policy.json")))
        return envelope, base, policy

    def service_state(self):
        result = self.command(
            "/usr/bin/systemctl",
            "show",
            "agent-center-mtr.socket",
            "-p",
            "LoadState",
            "-p",
            "ActiveState",
            "-p",
            "UnitFileState",
            check=False,
        )
        values = dict(line.split("=", 1) for line in result.stdout.splitlines() if "=" in line)
        if values.get("LoadState") == "not-found":
            return {"active": False, "enabled": False, "runtime": False}
        if result.returncode or values.get("LoadState") != "loaded":
            raise ReleaseError("component_environment_unavailable")
        if values.get("ActiveState") not in ("active", "inactive", "failed"):
            raise ReleaseError("component_busy")
        return {
            "active": values["ActiveState"] == "active",
            "enabled": values.get("UnitFileState") in ("enabled", "enabled-runtime"),
            "runtime": values.get("UnitFileState") == "enabled-runtime",
        }

    def write(self, value, data, mode, uid, gid):
        path = self.path(value)
        self.trusted(path)
        path.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
        fd, temporary = tempfile.mkstemp(prefix=".component-", dir=path.parent)
        try:
            with os.fdopen(fd, "wb") as out:
                os.fchmod(out.fileno(), mode)
                os.fchown(out.fileno(), uid, gid)
                out.write(data)
                out.flush()
                os.fsync(out.fileno())
            os.replace(temporary, path)
            directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
            try:
                os.fsync(directory)
            finally:
                os.close(directory)
        finally:
            if os.path.exists(temporary):
                os.unlink(temporary)

    def remove(self, value):
        path = self.path(value)
        self.trusted(path)
        path.unlink(missing_ok=True)

    def activate(self):
        self.command(
            "/usr/bin/systemd-analyze",
            "verify",
            "/opt/agent-center-mtr/agent-center-mtr.socket",
            "/opt/agent-center-mtr/agent-center-mtr@.service",
        )
        self.command("/usr/bin/systemctl", "daemon-reload")
        self.command("/usr/bin/systemctl", "enable", "--now", "agent-center-mtr.socket")

    def restore_bootstrap_links(self):
        # systemctl disable also removes externally linked unit aliases. Restore
        # only the two fixed, administrator-approved aliases while files exist,
        # before rollback can remove a newly installed component's files.
        for name in ("agent-center-mtr.socket", "agent-center-mtr@.service"):
            link = BOOTSTRAP_UNITS / name
            target = "/opt/agent-center-mtr/" + name
            if link.exists() or link.is_symlink():
                if not link.is_symlink() or os.readlink(link) != target:
                    raise ReleaseError("component_bootstrap_required")
            else:
                self.command("/usr/bin/systemctl", "link", target)
                if not link.is_symlink() or os.readlink(link) != target:
                    raise ReleaseError("component_bootstrap_required")

    def stop(self):
        stopped = self.command(
            "/usr/bin/systemctl", "disable", "--now", "agent-center-mtr.socket", check=False
        )
        if stopped.returncode and self.service_state()["active"]:
            raise ReleaseError("component_stop_failed")
        self.command("/usr/bin/systemctl", "stop", "agent-center-mtr@*.service", check=False)
        self.restore_bootstrap_links()
        busy = self.command(
            "/usr/bin/systemctl",
            "list-units",
            "agent-center-mtr@*.service",
            "--state=running",
            "--no-legend",
        ).stdout.strip()
        if busy:
            raise ReleaseError("component_stop_failed")

    def health(self):
        principal = pwd.getpwnam("agent-center-agent")
        result = subprocess.run(
            ["/usr/bin/python3", "-I", "/opt/agent-center-mtr/check-helper.py"],
            user=principal.pw_uid,
            group=principal.pw_gid,
            extra_groups=[],
            capture_output=True,
            text=True,
            check=True,
            timeout=25,
            env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL": "C"},
        )
        return json.loads(result.stdout).get("mtr_helper_ready") is True

    def restore(self, state):
        self.command("/usr/bin/systemctl", "daemon-reload")
        if state["enabled"]:
            if state.get("runtime", False):
                self.command("/usr/bin/systemctl", "enable", "--runtime", "agent-center-mtr.socket")
            else:
                self.command("/usr/bin/systemctl", "enable", "agent-center-mtr.socket")
        if state["active"]:
            self.command("/usr/bin/systemctl", "start", "agent-center-mtr.socket")

    def restored_health(self, state):
        return self.service_state() == state and (not state["active"] or self.health())
