"""Offline signed reference candidate and compatibility gates; never authorizes a device.

Uses the existing candidate builder, verifier and updater dry-run unchanged.
The key is ephemeral laboratory trust, never an operational trust anchor.
"""

import hashlib
import importlib.metadata
import importlib.util
import json
import os
import platform
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]
sys.path.insert(0, str(ROOT / "agent/src"))


def main():
    from agent_center.identity import Identity
    from agent_center.journal import Journal
    from agent_center.lifecycle.components import specification
    from agent_center.lifecycle.releases import ReleaseError, verify
    from agent_center.state import atomic_write
    from agent_center.update.engine import Engine

    os.umask(0o077)
    output = Path("/tmp/routeros-physical-preparation")
    output.mkdir(mode=0o700, exist_ok=False)
    signer = output / "private-signing-key.pem"
    subprocess.run(
        ["/usr/bin/openssl", "genpkey", "-algorithm", "ED25519", "-out", str(signer)],
        check=True,
        capture_output=True,
        timeout=5,
    )
    signer.chmod(0o600)
    spec = importlib.util.spec_from_file_location(
        "candidate_preparation", ROOT / "scripts/prepare-agent-candidate.py"
    )
    builder = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(builder)
    bundle = output / "bundle"
    # Synthetic lab sequence/floor; never reserve or guess an operational sequence.
    candidate = builder.prepare(bundle, 2, 1, signer)
    payload = (bundle / "manifest.json").read_bytes()
    # Public verification material must be readable by the non-root lab runtime.
    # The signing key remains private, outside the bundle, mode 0600.
    for name in ("manifest.json", "signature.base64", "signer-public.pem"):
        (bundle / name).chmod(0o644)
    signature = (bundle / "signature.base64").read_text().strip()
    public = (bundle / "signer-public.pem").read_bytes()
    artifact = (bundle / "agent.pyz").read_bytes()
    verified = verify(
        payload, signature, public, artifact, minimum_sequence=1, platform="debian13"
    )
    rejection = {}
    for name, data, floor, expected_platform in [
        ("tamper", artifact + b"changed", 1, "debian13"),
        ("replay", artifact, 2, "debian13"),
        ("platform", artifact, 1, "other"),
    ]:
        try:
            verify(
                payload,
                signature,
                public,
                data,
                minimum_sequence=floor,
                platform=expected_platform,
            )
            raise AssertionError("invalid candidate accepted")
        except ReleaseError as error:
            rejection[name] = str(error)
    try:
        specification(
            [
                {
                    "id": "routeros-ssh",
                    "version": verified["version"],
                    "profile": "ssh-readonly",
                    "dependencies": ["paramiko"],
                    "helper_sha256": "0" * 64,
                }
            ],
            verified["version"],
        )
        raise AssertionError("unsupported dependency component accepted")
    except ReleaseError as error:
        rejection["ssh_component"] = str(error)
    with zipfile.ZipFile(bundle / "agent.pyz") as archive:
        members = archive.namelist()
        assert "agent_center/routeros7_lab.py" in members
        bundled_ssh = any(
            name.startswith(("paramiko/", "cryptography/", "bcrypt/", "nacl/"))
            for name in members
        )
    with tempfile.TemporaryDirectory(prefix="physical-dry-run-") as directory:
        state = Path(directory) / "state"
        base = Path(directory) / "base"
        state.mkdir(mode=0o700)
        (base / "runtime").mkdir(parents=True)
        (base / "runtime/agent.pyz").write_bytes(artifact)
        Identity(
            "00000000-0000-4000-8000-000000000001",
            "00000000-0000-4000-8000-000000000001." + "1" * 64,
        ).save(state / "identity.json")
        atomic_write(state / "config.json", {"device_operations_frozen": True})
        journal = Journal(state / "journal.sqlite3")
        journal.reserve(
            "00000000-0000-4000-8000-000000000002",
            "00000000-0000-4000-8000-000000000003",
        )
        journal.transition("00000000-0000-4000-8000-000000000002", "uncertain")
        journal.close()
        paths = [
            state / "identity.json",
            state / "journal.sqlite3",
            state / "config.json",
            base / "runtime/agent.pyz",
        ]
        before = [hashlib.sha256(path.read_bytes()).hexdigest() for path in paths]
        dry_run = Engine(base, state).dry_run(bundle / "agent.pyz", verified["sha256"])
        after = [hashlib.sha256(path.read_bytes()).hexdigest() for path in paths]
        assert before == after and dry_run["apply"] is False
    dependencies = {
        name: importlib.metadata.version(name)
        for name in (
            "paramiko",
            "cryptography",
            "bcrypt",
            "pynacl",
            "cffi",
            "pycparser",
        )
    }
    report = {
        "result": "BLOCKED",
        "candidate": candidate,
        "native_verifier": "PASS",
        "native_updater_dry_run": dry_run,
        "identity_journal_config_runtime_unchanged": before == after,
        "negative_verification": rejection,
        "artifact_has_adapter": True,
        "artifact_bundles_ssh_dependencies": bundled_ssh,
        "lab_dependencies_effective": dependencies,
        "python": sys.version.split()[0],
        "uid": os.geteuid(),
        "effective_lab_platform": {
            "id": platform.freedesktop_os_release()["ID"],
            "version_id": platform.freedesktop_os_release()["VERSION_ID"],
            "architecture": platform.machine(),
        },
        "manifest_platform": verified["platform"],
        "native_debian13_systemd_precheck_proven": False,
        "operational_version_declared_by_operator": "0.6.5",
        "candidate_version": verified["version"],
        "candidate_newer_than_declared_operational": tuple(
            map(int, verified["version"].split("."))
        )
        > (0, 6, 5),
        "production_runtime_inspected": False,
        "production_trust_verified": False,
        "blockers": [
            "Adapter and Central require simulator namespace and fixed loopback; no physical execution grant contract exists.",
            "Lifecycle component allowlist supports MTR only, not SSH dependencies; archive validator forbids third-party/binary members.",
            "Current source version is 0.6.4, older than operator-declared operational 0.6.5; no promotion or downgrade authorized.",
            f"Effective offline tooling host is {platform.freedesktop_os_release()['ID']} {platform.freedesktop_os_release()['VERSION_ID']}; native Debian 13/systemd precheck is not proven.",
        ],
        "physical_connections": 0,
        "published": False,
        "operational_agent_updated": False,
        "device_freeze": True,
    }
    (bundle / "physical-readiness.json").write_text(json.dumps(report, indent=2) + "\n")
    print(json.dumps(report))
    return 0  # Preparation succeeded; physical readiness is explicitly BLOCKED.


if __name__ == "__main__":
    raise SystemExit(main())
