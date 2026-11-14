"""Offline rejection tests supplement the real Task Engine/SSH laboratory."""
import unittest

from agent_center import routeros7_lab as ros


class RouterOsSafetyTests(unittest.TestCase):
    def test_unknown_operations_and_command_injection_rejected(self):
        for operation, params in (
            ("/system reboot", {}),
            (ros.OPERATIONS[0], {"command": "/system reboot"}),
            (ros.OPERATIONS[2], {"destination": "8.8.8.8; /system reboot"}),
            (ros.OPERATIONS[2], {"destination": "8.8.8.8", "table": 'main"; /system reboot'}),
            (ros.OPERATIONS[2], {"destination": "::1"}),
            (ros.OPERATIONS[1], {"peer": "$(reboot)"}),
            (ros.OPERATIONS[3], {"interface": "ether1\n/system reboot"}),
        ):
            with self.subTest(operation=operation, params=params), self.assertRaises(ValueError):
                ros.commands(operation, params)

    def test_sensitive_unknown_fields_and_truncation_never_projected(self):
        for text in (
            "0 E name=peer remote.address=192.0.2.1 password=SECRET_FIXTURE\n",
            "0 E name=peer remote.address=192.0.2.1 name=duplicate\n",
            "0 E name=peer remote.address=192.0.2.1",
            "0 E name=peer remote.address=192.0.2.1\x00\n",
        ):
            with self.subTest(text=text), self.assertRaises(ros.Rejected):
                ros.parse(ros.OPERATIONS[1], [text, "1\n"], {})

    def test_route_response_must_match_exact_ancestor_and_table(self):
        for row in (
            "0 A dst-address=0.0.0.0/0 routing-table=main\n",
            "0 A dst-address=8.8.8.8/32 routing-table=other\n",
        ):
            with self.subTest(row=row), self.assertRaises(ros.Rejected):
                ros.parse(ros.OPERATIONS[2], [row], {"destination": "8.8.8.8"})

    def test_partial_route_walk_cannot_claim_no_route(self):
        with self.assertRaises(ros.Rejected):
            ros.parse(ros.OPERATIONS[2], [""], {"destination": "8.8.8.8"})

    def test_direct_entrypoint_fails_closed_without_disposable_scope(self):
        self.assertFalse(ros.ready())
        with self.assertRaises(ros.Rejected):
            ros.validate({"operation": ros.OPERATIONS[0], "parameters": {}, "binding": {}, "binding_digest": "0" * 64})


if __name__ == '__main__':
    unittest.main()
