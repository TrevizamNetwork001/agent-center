import contextlib
import io
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

from agent_center.api import Outcome
from agent_center.state import atomic_write, read_private
from agent_center.update import remote
from agent_center.update.remote import Remote


class UpdaterDiagnosticsTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.control = Path(self.tmp.name)
        self.client = Mock()
        self.remote = Remote(Mock(), self.client, [], self.control)

    def run_remote(self):
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            result = self.remote.run()
        return result, output.getvalue()

    def test_claim_failures_are_classified_without_response_or_credentials(self):
        for status, error, retryable in (
            (0, "network_error", True), (401, "http_401", False),
            (403, "origin_not_allowed", False), (429, "http_429", True),
            (503, "http_503", True), (200, "invalid_response", True),
        ):
            with self.subTest(error=error):
                self.client.call.return_value = Outcome(
                    False, status, {"token": "PRIVATE_RESPONSE"}, error, retryable
                )
                result, output = self.run_remote()
                self.assertFalse(result)
                self.assertEqual(json.loads(output), {
                    "event": "updater_api_failed", "operation": "operations/claim",
                    "reason": error, "status": status, "retryable": retryable,
                })
                self.assertNotIn("PRIVATE_RESPONSE", output)

    def test_failed_report_preserves_marker_and_does_not_claim(self):
        marker = self.control / "pending.json"
        record = {"operation_id": "fixture", "status": "COMPLETED"}
        atomic_write(marker, record)
        self.client.call.return_value = Outcome(False, 503, {}, "http_503", True)
        result, output = self.run_remote()
        self.assertFalse(result)
        self.assertEqual(read_private(marker), record)
        self.client.call.assert_called_once_with("operations/report", record)
        self.assertEqual(json.loads(output)["operation"], "operations/report")

    def test_unknown_error_and_invalid_status_are_redacted(self):
        self.client.call.return_value = Outcome(
            False, "PRIVATE_STATUS", {"token": "PRIVATE_BODY"}, "PRIVATE_ERROR", True
        )
        result, output = self.run_remote()
        self.assertFalse(result)
        self.assertEqual(json.loads(output)["reason"], "unavailable")
        self.assertEqual(json.loads(output)["status"], 0)
        self.assertNotIn("PRIVATE", output)

    def test_idle_success_remains_silent_and_does_not_touch_engine(self):
        self.client.call.return_value = Outcome(True, 200, {"operation": None})
        result, output = self.run_remote()
        self.assertTrue(result)
        self.assertEqual(output, "")
        self.assertEqual(self.remote.engine.mock_calls, [])

    def test_delivered_failed_report_explains_exit_without_replaying_operation(self):
        for status, error, expected in (
            ('FAILED', 'postcheck_failed', 'postcheck_failed'),
            ('UNCERTAIN', 'interrupted', 'interrupted'),
            ('FAILED', 'PRIVATE_ERROR', 'maintenance_failed'),
        ):
            with self.subTest(status=status, error=error):
                marker = self.control / 'pending.json'
                record = {'operation_id': 'fixture', 'status': status, 'error': error}
                atomic_write(marker, record)
                self.client.reset_mock()
                self.client.call.return_value = Outcome(True, 200, {})
                result, output = self.run_remote()
                self.assertFalse(result)
                self.assertFalse(marker.exists())
                self.client.call.assert_called_once_with('operations/report', record)
                self.assertEqual(json.loads(output), {
                    'event': 'updater_operation_failed', 'status': status, 'reason': expected,
                })
                self.assertNotIn('PRIVATE', output)

    def test_entrypoint_bounds_transport_timeout_without_claim_retries(self):
        catalog = self.control / 'catalog.json'
        catalog.write_text('[]')
        client = Mock()
        client.call.return_value = Outcome(False, 0, {}, 'network_error', True)
        with (
            patch.object(remote.os, 'geteuid', return_value=0),
            patch.object(remote.os, 'umask'),
            patch.object(remote, 'BASE', self.control),
            patch.object(remote, 'CONTROL', self.control / 'control'),
            patch.object(remote, 'trusted', return_value=catalog),
            patch.object(remote, 'read_private', return_value={'central_url': 'https://central.example.test'}),
            patch.object(remote, 'read_identity', return_value=Mock(token='PRIVATE_TOKEN')),
            patch.object(remote, 'Client', return_value=client) as constructor,
            patch.object(remote, 'Engine'),
            contextlib.redirect_stdout(io.StringIO()) as output,
        ):
            (self.control / 'releases').mkdir()
            (self.control / 'releases' / 'catalog.json').write_text('[]')
            self.assertEqual(remote.main(), 1)
        constructor.assert_called_once_with('https://central.example.test', 'PRIVATE_TOKEN', timeout=10, retries=0)
        self.assertEqual(client.call.call_count, 1)
        self.assertNotIn('PRIVATE_TOKEN', output.getvalue())

    def test_unsafe_control_directory_is_refused_before_loading_credentials(self):
        control = self.control / 'unsafe-control'
        control.mkdir()
        control.chmod(0o755)
        with (
            patch.object(remote.os, 'geteuid', return_value=0),
            patch.object(remote.os, 'umask'),
            patch.object(remote, 'CONTROL', control),
            patch.object(remote, 'trusted'),
            patch.object(remote, 'read_private') as configuration,
            patch.object(remote, 'read_identity') as identity,
            patch.object(remote, 'Client') as client,
            contextlib.redirect_stdout(io.StringIO()),
        ):
            self.assertEqual(remote.main(), 2)
        configuration.assert_not_called()
        identity.assert_not_called()
        client.assert_not_called()

    def test_missing_lifecycle_key_cannot_enable_unsigned_operation_polling(self):
        def trust(path):
            if path.name == 'lifecycle-public.pem':
                raise FileNotFoundError()
            return path

        with (
            patch.object(remote.os, 'geteuid', return_value=0),
            patch.object(remote.os, 'umask'),
            patch.object(remote, 'CONTROL', self.control),
            patch.object(remote, 'trusted', side_effect=trust),
            patch.object(remote, 'read_private') as configuration,
            patch.object(remote, 'read_identity') as identity,
            patch.object(remote, 'Client') as client,
            contextlib.redirect_stdout(io.StringIO()),
        ):
            self.assertEqual(remote.main(), 2)
        configuration.assert_not_called()
        identity.assert_not_called()
        client.assert_not_called()
