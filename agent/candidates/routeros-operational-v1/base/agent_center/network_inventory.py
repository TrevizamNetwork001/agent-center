"""Bounded, read-only Linux network inventory; never configures an interface."""

import functools
import ipaddress
import json
import shutil
import subprocess
import time
from pathlib import Path


def resolvers():
    values = []
    try:
        for line in Path("/etc/resolv.conf").read_text().splitlines():
            parts = line.split()
            if len(parts) >= 2 and parts[0] == "nameserver":
                try:
                    ip = ipaddress.ip_address(parts[1])
                    if "%" not in str(ip) and not ip.is_unspecified and not ip.is_multicast:
                        values.append(str(ip))
                except ValueError:
                    continue
    except OSError:
        pass
    return list(dict.fromkeys(values))[:4]


@functools.lru_cache(maxsize=2)
def _addresses(bucket):
    binary = shutil.which("ip")
    if not binary:
        return []
    try:
        result = subprocess.run(
            [binary, "-json", "address", "show"], capture_output=True, timeout=1, check=True
        )
        if len(result.stdout) > 262144:
            return []
        addresses = []
        for interface in json.loads(result.stdout):
            for row in interface.get("addr_info", []):
                ip = ipaddress.ip_address(row["local"])
                if not ip.is_loopback and not ip.is_unspecified and not ip.is_multicast:
                    addresses.append(str(ip))
        return sorted(set(addresses))[:32]
    except (OSError, ValueError, TypeError, KeyError, subprocess.SubprocessError):
        return []


def addresses():
    return _addresses(int(time.monotonic() // 60))
