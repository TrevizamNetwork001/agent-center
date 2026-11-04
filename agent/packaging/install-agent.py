#!/usr/bin/env python3
"""Verified offline installer. No network downloads or unverified shell scripts."""

import argparse
import hashlib
import json
import os
import platform
import pwd
import shutil
import subprocess
import sys
from pathlib import Path
from urllib.parse import urlsplit


def digest(path, expected):
    if (
        path.is_symlink()
        or not path.is_file()
        or hashlib.sha256(path.read_bytes()).hexdigest() != expected
    ):
        raise ValueError("artifact_integrity_failed")


def run(argv):
    subprocess.run(argv, check=True)


def main():
    p = argparse.ArgumentParser()
    p.add_argument("--kit", type=Path, required=True)
    p.add_argument("--central-url", required=True)
    p.add_argument("--manifest-sha256", required=True)
    p.add_argument("--check", action="store_true")
    p.add_argument("--lab-root", type=Path)
    p.add_argument("--enable-remote-updates", action="store_true")
    p.add_argument("--ca-file", type=Path)
    p.add_argument("--ca-sha256")
    a = p.parse_args()
    os.umask(0o077)
    try:
        if (
            sys.version_info[:2] != (3, 13)
            or sys.implementation.name != "cpython"
            or platform.system() != "Linux"
        ):
            raise ValueError("requires_linux_cpython_313")
        distro = platform.freedesktop_os_release()
        if distro.get("ID") != "debian" or distro.get("VERSION_ID") not in ("12", "13"):
            raise ValueError("unsupported_system")
        url = urlsplit(a.central_url)
        if (
            url.scheme != "https"
            or not url.hostname
            or url.username
            or url.password
            or url.query
            or url.fragment
            or url.path not in ("", "/")
        ):
            raise ValueError("https_origin_required")
        manifest = a.kit / "manifest.json"
        digest(manifest, a.manifest_sha256)
        files = json.loads(manifest.read_text())
        required = {
            "agent.pyz",
            "updater.pyz",
            "remote-updater.pyz",
            "catalog.json",
            "agent-center-agent.service",
            "agent-center-updater.service",
            "agent-center-updater.timer",
            "logrotate.conf",
            "update-agent.sh",
            "rollback-agent.sh",
            "complete-enrollment.sh",
        }
        if set(files) != required:
            raise ValueError("invalid_kit_manifest")
        for name, sha in files.items():
            digest(a.kit / name, sha)
        catalog = json.loads((a.kit / "catalog.json").read_text())
        if (
            len(catalog) != 1
            or catalog[0]["sha256"] != files["agent.pyz"]
            or catalog[0]["device_operations_frozen"] is not True
        ):
            raise ValueError("invalid_catalog")
        if a.ca_file:
            digest(a.ca_file, a.ca_sha256)
        root = a.lab_root.resolve() if a.lab_root else Path("/")
        if a.lab_root and (not root.is_dir() or any(root.iterdir())):
            raise ValueError("lab_root_must_be_empty")
        base = root / "opt/agent-center-agent"
        config = root / "etc/agent-center-agent"
        state = root / "var/lib/agent-center-agent"
        logs = root / "var/log/agent-center-agent"
        for path in (base, config, state, logs):
            if path.exists() or path.is_symlink():
                raise ValueError("existing_installation_requires_update_procedure")
        if a.check:
            print(
                json.dumps(
                    {
                        "validated": True,
                        "python_abi": sys.implementation.cache_tag,
                        "runtime_dependencies": [],
                        "install": False,
                    }
                )
            )
            return 0
        if not a.lab_root and os.geteuid() != 0:
            raise ValueError("installation_requires_root")
        # All integrity/system checks precede mutations. Failures preserve installation evidence.
        account = None
        if not a.lab_root:
            if not Path("/run/systemd/system").is_dir():
                raise ValueError("systemd_required")
            try:
                pwd.getpwnam("agent-center-agent")
                raise ValueError("dedicated_account_already_exists_review_required")
            except KeyError:
                pass
            run(
                [
                    "/usr/sbin/useradd",
                    "--system",
                    "--user-group",
                    "--home-dir",
                    "/var/lib/agent-center-agent",
                    "--shell",
                    "/usr/sbin/nologin",
                    "agent-center-agent",
                ]
            )
            account = pwd.getpwnam("agent-center-agent")
        for path in (
            base,
            base / "runtime",
            base / "releases",
            config,
            state,
            logs,
            root / "var/lib/agent-center-updater",
        ):
            path.mkdir(
                parents=True,
                mode=0o755
                if path in (base, base / "runtime", base / "releases", config)
                else 0o700,
            )
        for name in (
            "updater.pyz",
            "remote-updater.pyz",
            "update-agent.sh",
            "rollback-agent.sh",
            "complete-enrollment.sh",
        ):
            shutil.copyfile(a.kit / name, base / name)
            (base / name).chmod(0o755 if name.endswith(".sh") else 0o644)
        shutil.copyfile(a.kit / "agent.pyz", base / "runtime/agent.pyz")
        (base / "runtime/agent.pyz").chmod(0o644)
        shutil.copyfile(a.kit / "agent.pyz", base / "releases" / (files["agent.pyz"] + ".pyz"))
        (base / "releases" / (files["agent.pyz"] + ".pyz")).chmod(0o644)
        shutil.copyfile(a.kit / "catalog.json", base / "releases/catalog.json")
        (base / "releases/catalog.json").chmod(0o644)
        runtime = dict(
            central_url=a.central_url.rstrip("/"),
            state_dir="/var/lib/agent-center-agent",
            log_dir="/var/log/agent-center-agent",
            identity_file="/var/lib/agent-center-agent/identity.json",
            device_operations_frozen=True,
            allowed_networks=[],
            allowed_dns_names=[],
        )
        (config / "config.json").write_text(json.dumps(runtime))
        (config / "config.json").chmod(0o600)
        (config / "operations.json").write_text(
            json.dumps({"central_url": a.central_url.rstrip("/")})
        )
        (config / "operations.json").chmod(0o600)
        units = root / "etc/systemd/system"
        units.mkdir(parents=True, exist_ok=True)
        for name in (
            "agent-center-agent.service",
            "agent-center-updater.service",
            "agent-center-updater.timer",
        ):
            shutil.copyfile(a.kit / name, units / name)
            (units / name).chmod(0o644)
        rotation = root / "etc/logrotate.d"
        rotation.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(a.kit / "logrotate.conf", rotation / "agent-center-agent")
        (rotation / "agent-center-agent").chmod(0o644)
        if a.lab_root:
            print(
                '{"installed_layout":true,"systemd_started":false,"enrollment_pending":true,"scope":"isolated_lab"}'
            )
            return 0
        for path in (state, logs, config / "config.json"):
            os.chown(path, account.pw_uid, account.pw_gid)
        if a.ca_file:
            shutil.copyfile(a.ca_file, "/usr/local/share/ca-certificates/agent-center.crt")
            run(["/usr/sbin/update-ca-certificates"])
        run(
            [
                "/usr/sbin/runuser",
                "-u",
                "agent-center-agent",
                "--",
                "/usr/bin/python3",
                "-I",
                str(base / "runtime/agent.pyz"),
                "--self-check",
            ]
        )
        run(["/usr/bin/systemctl", "daemon-reload"])
        command = [
            "/usr/sbin/runuser",
            "-u",
            "agent-center-agent",
            "--",
            "/usr/bin/python3",
            "-I",
            str(base / "runtime/agent.pyz"),
            "--config",
            str(config / "config.json"),
            "--enroll",
        ]
        # Enrollment returns 4 while approval is pending. Resume explicitly without another code.
        result = subprocess.run(command, check=False)
        if result.returncode == 4:
            print('{"state":"AWAITING_APPROVAL","service_started":false}')
            return 4
        if result.returncode:
            return 2
        run(["/usr/bin/systemctl", "enable", "--now", "agent-center-agent.service"])
        if a.enable_remote_updates:
            run(["/usr/bin/systemctl", "enable", "--now", "agent-center-updater.timer"])
        print('{"state":"SERVICE_STARTED","heartbeat_validation":"check_Agent_Center"}')
        return 0
    except Exception:
        print('{"event":"installation_refused_or_failed","secrets_logged":false}')
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
