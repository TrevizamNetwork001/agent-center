import base64
import hashlib
import json
import subprocess
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

from agent_center.lifecycle.releases import ReleaseError, verify
from agent_center.update.engine import UpdateError
from agent_center.update.remote import Remote


class SignedReleases(unittest.TestCase):
    def test_valid_tampered_replay_platform_and_artifact(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)

            def run(*args):
                return subprocess.run(
                    ["/usr/bin/openssl", *args], check=True, capture_output=True
                ).stdout

            run("genpkey", "-algorithm", "ED25519", "-out", str(root / "private"))
            key = run("pkey", "-in", str(root / "private"), "-pubout")
            artifact = b"approved fixture"
            payload = json.dumps(
                dict(
                    schema_version=1,
                    sequence=2,
                    version="0.6.2",
                    platform="debian13",
                    size=len(artifact),
                    sha256=hashlib.sha256(artifact).hexdigest(),
                )
            ).encode()
            (root / "manifest").write_bytes(payload)
            sig = base64.b64encode(
                run(
                    "pkeyutl",
                    "-sign",
                    "-rawin",
                    "-inkey",
                    str(root / "private"),
                    "-in",
                    str(root / "manifest"),
                )
            )
            self.assertEqual(
                verify(payload, sig, key, artifact, minimum_sequence=1, platform="debian13")[
                    "sequence"
                ],
                2,
            )
            (root / "lifecycle-public.pem").write_bytes(key)
            remote = Remote(None, SimpleNamespace(), [], root)
            remote.platform = "debian13"
            release = {
                "sha256": hashlib.sha256(artifact).hexdigest(),
                "version": "0.6.2",
                "lifecycle": {
                    "manifest": base64.b64encode(payload).decode(),
                    "signature": sig.decode(),
                },
            }
            with (
                patch("agent_center.update.remote.CONFIG", root / "operations.json"),
                patch("agent_center.update.remote.trusted", side_effect=lambda p: p),
            ):
                remote._verify_signed_release(release, artifact)
                self.assertEqual(remote.signed_release["sequence"], 2)
                with self.assertRaisesRegex(UpdateError, "invalid_release"):
                    remote._verify_signed_release({"sha256": release["sha256"]}, artifact)
                with self.assertRaisesRegex(UpdateError, "invalid_release"):
                    remote._verify_signed_release(release, b"tampered")
            cases = [
                (payload + b" ", sig, key, artifact, 1, "debian13", "invalid_signature"),
                (payload, sig, key, b"tampered", 1, "debian13", "artifact_mismatch"),
                (payload, sig, key, artifact, 2, "debian13", "replay_or_downgrade"),
                (payload, sig, key, artifact, 1, "debian12", "incompatible_platform"),
                (payload, b"invalid", key, artifact, 1, "debian13", "invalid_signature"),
                (payload, sig, b"bad key", artifact, 1, "debian13", "invalid_trust_key"),
            ]
            for data, signature, trust, content, sequence, platform, error in cases:
                with (
                    self.subTest(error=error),
                    self.assertRaisesRegex(ReleaseError, "^" + error + "$"),
                ):
                    verify(
                        data,
                        signature,
                        trust,
                        content,
                        minimum_sequence=sequence,
                        platform=platform,
                    )
