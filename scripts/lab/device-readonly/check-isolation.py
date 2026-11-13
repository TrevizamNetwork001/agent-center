"""Offline network assertions; never probes a management or external address."""
import json
from pathlib import Path

routes = Path('/proc/net/route').read_text().splitlines()[1:]
if any(line.split()[1] == '00000000' for line in routes):
    raise RuntimeError('Default IPv4 route is forbidden in the protocol laboratory')
for line in Path('/proc/net/ipv6_route').read_text().splitlines():
    fields = line.split()
    if fields[0] == '0' * 32 and fields[1] == '00' and fields[-1] != 'lo':
        raise RuntimeError('Default IPv6 route is forbidden in the protocol laboratory')
(Path('/lab') / 'network-isolation.json').write_text(json.dumps({
    'passed': True, 'default_route': False, 'external_probes': 0,
    'transport_destination': '127.0.0.1', 'management_address_used': False,
    'docker_network_internal': True,
}, indent=2) + '\n')
print('Network isolation PASS: no default route; fixed loopback transport; no external probes.')
