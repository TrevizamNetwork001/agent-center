import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[4] / 'agent/candidates/routeros-operational-v1/src'))
from agent_center import mtr, observability


class LegacyHelperObservationTests(unittest.TestCase):
    def test_legacy_readiness_is_healthy_without_claiming_policy_visibility_or_probing(self):
        with (
            tempfile.TemporaryDirectory() as directory,
            patch('agent_center.mtr_client.exchange', side_effect=[
                {'ok': False, 'error': 'mtr_invalid_input'},
                {'ok': True, 'protocol_version': 1, 'capability': mtr.CAPABILITY},
            ]) as exchange,
            patch('agent_center.mtr.collect', side_effect=AssertionError('probe forbidden')),
        ):
            report = observability.snapshot(Path(directory))
        self.assertTrue(report['helper']['healthy'])
        self.assertTrue(report['helper']['authenticated'])
        self.assertIsNone(report['base'])
        self.assertIsNone(report['ceiling'])
        capability = next(c for c in report['capabilities'] if c['name'] == mtr.CAPABILITY)
        self.assertEqual('UNKNOWN', capability['state'])
        self.assertEqual('UNSUPPORTED_VERSION', capability['reason'])
        self.assertEqual([{'op': 'observe', 'protocol_version': 1}, {'op': 'ready', 'protocol_version': 1}], [call.args[0] for call in exchange.call_args_list])
