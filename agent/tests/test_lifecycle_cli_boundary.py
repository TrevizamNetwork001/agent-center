import contextlib
import io
import unittest
from unittest.mock import patch

from agent_center.update import cli


class LifecycleCliBoundary(unittest.TestCase):
    def test_manual_apply_never_bypasses_lifecycle_even_as_root(self):
        for operation in ('update', 'rollback'):
            for uid in (0, 65534):
                args = ['updater', operation, '--apply', '--artifact', '/tmp/fixture.pyz', '--sha256', 'a' * 64]
                with (self.subTest(operation=operation, uid=uid),
                      patch('sys.argv', args), patch('os.geteuid', return_value=uid),
                      patch.object(cli, 'Engine') as engine,
                      contextlib.redirect_stdout(io.StringIO()) as output):
                    engine.return_value.apply.return_value = {'applied': True}
                    engine.return_value.rollback.return_value = {'rolled_back': True}
                    self.assertEqual(cli.main(), 2)
                    engine.assert_not_called()
                    self.assertIn('apply_requires_lifecycle', output.getvalue())

    def test_read_only_dry_run_remains_available(self):
        args = ['updater', 'update', '--dry-run', '--artifact', '/tmp/fixture.pyz', '--sha256', 'a' * 64]
        with (patch('sys.argv', args), patch.object(cli, 'Engine') as engine,
              contextlib.redirect_stdout(io.StringIO())):
            engine.return_value.dry_run.return_value = {'valid': True, 'apply': False}
            self.assertEqual(cli.main(), 0)
            engine.return_value.dry_run.assert_called_once()
            engine.return_value.apply.assert_not_called()
