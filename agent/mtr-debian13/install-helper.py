"""Explicit offline Debian 13 preparation. Never starts a service or changes Agent config/runtime."""

import argparse
import grp
import hashlib
import ipaddress
import json
import os
import pathlib
import pwd
import re
import shutil
import subprocess
import sys
import uuid

ROOT = pathlib.Path(__file__).resolve().parent
FILES = {
    "check-helper.py": "/opt/agent-center-mtr/check-helper.py",
    "helper.pyz": "/opt/agent-center-mtr/helper.pyz",
    "agent-center-mtr.socket": "/etc/systemd/system/agent-center-mtr.socket",
    "agent-center-mtr@.service": "/etc/systemd/system/agent-center-mtr@.service",
    "sysusers.conf": "/opt/agent-center-mtr/sysusers.conf",
}


def command(args):
    return subprocess.run(
        args,
        check=True,
        capture_output=True,
        text=True,
        env={"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL": "C"},
        timeout=20,
    ).stdout.strip()


def trusted(path, private=False):
    path = pathlib.Path(path)
    for parent in [path, *path.parents]:
        if parent.is_symlink():
            raise ValueError("symlink_refused")
        if parent.exists():
            s = parent.stat()
            if s.st_uid != 0 or s.st_mode & 0o022:
                raise ValueError("untrusted_path")
    if private and path.stat().st_mode & 0o077:
        raise ValueError("private_policy_required")
    return path


def guard():
    values = {
        key: command(
            ["/usr/bin/systemctl", "show", "agent-center-agent.service", "--value", "-p", key]
        )
        for key in (
            "User",
            "NoNewPrivileges",
            "PrivateDevices",
            "CapabilityBoundingSet",
            "AmbientCapabilities",
        )
    }
    if values != {
        "User": "agent-center-agent",
        "NoNewPrivileges": "yes",
        "PrivateDevices": "yes",
        "CapabilityBoundingSet": "",
        "AmbientCapabilities": "",
    }:
        raise ValueError("principal_hardening_precondition_failed")
    return values


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--policy", required=True, type=pathlib.Path)
    args = parser.parse_args()
    if os.geteuid() != 0:
        raise ValueError("requires_root_installation_only")
    release = pathlib.Path("/etc/os-release").read_text()
    if (
        not re.search(r"^ID=debian$", release, re.M)
        or not re.search(r'^VERSION_ID="?13"?$', release, re.M)
        or sys.version_info[:2] != (3, 13)
    ):
        raise ValueError("requires_debian13_python313")
    if not pathlib.Path("/usr/bin/mtr").is_file():
        raise ValueError("dependency_mtr_missing")
    before = guard()
    manifest = json.loads(trusted(ROOT / "manifest.json").read_text())
    for name, digest in manifest.items():
        if "/" in name or name not in {
            *FILES,
            "agent.pyz",
            "install-helper.py",
            "check-helper.py",
            "policy.example.json",
            "INSTALL.md",
        }:
            raise ValueError("invalid_manifest")
        source = trusted(ROOT / name)
        if not source.is_file() or hashlib.sha256(source.read_bytes()).hexdigest() != digest:
            raise ValueError("artifact_hash_mismatch")
    if set(manifest) != {
        *FILES,
        "agent.pyz",
        "install-helper.py",
        "check-helper.py",
        "policy.example.json",
        "INSTALL.md",
    }:
        raise ValueError("manifest_incomplete")
    policy = json.loads(trusted(args.policy, private=True).read_text())
    if (
        not isinstance(policy, dict)
        or set(policy) != {"schema_version", "allowed_dns_names", "allowed_networks"}
        or type(policy["schema_version"]) is not int
        or policy["schema_version"] != 1
    ):
        raise ValueError("invalid_policy")
    local_path = pathlib.Path("/etc/agent-center-agent/config.json")
    trusted(local_path.parent)
    if local_path.stat().st_uid not in (0, pwd.getpwnam("agent-center-agent").pw_uid):
        raise ValueError("unsafe_local_config_owner")
    if local_path.is_symlink() or local_path.stat().st_mode & 0o077:
        raise ValueError("unsafe_local_config")
    local_bytes = local_path.read_bytes()
    local = json.loads(local_bytes)
    if any(
        not isinstance(policy[k], list) or len(policy[k]) > 256
        for k in ("allowed_dns_names", "allowed_networks")
    ) or not any(policy[k] for k in ("allowed_dns_names", "allowed_networks")):
        raise ValueError("empty_or_invalid_ceiling")
    for name in policy["allowed_dns_names"]:
        if (
            not isinstance(name, str)
            or not re.fullmatch(r"[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?", name)
            or name not in local.get("allowed_dns_names", [])
        ):
            raise ValueError("ceiling_exceeds_local_policy")
    for network in policy["allowed_networks"]:
        if not isinstance(network, str):
            raise ValueError("invalid_policy")
        net = ipaddress.ip_network(network)
        if not any(
            net.version == other.version and net.subnet_of(other)
            for other in map(ipaddress.ip_network, local.get("allowed_networks", []))
        ):
            raise ValueError("ceiling_exceeds_local_policy")
    principal = pwd.getpwnam("agent-center-agent")
    if principal.pw_uid == 0:
        raise ValueError("invalid_principal_uid")
    try:
        helper = pwd.getpwnam("agent-center-mtr")
    except KeyError:
        helper = None
    if helper is not None:
        if (
            helper.pw_uid in (0, principal.pw_uid)
            or helper.pw_shell != "/usr/sbin/nologin"
            or helper.pw_dir != "/nonexistent"
        ):
            raise ValueError("unexpected_existing_helper_account")
        group = grp.getgrnam("agent-center-mtr")
        if (
            helper.pw_gid != group.gr_gid
            or group.gr_gid == 0
            or set(os.getgrouplist("agent-center-mtr", group.gr_gid)) != {group.gr_gid}
        ):
            raise ValueError("unexpected_existing_helper_groups")
    targets = [pathlib.Path(p) for p in FILES.values()] + [
        pathlib.Path("/etc/agent-center-mtr/policy.json")
    ]
    for target in targets:
        trusted(target.parent)
        if target.exists() or target.is_symlink():
            raise ValueError("existing_installation_refused")
    backup = pathlib.Path("/var/lib/agent-center-updater/mtr-preparations") / str(uuid.uuid4())
    trusted(backup.parent)
    backup.mkdir(parents=True, mode=0o700)
    unit_path = pathlib.Path(
        command(
            [
                "/usr/bin/systemctl",
                "show",
                "agent-center-agent.service",
                "--value",
                "-p",
                "FragmentPath",
            ]
        )
    )
    snapshot = {
        "principal_properties": before,
        "config_sha256": hashlib.sha256(local_bytes).hexdigest(),
        "unit_path": str(unit_path),
        "unit_sha256": hashlib.sha256(unit_path.read_bytes()).hexdigest(),
        "created_files": [str(p) for p in targets],
    }
    (backup / "guard.json").write_text(json.dumps(snapshot, indent=2) + "\n")
    created = []
    try:
        for name, destination in FILES.items():
            dest = pathlib.Path(destination)
            dest.parent.mkdir(parents=True, exist_ok=True, mode=0o755)
            shutil.copyfile(ROOT / name, dest)
            dest.chmod(0o644)
            os.chown(dest, 0, 0)
            created.append(dest)
        destination = targets[-1]
        destination.parent.mkdir(mode=0o755, exist_ok=True)
        destination.write_text(json.dumps(policy, indent=2) + "\n")
        destination.chmod(0o600)
        created.append(destination)
        command(["/usr/bin/systemd-sysusers", "/opt/agent-center-mtr/sysusers.conf"])
        command(
            [
                "/usr/bin/systemd-analyze",
                "verify",
                "/etc/systemd/system/agent-center-mtr.socket",
                "/etc/systemd/system/agent-center-mtr@.service",
            ]
        )
        command(["/usr/bin/systemctl", "daemon-reload"])
        if (
            guard() != before
            or local_path.read_bytes() != local_bytes
            or hashlib.sha256(unit_path.read_bytes()).hexdigest() != snapshot["unit_sha256"]
        ):
            raise ValueError("principal_changed")
    except Exception:
        for target in reversed(created):
            target.unlink(missing_ok=True)
        command(["/usr/bin/systemctl", "daemon-reload"])
        raise
    print(
        json.dumps(
            {"prepared": True, "started": False, "principal_modified": False, "backup": str(backup)}
        )
    )


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        reason = (
            str(error)
            if isinstance(error, ValueError) and re.fullmatch(r"[a-z0-9_]+", str(error))
            else "preparation_refused_or_failed"
        )
        print(json.dumps({"prepared": False, "error": reason, "secrets_logged": False}))
        raise SystemExit(2)
