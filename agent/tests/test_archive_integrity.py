import hashlib
import tempfile
import unittest
import warnings
import zipfile
from pathlib import Path

from agent_center.update.engine import UpdateError, validate


class ArchiveIntegrity(unittest.TestCase):
    def test_duplicate_entry_is_rejected_even_when_digest_matches(self):
        with tempfile.TemporaryDirectory() as directory:
            artifact = Path(directory) / 'agent.pyz'
            with warnings.catch_warnings():
                warnings.simplefilter('ignore', UserWarning)
                with zipfile.ZipFile(artifact, 'w') as archive:
                    archive.writestr('__main__.py', 'pass')
                    archive.writestr('agent_center/main.py', 'pass')
                    archive.writestr('agent_center/main.py', 'raise SystemExit(1)')
            digest = hashlib.sha256(artifact.read_bytes()).hexdigest()
            with self.assertRaisesRegex(UpdateError, '^unsafe_archive$'):
                validate(artifact, digest)
