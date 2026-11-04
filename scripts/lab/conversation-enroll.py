"""Technical lab tooling: own loopback Central and actual unchanged Agent 0.5.2."""
import json
import sys
import time
from pathlib import Path
from agent_center.api import Client
from agent_center.identity import Identity
from agent_center.state import atomic_write
lab = Path(sys.argv[1])
credentials = json.loads((lab / 'credentials.json').read_text())
client = Client('http://127.0.0.1:18084', retries=0)
for _ in range(20):
    r = client.call('enroll', {'code': credentials['code'], 'hostname': 'conversation-lab', 'version': '0.5.2', 'capabilities': ['agent_info', 'dns_lookup:v1', 'tcp_connect:v1', 'http_probe:v1', 'icmp_ping:v1', 'traceroute:v1']})
    if r.ok: break
    if r.status and r.status < 500: raise SystemExit('Lab enrollment rejected: '+str(r.status))
    time.sleep(0.25)
else:
    raise SystemExit('Lab Central unavailable; no payload emitted')
identity = Identity.validate(r.body)
(lab / 'state').mkdir(mode=0o700)
identity.save(lab / 'state' / 'identity.json')
atomic_write(lab / 'config.json', {'central_url':'http://127.0.0.1:18084','allow_http_loopback': True,'state_dir':'/lab/state','identity_file':'/lab/state/identity.json','log_dir':'/lab/logs','heartbeat_interval':20,'poll_interval':2,'timeout':5,'device_operations_frozen':True,'allowed_networks':['127.0.0.0/8','::1/128'],'allowed_dns_names':['localhost']})
credentials.pop('code')
atomic_write(lab / 'credentials.json', credentials)
print('Actual Python Agent lab enrollment PASS; no token output')
