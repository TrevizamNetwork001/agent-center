import socket
import unittest
from unittest.mock import patch

from agent_center.capabilities import KNOWN, execute
from agent_center.policy import Policy


class DnsPolicyRegressions(unittest.TestCase):
    def setUp(self):
        self.policy = Policy(True, ('dns_lookup:v1',), True, True)

    def test_unlisted_a_and_aaaa_never_reach_resolver(self):
        with patch('socket.getaddrinfo') as resolver:
            for kind in ('A', 'AAAA'):
                self.assertEqual(execute('dns_lookup:v1', {'target': 'google.com', 'record_type': kind}, self.policy, (), ()), {'ok': False, 'error': 'policy_denied'})
            resolver.assert_not_called()

    def test_allowlisted_a_and_aaaa_work_without_probe_networks(self):
        for kind, family, ip in [('A', socket.AF_INET, '142.250.185.78'), ('AAAA', socket.AF_INET6, '2a00:1450:4001:830::200e')]:
            with self.subTest(kind=kind), patch('socket.getaddrinfo', return_value=[(family, socket.SOCK_STREAM, 6, '', (ip, 443))]), patch('socket.create_connection') as connect:
                result = execute('dns_lookup:v1', {'target': 'google.com', 'record_type': kind}, self.policy, (), ('google.com',))
                self.assertTrue(result['ok'])
                self.assertEqual(result['data']['answers'], [ip])
                connect.assert_not_called()

    def test_split_dns_records_are_returned_as_data_without_adding_network_authority(self):
        networks = ('8.8.8.8/32',)
        rows = [(socket.AF_INET, socket.SOCK_STREAM, 6, '', (ip, 443)) for ip in ('8.8.8.8', '192.168.1.1')]
        with patch('socket.getaddrinfo', return_value=rows), patch('socket.create_connection') as connect:
            result = execute('dns_lookup:v1', {'target': 'google.com', 'record_type': 'A'}, self.policy, networks, ('google.com',))
            self.assertTrue(result['ok'])
            self.assertEqual(result['data']['answers'], ['192.168.1.1', '8.8.8.8'])
            self.assertEqual(networks, ('8.8.8.8/32',))
            connect.assert_not_called()

    def test_dns_answers_do_not_authorize_active_probes(self):
        policy = Policy(True, KNOWN, True, True)
        cases = [('tcp_connect:v1', {'target': '192.168.1.1', 'port': 443}), ('icmp_ping:v1', {'target': '192.168.1.1'}), ('traceroute:v1', {'target': '192.168.1.1'}), ('http_probe:v1', {'url': 'https://google.com/', 'target': '192.168.1.1'})]
        with patch('agent_center.capabilities.available', return_value=KNOWN), patch('socket.create_connection') as connect, patch('subprocess.run') as command, patch('agent_center.capabilities.PinnedHTTPS') as https:
            for capability, args in cases:
                with self.subTest(capability=capability):
                    result = execute(capability, args, policy, (), ('google.com',))
                    self.assertFalse(result['ok'])
            connect.assert_not_called()
            command.assert_not_called()
            https.assert_not_called()

    def test_reverse_lookup_still_requires_an_authorized_network(self):
        with patch('socket.gethostbyaddr') as reverse:
            result = execute('dns_lookup:v1', {'target': '192.168.1.1'}, self.policy, (), ('google.com',))
            self.assertFalse(result['ok'])
            reverse.assert_not_called()

    def test_missing_capability_authorization_never_reaches_resolver(self):
        with patch('socket.getaddrinfo') as resolver:
            for policy in [Policy(False), Policy(True, (), True, True)]:
                result = execute('dns_lookup:v1', {'target': 'google.com', 'record_type': 'A'}, policy, (), ('google.com',))
                self.assertEqual(result, {'ok': False, 'error': 'policy_denied'})
            resolver.assert_not_called()

    def test_unrelated_executor_failure_is_not_reported_as_policy_denied(self):
        with patch('socket.getaddrinfo', side_effect=OSError('private fixture details')):
            result = execute('dns_lookup:v1', {'target': 'google.com', 'record_type': 'A'}, self.policy, (), ('google.com',))
        self.assertEqual(result, {'ok': False, 'error': 'action_failed'})

    def test_malformed_answer_is_rejected_without_exposing_payload(self):
        with patch('socket.getaddrinfo', return_value=[(socket.AF_INET, socket.SOCK_STREAM, 6, '', ('not-an-ip-private-fixture', 443))]):
            result = execute('dns_lookup:v1', {'target': 'google.com', 'record_type': 'A'}, self.policy, (), ('google.com',))
        self.assertEqual(result, {'ok': False, 'error': 'action_failed'})

    def test_resolver_timeout_and_addressless_outcome_remain_distinct(self):
        with patch('socket.getaddrinfo', side_effect=TimeoutError):
            result = execute('dns_lookup:v1', {'target': 'google.com', 'record_type': 'A'}, self.policy, (), ('google.com',))
        self.assertEqual(result, {'ok': False, 'error': 'timeout'})
        with patch('socket.getaddrinfo', side_effect=socket.gaierror(socket.EAI_NONAME, 'fixture')):
            result = execute('dns_lookup:v1', {'target': 'absent.invalid', 'record_type': 'A'}, self.policy, (), ('absent.invalid',))
        self.assertTrue(result['ok'])
        self.assertEqual(result['data']['error'], 'nodata')
        self.assertNotIn('rcode', result['data'])


class DestinationPolicyTests(unittest.TestCase):
    def test_network_denials_have_policy_code_for_all_active_probes(self):
        policy = Policy(True, KNOWN, True, True)
        checks = [('tcp_connect:v1', {'target': '172.217.162.14', 'port': 443}), ('http_probe:v1', {'target': '172.217.162.14', 'url': 'https://google.com/'}), ('icmp_ping:v1', {'target': '172.217.162.14'}), ('traceroute:v1', {'target': '172.217.162.14'})]
        with patch('agent_center.capabilities.available', return_value=KNOWN), patch('socket.create_connection') as connect, patch('subprocess.run') as process:
            for capability, arguments in checks:
                self.assertEqual(execute(capability, arguments, policy, (), ('google.com',)), {'ok': False, 'error': 'policy_denied'})
            connect.assert_not_called()
            process.assert_not_called()

    def test_malformed_destination_is_not_misreported_as_a_policy_denial(self):
        policy = Policy(True, KNOWN, True, True)
        result = execute('tcp_connect:v1', {'target': 'not-an-ip', 'port': 443}, policy, (), ())
        self.assertEqual(result, {'ok': False, 'error': 'action_failed'})


if __name__ == '__main__':
    unittest.main()
