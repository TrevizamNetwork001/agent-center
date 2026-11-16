"""Offline Ed25519 verification with an independently provisioned trust key.

No key, executable, command, or download URL is accepted from release metadata.
Verification does not advance the durable sequence; only a committed install may
advance it. Rollback authorization requires a separate protocol, not a bypass.
"""

import base64
import hashlib
import io
import json
import re
import subprocess
import tempfile
import zipfile
from pathlib import Path


class ReleaseError(ValueError):
    pass


def verify(payload, signature, public_key, artifact, *, minimum_sequence, platform):
    try:
        lines = public_key.strip().splitlines()
        if lines[0] != b"-----BEGIN PUBLIC KEY-----" or lines[-1] != b"-----END PUBLIC KEY-----":
            raise ValueError()
        der = base64.b64decode(b"".join(lines[1:-1]), validate=True)
        if len(der) != 44 or der[:12] != bytes.fromhex("302a300506032b6570032100"):
            raise ValueError()
    except (ValueError, TypeError, IndexError, AttributeError):
        raise ReleaseError("invalid_trust_key") from None
    if not isinstance(payload, bytes) or not 0 < len(payload) <= 65536:
        raise ReleaseError("invalid_manifest")
    try:
        decoded = base64.b64decode(signature, validate=True)
    except (ValueError, TypeError):
        raise ReleaseError("invalid_signature") from None
    if len(decoded) != 64:
        raise ReleaseError("invalid_signature")
    with tempfile.TemporaryDirectory(prefix="lifecycle-verify-") as directory:
        root = Path(directory)
        for name, data in [("manifest", payload), ("signature", decoded), ("key", public_key)]:
            (root / name).write_bytes(data)
        try:
            result = subprocess.run(
                [
                    "/usr/bin/openssl",
                    "pkeyutl",
                    "-verify",
                    "-pubin",
                    "-rawin",
                    "-inkey",
                    str(root / "key"),
                    "-in",
                    str(root / "manifest"),
                    "-sigfile",
                    str(root / "signature"),
                ],
                capture_output=True,
                timeout=5,
                check=False,
                env={"PATH": "/usr/bin:/bin", "LC_ALL": "C"},
            )
        except (OSError, subprocess.SubprocessError):
            raise ReleaseError("signature_verifier_unavailable") from None
    if result.returncode:
        raise ReleaseError("invalid_signature")

    def unique(pairs):
        value = {}
        for key, item in pairs:
            if key in value:
                raise ReleaseError("duplicate_manifest_key")
            value[key] = item
        return value

    try:
        doc = json.loads(payload, object_pairs_hook=unique)
    except (ValueError, UnicodeError):
        raise ReleaseError("invalid_manifest") from None
    fields = {"schema_version", "sequence", "version", "platform", "sha256", "size"}
    package_fields = {"runtime_package_protocol", "bootstrap_sha256"}
    allowed = (fields, fields | {"components"}, fields | package_fields,
               fields | package_fields | {"components"})
    if not isinstance(doc, dict) or set(doc) not in allowed:
        raise ReleaseError("invalid_manifest")
    if type(doc["schema_version"]) is not int or doc["schema_version"] != 1:
        raise ReleaseError("invalid_manifest")
    if type(doc["sequence"]) is not int or doc["sequence"] <= minimum_sequence:
        raise ReleaseError("replay_or_downgrade")
    if not isinstance(doc["version"], str) or not re.fullmatch(r"\d+\.\d+\.\d+", doc["version"]):
        raise ReleaseError("invalid_manifest")
    if doc["platform"] != platform:
        raise ReleaseError("incompatible_platform")
    if type(doc["size"]) is not int or not 0 < doc["size"] <= 8388608:
        raise ReleaseError("invalid_manifest")
    if not isinstance(doc["sha256"], str) or not re.fullmatch("[a-f0-9]{64}", doc["sha256"]):
        raise ReleaseError("invalid_manifest")
    if len(artifact) != doc["size"] or hashlib.sha256(artifact).hexdigest() != doc["sha256"]:
        raise ReleaseError("artifact_mismatch")
    if package_fields & set(doc):
        if (
            type(doc["runtime_package_protocol"]) is not int
            or doc["runtime_package_protocol"] not in (2, 3)
            or not isinstance(doc["bootstrap_sha256"], str)
            or not re.fullmatch("[a-f0-9]{64}", doc["bootstrap_sha256"])
            or doc["bootstrap_sha256"] == doc["sha256"]
        ):
            raise ReleaseError("invalid_package_protocol")
        from .ssh_runtime import inventory

        try:
            with zipfile.ZipFile(io.BytesIO(artifact)) as archive:
                if not inventory(archive):
                    raise ValueError("missing_payload")
        except (ValueError, zipfile.BadZipFile):
            raise ReleaseError("invalid_package_payload") from None
    if "components" in doc:
        from .components import specification

        specification(doc["components"], doc["version"])
    return doc
