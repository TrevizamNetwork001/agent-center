import tempfile
import unittest
from pathlib import Path

from agent_center.config import Config
from agent_center.state import atomic_write


class PolicyEnvelope(unittest.TestCase):
    def test_remote_policy_cannot_expand_local_names_or_networks(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            atomic_write(root / 'config.json', {
                'central_url': 'https://central.example', 'state_dir': str(root),
                'log_dir': str(root), 'identity_file': str(root / 'identity.json'),
                'allowed_dns_names': ['google.com'],
                'allowed_networks': ['192.0.2.0/24'],
                'auto_public_targets': False,
            })
            atomic_write(root / 'managed-settings.json', {
                'revision': 1, 'source_ips': [], 'auto_public_targets': True,
                'allowed_dns_names': ['attacker.example', 'google.com'],
                'allowed_networks': ['0.0.0.0/0', '::/0'],
            })
            config = Config.load(root / 'config.json')
            self.assertEqual(config.allowed_dns_names, ('google.com',))
            self.assertEqual(config.allowed_networks, ('192.0.2.0/24',))
            self.assertFalse(config.auto_public_targets)

    def test_remote_policy_can_only_narrow_or_remove_base_permissions(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            atomic_write(root / 'config.json', {
                'central_url': 'https://central.example', 'state_dir': str(root),
                'log_dir': str(root), 'identity_file': str(root / 'identity.json'),
                'allowed_dns_names': ['google.com'], 'allowed_networks': ['192.0.2.0/24'],
            })
            atomic_write(root / 'managed-settings.json', {
                'revision': 1, 'source_ips': [], 'auto_public_targets': False,
                'allowed_dns_names': [], 'allowed_networks': ['192.0.2.8/32'],
            })
            config = Config.load(root / 'config.json')
            self.assertEqual(config.allowed_dns_names, ())
            self.assertEqual(config.allowed_networks, ('192.0.2.8/32',))
