import importlib.util
import json
import subprocess
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("candidate_builder", ROOT / "scripts/prepare-agent-candidate.py")
candidate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(candidate)


class CandidateTests(unittest.TestCase):
    def test_signed_candidate_verifies_and_tamper_is_rejected_without_publication(self):
        with tempfile.TemporaryDirectory(prefix="agent-candidate-test-") as directory:
            root = Path(directory)
            key = root / "private.pem"
            subprocess.run(["/usr/bin/openssl", "genpkey", "-algorithm", "ED25519", "-out", str(key)],
                           capture_output=True, check=True, timeout=5)
            key.chmod(0o600)
            output = root / "candidate"
            report = candidate.prepare(output, 5, 4, key)
            self.assertTrue(report["signature_verified_with_provided_key"])
            self.assertTrue(report["same_source_all_components"])
            self.assertFalse(report["production_ready"])
            self.assertFalse(report["published"])
            self.assertFalse(report["production_trust_verified"])
            from agent_center.lifecycle.releases import ReleaseError, verify

            with self.assertRaisesRegex(ReleaseError, "artifact_mismatch"):
                verify((output / "manifest.json").read_bytes(),
                       (output / "signature.base64").read_text().strip(),
                       (output / "signer-public.pem").read_bytes(),
                       (output / "agent.pyz").read_bytes() + b"tampered",
                       minimum_sequence=4, platform="debian13")
            with self.assertRaisesRegex(ReleaseError, "replay_or_downgrade"):
                verify((output / "manifest.json").read_bytes(),
                       (output / "signature.base64").read_text().strip(),
                       (output / "signer-public.pem").read_bytes(),
                       (output / "agent.pyz").read_bytes(),
                       minimum_sequence=5, platform="debian13")
            previous = (output / "manifest.json").read_bytes()
            with self.assertRaises(FileExistsError):
                candidate.prepare(output, 6, 5, key)
            self.assertEqual(previous, (output / "manifest.json").read_bytes())
            self.assertEqual(0o700, output.stat().st_mode & 0o777)

    def test_unsigned_candidate_and_invalid_sequence_never_appear_available(self):
        with tempfile.TemporaryDirectory(prefix="agent-candidate-test-") as directory:
            output = Path(directory) / "candidate"
            with self.assertRaisesRegex(ValueError, "invalid_sequence"):
                candidate.prepare(output, 4, 4)
            self.assertFalse(output.exists())
            report = candidate.prepare(output, 5, 4)
            self.assertFalse(report["signature_verified_with_provided_key"])
            self.assertFalse((output / "signature.base64").exists())
            self.assertFalse((output / "catalog.json").exists())
            self.assertFalse(json.loads((output / "candidate-report.json").read_text())["production_ready"])


if __name__ == "__main__":
    unittest.main()
