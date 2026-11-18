"""Build a deterministic, offline, integrity-verified installation kit from a committed Agent."""

import argparse
import hashlib
import json
import shutil
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("output", type=Path)
    args = parser.parse_args()
    ref = subprocess.check_output(["git", "-C", str(ROOT), "rev-parse", "HEAD"], text=True).strip()
    if subprocess.check_output(
        ["git", "-C", str(ROOT), "status", "--porcelain", "--", "agent"], text=True
    ).strip():
        raise SystemExit("Commit Agent sources before building a release kit")
    args.output.mkdir(mode=0o700, parents=True, exist_ok=False)
    for name, mode in [
        ("agent.pyz", None),
        ("updater.pyz", "updater"),
        ("remote-updater.pyz", "remote-updater"),
    ]:
        command = [sys.executable, str(ROOT / "agent/packaging/build.py"), str(args.output / name)]
        if mode:
            command.append(mode)
        subprocess.run(command, check=True, stdout=subprocess.DEVNULL)
    sys.path.insert(0, str(ROOT / "agent/src"))
    from agent_center import __version__

    catalog = [
        dict(
            version=__version__,
            sha256=hashlib.sha256((args.output / "agent.pyz").read_bytes()).hexdigest(),
            artifact="agent.pyz",
            compatibility="Debian 12/13; Linux CPython 3.13; state/journal schema 1",
            date=datetime.now(timezone.utc).isoformat(),
            status="available",
            changelog="Human-approved onboarding, operational telemetry and allowlisted pre-staged update/rollback companion.",
            git_ref=ref,
            device_operations_frozen=True,
            tested_capabilities=["agent_info", "dns_lookup:v1", "tcp_connect:v1", "http_probe:v1"],
        )
    ]
    (args.output / "catalog.json").write_text(json.dumps(catalog, indent=2) + "\n")
    for name in [
        "update-agent.sh",
        "rollback-agent.sh",
        "complete-enrollment.sh",
        "logrotate.conf",
    ]:
        shutil.copyfile(ROOT / "agent/packaging" / name, args.output / name)
    for name in [
        "agent-center-agent.service",
        "agent-center-updater.service",
        "agent-center-updater.timer",
    ]:
        shutil.copyfile(ROOT / "agent/systemd" / name, args.output / name)
    manifest = {
        path.name: hashlib.sha256(path.read_bytes()).hexdigest()
        for path in sorted(args.output.iterdir())
    }
    (args.output / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
    print(
        json.dumps(
            dict(
                version=__version__,
                git_ref=ref,
                manifest_sha256=hashlib.sha256(
                    (args.output / "manifest.json").read_bytes()
                ).hexdigest(),
                artifact_sha256=catalog[0]["sha256"],
            )
        )
    )


if __name__ == "__main__":
    main()
