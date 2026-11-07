"""Declarative, closed component operations. No paths or commands from a release."""

import hashlib
import io
import ipaddress
import os
import re
import zipfile
from pathlib import Path

from ..state import atomic_write, read_private
from .mtr_catalog import DESTINATIONS, FILES
from .releases import ReleaseError

PROFILE = "network-raw-helper"


def specification(value, runtime_version):
    if not isinstance(value, list) or len(value) > 1:
        raise ReleaseError("unsupported_components")
    if not value:
        return []
    item = value[0]
    expected = {"id", "version", "profile", "dependencies", "helper_sha256"}
    if not isinstance(item, dict) or set(item) != expected:
        raise ReleaseError("invalid_component")
    if (
        item["id"] != "mtr"
        or item["profile"] != PROFILE
        or not isinstance(item["version"], str)
        or len(item["version"]) > 32
        or not re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+", item["version"])
        or item["dependencies"] != ["python3.13", "mtr0.95"]
        or not isinstance(item["helper_sha256"], str)
        or len(item["helper_sha256"]) != 64
    ):
        raise ReleaseError("unsupported_component")
    if tuple(map(int, runtime_version.split("."))) < (0, 6, 2):
        raise ReleaseError("incompatible_component")
    return value


def helper_from_runtime(artifact):
    # Identical deterministic zipapp packaging, with the fixed helper entrypoint.
    output = io.BytesIO()
    output.write(b"#!/usr/bin/env python3\n")
    with zipfile.ZipFile(io.BytesIO(artifact)) as source, zipfile.ZipFile(output, "w") as target:
        for member in source.infolist():
            data = source.read(member)
            if member.filename == "__main__.py":
                data = b"from agent_center.mtr_helper import main\nraise SystemExit(main())\n"
            target.writestr(member, data)
    return output.getvalue()


def ceiling(base, policy):
    if not isinstance(base, dict) or any(
        not isinstance(base.get(key, []), list) for key in ("allowed_dns_names", "allowed_networks")
    ):
        raise ReleaseError("invalid_component_policy")
    if (
        not isinstance(policy, dict)
        or set(policy) != {"schema_version", "allowed_dns_names", "allowed_networks"}
        or type(policy["schema_version"]) is not int
        or policy["schema_version"] != 1
    ):
        raise ReleaseError("invalid_component_policy")
    for key in ("allowed_dns_names", "allowed_networks"):
        if not isinstance(policy[key], list) or len(policy[key]) > 256:
            raise ReleaseError("invalid_component_policy")
    if not (policy["allowed_dns_names"] or policy["allowed_networks"]):
        raise ReleaseError("empty_component_policy")
    if any(
        not isinstance(n, str) or "*" in n or n not in base.get("allowed_dns_names", [])
        for n in policy["allowed_dns_names"]
    ):
        raise ReleaseError("component_policy_exceeds_base")
    try:
        for value in policy["allowed_networks"]:
            if not isinstance(value, str):
                raise ValueError()
            net = ipaddress.ip_network(value)
            if net.prefixlen == 0 or not any(
                net.version == other.version and net.subnet_of(other)
                for other in map(ipaddress.ip_network, base.get("allowed_networks", []))
            ):
                raise ValueError()
    except (ValueError, TypeError):
        raise ReleaseError("component_policy_exceeds_base") from None


class Components:
    def __init__(self, system):
        self.system = system
        self.plan = None

    def precheck(self, release, artifact):
        specs = specification(release.get("components", []), release["version"])
        if not specs:
            self.plan = {}
            return
        envelope, base, policy = self.system.precheck()
        if (
            not isinstance(envelope, dict)
            or type(envelope.get("schema_version")) is not int
            or not isinstance(envelope.get("profiles"), list)
            or not isinstance(envelope.get("components"), list)
            or any(
                not isinstance(value, str)
                for value in [*envelope["profiles"], *envelope["components"]]
            )
        ):
            raise ReleaseError("invalid_component_policy")
        if (
            envelope.get("schema_version") != 1
            or envelope.get("device_operations_frozen") is not True
            or base.get("device_operations_frozen", True) is not True
            or "mtr" not in envelope.get("components", [])
            or PROFILE not in envelope.get("profiles", [])
        ):
            raise ReleaseError("component_permission_not_authorized")
        ceiling(base, policy)
        helper = helper_from_runtime(artifact)
        if hashlib.sha256(helper).hexdigest() != specs[0]["helper_sha256"]:
            raise ReleaseError("component_hash_mismatch")
        self.plan = {**FILES, "helper.pyz": helper}

    def snapshot(self, directory):
        if not self.plan:
            return
        saved = {}
        for name in self.plan:
            path = self.system.path(DESTINATIONS[name])
            self.system.trusted(path)
            if path.exists():
                st = path.stat()
                if st.st_size > 8389632:
                    raise ReleaseError("component_backup_invalid")
                data = path.read_bytes()
                blobs = Path(directory) / "component-files"
                blobs.mkdir(mode=0o700, parents=True, exist_ok=True)
                with (blobs / name).open("xb") as out:
                    os.fchmod(out.fileno(), 0o600)
                    out.write(data)
                    out.flush()
                    os.fsync(out.fileno())
                directory_fd = os.open(blobs, os.O_RDONLY | os.O_DIRECTORY)
                try:
                    os.fsync(directory_fd)
                finally:
                    os.close(directory_fd)
                saved[name] = {
                    "sha256": hashlib.sha256(data).hexdigest(),
                    "size": len(data),
                    "mode": st.st_mode & 0o777,
                    "uid": st.st_uid,
                    "gid": st.st_gid,
                }
            else:
                saved[name] = None
        atomic_write(
            Path(directory) / "components.json",
            {"files": saved, "service": self.system.service_state()},
        )

    def install(self):
        if not self.plan:
            return
        for name, content in self.plan.items():
            self.system.write(DESTINATIONS[name], content, 0o644, 0, 0)
        self.system.activate()

    def health(self):
        return not self.plan or self.system.health() is True

    def backup_bytes(self, directory, name, metadata):
        path = Path(directory) / "component-files" / name
        info = path.lstat()
        if (
            path.is_symlink()
            or not path.is_file()
            or info.st_uid != os.geteuid()
            or info.st_mode & 0o077
            or info.st_size != metadata["size"]
            or info.st_size > 8389632
        ):
            raise ReleaseError("component_backup_invalid")
        data = path.read_bytes()
        if hashlib.sha256(data).hexdigest() != metadata["sha256"]:
            raise ReleaseError("component_backup_invalid")
        return data

    def rollback(self, directory):
        marker = Path(directory) / "components.json"
        if not marker.exists():
            return
        backup = read_private(marker)
        if set(backup["files"]) != set(DESTINATIONS):
            raise ReleaseError("invalid_component_backup")
        self.system.stop()
        for name, old in backup["files"].items():
            if old is None:
                self.system.remove(DESTINATIONS[name])
            else:
                self.system.write(
                    DESTINATIONS[name],
                    self.backup_bytes(directory, name, old),
                    old["mode"],
                    old["uid"],
                    old["gid"],
                )
        self.system.restore(backup["service"])

    def rollback_health(self, directory):
        marker = Path(directory) / "components.json"
        if not marker.exists():
            return True
        backup = read_private(marker)
        for name, old in backup["files"].items():
            path = self.system.path(DESTINATIONS[name])
            if old is None:
                if path.exists() or path.is_symlink():
                    return False
            elif (
                not path.is_file()
                or path.read_bytes() != self.backup_bytes(directory, name, old)
                or path.stat().st_mode & 0o777 != old["mode"]
                or path.stat().st_uid != old["uid"]
                or path.stat().st_gid != old["gid"]
            ):
                return False
        return self.system.restored_health(backup["service"])
