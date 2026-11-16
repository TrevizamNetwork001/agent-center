"""Fail-closed inputs for the candidate's transactional offline dependency path."""
import io
import os
import sys
import tempfile
import unittest
import zipfile
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[4] / 'agent/candidates/routeros-operational-v1/src'))
from agent_center.lifecycle import ssh_runtime
from agent_center.update.engine import Engine, UpdateError


class SshRuntimeTests(unittest.TestCase):
    def archive(self, names):
        stream = io.BytesIO()
        with zipfile.ZipFile(stream, 'w') as archive:
            for name, data in names:
                archive.writestr(name, data)
        return zipfile.ZipFile(io.BytesIO(stream.getvalue()))

    def test_no_ssh_payload_preserves_legacy_runtime(self):
        with self.archive([('__main__.py', b'pass')]) as archive:
            self.assertEqual({}, ssh_runtime.inventory(archive))

    def test_partial_extra_duplicate_and_tampered_payloads_fail(self):
        valid_names = [(ssh_runtime.PREFIX + name, b'corrupted') for name in ssh_runtime.WHEELS]
        for entries in (valid_names[:1], valid_names + [('agent_center/_ssh/other.whl', b'x')], valid_names):
            with self.subTest(count=len(entries)), self.archive(entries) as archive, self.assertRaises(ValueError):
                ssh_runtime.inventory(archive)

    def test_wheel_paths_scripts_and_symlinks_cannot_escape_staging(self):
        for name in ('../escape', '/escape', 'a/../../escape', 'site.pth', 'x.data/scripts/run'):
            stream = io.BytesIO()
            with zipfile.ZipFile(stream, 'w') as archive:
                archive.writestr(name, b'payload')
            with self.subTest(name=name), self.assertRaises(ValueError):
                ssh_runtime.files({'fixture': stream.getvalue()})

    def test_python_architecture_and_os_compatibility_are_required(self):
        with patch.object(ssh_runtime.platform, 'machine', return_value='aarch64'), self.assertRaisesRegex(ValueError, 'incompatible'):
            ssh_runtime.compatible()
        with patch.object(ssh_runtime.platform, 'freedesktop_os_release', return_value={'ID': 'debian', 'VERSION_ID': '12'}), self.assertRaisesRegex(ValueError, 'incompatible'):
            ssh_runtime.compatible()

    def test_restrictive_companion_umask_keeps_nested_libraries_readable(self):
        stream = io.BytesIO()
        with zipfile.ZipFile(stream, 'w') as wheel:
            wheel.writestr('package/nested/module.py', b'pass')
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            archive_path = root / 'agent.pyz'
            with zipfile.ZipFile(archive_path, 'w') as archive:
                archive.writestr('__main__.py', b'pass')
            previous = os.umask(0o077)
            try:
                with patch.object(ssh_runtime, 'inventory', return_value={'fixture': stream.getvalue()}), patch.object(ssh_runtime, 'compatible'):
                    ssh_runtime.materialize(archive_path, root)
            finally:
                os.umask(previous)
            for path in (root / 'ssh').rglob('*'):
                self.assertEqual(0o755 if path.is_dir() else 0o644, path.stat().st_mode & 0o777)

    def test_recovery_cleanup_is_closed_to_engine_owned_staging(self):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            stage = base / '.staging-abcdefgh'
            stage.mkdir(mode=0o755)
            (stage / 'partial').write_bytes(b'partial dependency')
            state = base / 'state'
            state.mkdir()
            (state / 'journal').write_bytes(b'preserve')
            engine = Engine(base, state)
            engine._discard_interrupted_stages()
            self.assertFalse(stage.exists())
            self.assertEqual(b'preserve', (state / 'journal').read_bytes())
            stage.symlink_to(state, target_is_directory=True)
            with self.assertRaisesRegex(UpdateError, 'unsafe_staging'):
                engine._discard_interrupted_stages()
            self.assertEqual(b'preserve', (state / 'journal').read_bytes())


if __name__ == '__main__':
    unittest.main()
