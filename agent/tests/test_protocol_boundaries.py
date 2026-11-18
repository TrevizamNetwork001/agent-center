import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock

from agent_center.api import Client
from agent_center.policy import Policy
from agent_center.state import StateError, read_private


class ProtocolBoundaries(unittest.TestCase):
    def test_deep_response_is_a_typed_failure_and_does_not_escape_client(self):
        client = Client('https://central.example.test', retries=0)
        response = MagicMock()
        response.status = 200
        response.read.return_value = b'{"nested":' + b'[' * 50000 + b'0' + b']' * 50000 + b'}'
        client.opener = MagicMock()
        client.opener.open.return_value.__enter__.return_value = response
        outcome = client.call('heartbeat')
        self.assertFalse(outcome.ok)
        self.assertEqual(outcome.error, 'invalid_response')
        self.assertEqual(outcome.body, {})
        client.opener.open.assert_called_once()

    def test_deep_payload_is_refused_before_transport(self):
        payload = current = {}
        for _ in range(50000):
            current['nested'] = {}
            current = current['nested']
        client = Client('https://central.example.test', retries=0)
        client.opener = MagicMock()
        outcome = client.call('result', payload)
        self.assertEqual(outcome.error, 'invalid_payload')
        client.opener.open.assert_not_called()

    def test_deep_private_json_is_a_state_error(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'state.json'
            path.write_bytes(b'{"nested":' + b'[' * 50000 + b'0' + b']' * 50000 + b'}')
            path.chmod(0o600)
            with self.assertRaises(StateError):
                read_private(path)

    def test_policy_rejects_boolean_protocol_and_non_boolean_enable_flags(self):
        base = {'schema_version': 1, 'agent_id': 'fixture', 'device_operations_frozen': True,
                'tasks_enabled': True, 'task_protocol': 1, 'allowed_capabilities': ['agent_info']}
        self.assertTrue(Policy.parse(base, 'fixture', ('agent_info',)).tasks)
        for change in ({'task_protocol': True}, {'task_protocol': 1.0},
                       {'tasks_enabled': 1}, {'tasks_enabled': 0}):
            with self.subTest(change=change):
                self.assertFalse(Policy.parse(base | change, 'fixture', ('agent_info',)).valid)
