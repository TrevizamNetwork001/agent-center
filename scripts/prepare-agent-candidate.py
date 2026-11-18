"""Prepare a closed release candidate without publishing or modifying installed Agents.

The signature authenticates the runtime archive. Companion/CLI/helper are derived
from exactly the same members with fixed entrypoints; no unsigned source is added.
This tool intentionally does not mark a candidate available in Central.
"""

import argparse
import ast
import base64
import hashlib
import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import tomllib
import zipfile
from pathlib import Path


def prepare(output, sequence, minimum_sequence, private_key=None):
    root = Path(__file__).resolve().parents[1]
    sys.path.insert(0, str(root / "agent/src"))
    from agent_center.lifecycle.components import helper_from_runtime
    from agent_center.lifecycle.releases import verify

    version = ast.literal_eval(ast.parse(
        (root / "agent/src/agent_center/__init__.py").read_text()
    ).body[-1].value)
    project = tomllib.loads((root / "agent/pyproject.toml").read_text())
    if version != project["project"]["version"] or version == "0.6.3":
        raise ValueError("reserved_or_inconsistent_version")
    if not 0 <= minimum_sequence < sequence <= 2147483647:
        raise ValueError("invalid_sequence")
    if private_key is not None:
        info = private_key.lstat()
        if private_key.is_symlink() or not private_key.is_file() or info.st_mode & 0o077:
            raise ValueError("unsafe_signing_key")
        if private_key.resolve().is_relative_to(root):
            raise ValueError("signing_key_must_be_outside_repository")
    output.mkdir(mode=0o700, parents=True, exist_ok=False)
    spec = importlib.util.spec_from_file_location("agent_builder", root / "agent/packaging/build.py")
    builder = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(builder)
    roles = {"agent.pyz": "agent", "remote-updater.pyz": "remote-updater",
             "updater.pyz": "updater", "helper.pyz": "mtr-helper",
             "updater-bootstrap.pyz": "updater-bootstrap"}
    hashes, sources = {}, set()
    for name, mode in roles.items():
        digest, source = builder.build(output / name, mode)
        hashes[name] = digest
        sources.add(source)
        with tempfile.TemporaryDirectory(prefix="agent-candidate-repeat-") as directory:
            repeated = Path(directory) / name
            builder.build(repeated, mode)
            if repeated.read_bytes() != (output / name).read_bytes():
                raise ValueError("non_deterministic_build")
    if len(sources) != 1:
        raise ValueError("source_mismatch")
    artifact = (output / "agent.pyz").read_bytes()
    if helper_from_runtime(artifact) != (output / "helper.pyz").read_bytes():
        raise ValueError("helper_derivation_mismatch")
    # Each sibling must have identical archive members except the fixed main.
    with zipfile.ZipFile(output / "agent.pyz") as archive:
        expected = {name: archive.read(name) for name in archive.namelist() if name != "__main__.py"}
    for name in roles:
        with zipfile.ZipFile(output / name) as archive:
            actual = {entry: archive.read(entry) for entry in archive.namelist() if entry != "__main__.py"}
            if actual != expected:
                raise ValueError("component_source_mismatch")
    manifest = {"schema_version": 1, "version": version, "sequence": sequence,
                "platform": "debian13", "sha256": hashes["agent.pyz"], "size": len(artifact),
                "components": [{"id": "mtr", "version": version,
                    "profile": "network-raw-helper", "dependencies": ["python3.13", "mtr0.95"],
                    "helper_sha256": hashes["helper.pyz"]}]}
    payload = json.dumps(manifest, sort_keys=True, separators=(",", ":")).encode()
    (output / "manifest.json").write_bytes(payload)
    signature_verified = False
    if private_key is not None:
        signature = subprocess.run([
            "/usr/bin/openssl", "pkeyutl", "-sign", "-rawin", "-inkey", str(private_key),
            "-in", str(output / "manifest.json"),
        ], capture_output=True, timeout=10, check=True).stdout
        public = subprocess.run([
            "/usr/bin/openssl", "pkey", "-in", str(private_key), "-pubout",
        ], capture_output=True, timeout=10, check=True).stdout
        encoded = base64.b64encode(signature).decode()
        verify(payload, encoded, public, artifact,
               minimum_sequence=minimum_sequence, platform="debian13")
        (output / "signature.base64").write_text(encoded + "\n")
        (output / "signer-public.pem").write_bytes(public)
        signature_verified = True
    report = {"version": version, "sequence": sequence, "minimum_sequence": minimum_sequence,
              "source_sha256": sources.pop(), "artifacts": hashes, "deterministic": True,
              "same_source_all_components": True, "signature_verified_with_provided_key": signature_verified,
              "production_trust_verified": False, "production_ready": False, "published": False,
              "gates": ["central_version_compatibility", "signed_updater_distribution_and_rollback",
                        "native_debian13_systemd_lab", "registered_agent_transport_observation",
                        "production_trust_anchor", "operational_deployment_authorization"]}
    (output / "candidate-report.json").write_text(json.dumps(report, indent=2) + "\n")
    return report


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--sequence", type=int, required=True)
    parser.add_argument("--minimum-sequence", type=int, required=True)
    parser.add_argument("--private-key", type=Path)
    args = parser.parse_args()
    os.umask(0o077)
    try:
        report = prepare(args.output, args.sequence, args.minimum_sequence, args.private_key)
    except (OSError, ValueError, subprocess.SubprocessError):
        print('{"error":"candidate_preparation_failed"}', file=sys.stderr)
        return 2
    print(json.dumps(report))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
