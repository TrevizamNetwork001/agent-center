import ipaddress
import struct
import unittest

from agent_center.dns_transport import InvalidResponse, parse


def name(value):
    return b''.join(bytes([len(label)]) + label.encode('ascii') for label in value.split('.')) + b'\0'


def answer(owner, kind, data):
    return name(owner) + struct.pack('!HHIH', kind, 1, 60, len(data)) + data


def packet(records):
    question = name('example.test') + struct.pack('!HH', 1, 1)
    return struct.pack('!6H', 7, 0x8180, 1, len(records), 0, 0) + question + b''.join(records)


class DnsAnswerBinding(unittest.TestCase):
    def test_unrelated_address_is_not_an_answer_to_the_question(self):
        raw = packet([answer('other.test', 1, ipaddress.ip_address('203.0.113.99').packed)])
        self.assertEqual(parse(raw, 7, 'example.test', 1)[0], [])

    def test_cname_chain_only_returns_addresses_bound_to_the_question(self):
        records = [answer('other.test', 1, ipaddress.ip_address('203.0.113.99').packed),
                   answer('alias.test', 1, ipaddress.ip_address('203.0.113.7').packed),
                   answer('example.test', 5, name('alias.test'))]
        self.assertEqual(parse(packet(records), 7, 'example.test', 1)[0], ['203.0.113.7'])

    def test_cname_loop_is_rejected(self):
        records = [answer('example.test', 5, name('alias.test')),
                   answer('alias.test', 5, name('example.test'))]
        with self.assertRaises(InvalidResponse):
            parse(packet(records), 7, 'example.test', 1)

    def test_conflicting_aliases_are_rejected(self):
        records = [answer('example.test', 5, name('alias.test')),
                   answer('example.test', 5, name('other.test'))]
        with self.assertRaises(InvalidResponse):
            parse(packet(records), 7, 'example.test', 1)
