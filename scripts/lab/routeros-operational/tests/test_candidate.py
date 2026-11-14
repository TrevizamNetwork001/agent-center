"""Provenance and fail-closed release compatibility gates, not an E2E substitute."""
import hashlib
import json
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[4]
CANDIDATE = ROOT / 'agent/candidates/routeros-operational-v1'
sys.path.insert(0, str(CANDIDATE / 'src'))
sys.path.insert(0, str(ROOT / 'scripts/lab/routeros-operational'))
import bundle
from agent_center import routeros7_lab
from agent_center.lifecycle.components import specification
from agent_center.lifecycle.releases import ReleaseError


class CandidateTests(unittest.TestCase):
    def test_extracted_source_hashes_match_verified_archive(self):
        proof = json.loads((CANDIDATE / 'provenance.json').read_text())
        self.assertTrue(proof['signature_verified'])
        self.assertEqual(proof['sequence'], 6)
        expected = {name: digest for name, digest in proof['source_members'].items()
                    if name != '__main__.py'}
        files = list((CANDIDATE / 'base').rglob('*.py'))
        self.assertEqual(len(files), len(expected))
        for path in files:
            self.assertEqual(hashlib.sha256(path.read_bytes()).hexdigest(),
                             expected[path.relative_to(CANDIDATE / 'base').as_posix()])

    def test_operational_safety_code_is_preserved_byte_for_byte(self):
        changed = {'agent_center/__init__.py', 'agent_center/capabilities.py', 'agent_center/api.py'}
        for base in (CANDIDATE / 'base').rglob('*.py'):
            relative = base.relative_to(CANDIDATE / 'base')
            if relative.as_posix() not in changed:
                self.assertEqual(base.read_bytes(), (CANDIDATE / 'src' / relative).read_bytes())

    def test_lifecycle_rejects_unimplemented_ssh_component(self):
        with self.assertRaisesRegex(ReleaseError, 'unsupported_component'):
            specification([{'id': 'routeros-ssh', 'version': '3.5.1',
                'profile': 'isolated-ssh', 'dependencies': ['python3.13'],
                'helper_sha256': '0'*64}], '0.6.6')

    def test_freeze_cannot_be_released_by_candidate_import(self):
        self.assertFalse(routeros7_lab.ready())
        with self.assertRaises(routeros7_lab.Rejected):
            routeros7_lab.validate({})

    def test_arbitrary_queries_are_rejected(self):
        for capability in ('/system reboot', 'DEVICE_CONFIG_WRITE', 'ssh'):
            with self.assertRaises(ValueError):
                routeros7_lab.commands(capability, {})

    def test_missing_wheels_are_not_a_valid_bundle(self):
        with (
            tempfile.TemporaryDirectory() as directory,
            self.assertRaisesRegex(ValueError, 'incomplete_dependency_set'),
        ):
            bundle.inventory(Path(directory))

    def test_dependency_lock_closes_versions_hashes_and_license_files(self):
        lock = json.loads((CANDIDATE / 'ssh-dependencies.lock.json').read_text())
        self.assertEqual(set(lock['packages']), set(bundle.PINS))
        for name, entry in lock['packages'].items():
            self.assertEqual(entry['version'], bundle.PINS[name])
            self.assertRegex(entry['sha256'], r'^[a-f0-9]{64}$')
            self.assertTrue(entry['license_files'])
        self.assertFalse(lock['lifecycle_installation_validated'])


if __name__ == '__main__':
    unittest.main()
