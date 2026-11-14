"""Offline native-systemd probe. No config, credentials, sockets or device access."""
import json
import os
import sys
from pathlib import Path

sys.path.insert(0, '/tmp')
import bcrypt
import cffi
import cryptography
import nacl
import paramiko
from agent_center import __version__
from agent_center.capabilities import available, self_check

status = dict(line.split(':', 1) for line in Path('/proc/self/status').read_text().splitlines() if ':' in line)
assert os.geteuid() == 65534
assert int(status['CapEff'].strip(), 16) == 0
assert int(status['CapBnd'].strip(), 16) == 0
assert status['NoNewPrivs'].strip() == '1'
assert Path('/proc/1/comm').read_text().strip() == 'systemd'
assert 'VERSION_ID="13"' in Path('/etc/os-release').read_text()
assert __version__ == '0.6.6'
assert 'routeros7_lab:v1' not in available()
assert sys.version_info[:2] == (3, 13)
self_check()
Path('/tmp/probe-result.json').write_text(json.dumps({
    'agent_version': __version__, 'uid': os.geteuid(), 'python': sys.version.split()[0],
    'systemd_pid1': True, 'effective_capabilities': 0, 'bounding_capabilities': 0, 'no_new_privileges': True, 'debian': '13', 'network_mode': 'none',
    'paramiko': paramiko.__version__, 'cryptography': cryptography.__version__,
    'bcrypt': bcrypt.__version__, 'pynacl': nacl.__version__, 'cffi': cffi.__version__,
    'lab_capability_advertised': False, 'kind': 'OFFLINE_STARTUP_PROBE_ONLY',
}, indent=2)+'\n')
