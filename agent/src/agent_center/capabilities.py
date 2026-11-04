import ipaddress
import platform
import shutil
import socket
import subprocess
import urllib.parse
import urllib.request
from http.client import HTTPException

from .api import NoRedirect
from .policy import authorize

KNOWN = (
    "agent_info",
    "icmp_ping:v1",
    "dns_lookup:v1",
    "tcp_connect:v1",
    "traceroute:v1",
    "http_probe:v1",
)


def available():
    return tuple(
        v
        for v in KNOWN
        if v not in ("icmp_ping:v1", "traceroute:v1")
        or shutil.which("ping" if v == "icmp_ping:v1" else "traceroute")
    )


def self_check():
    caps = available()
    assert len(caps) == len(set(caps)) and set(caps) <= set(KNOWN)
    assert all(callable(HANDLERS[c]) for c in caps)
    return caps


def target(value, networks):
    ip = ipaddress.ip_address(value)
    if not any(ip in ipaddress.ip_network(net) for net in networks):
        raise ValueError("target_not_allowed")
    return str(ip)


def info(args, networks):
    return {
        "version": "0.5.2",
        "hostname": socket.gethostname(),
        "python": platform.python_version(),
        "device_operations_frozen": True,
    }


def ping(args, networks):
    host = target(args["target"], networks)
    binary = shutil.which("ping")
    if not binary:
        raise ValueError("unavailable")
    r = subprocess.run(
        [binary, "-n", "-c", "1", "-W", "2", "--", host],
        capture_output=True,
        timeout=5,
        check=False,
    )
    return {"reachable": r.returncode == 0}


def dns(args, networks):
    # Read-only reverse lookup restricted to explicitly allowed numeric targets.
    host = target(args["target"], networks)
    name = socket.gethostbyaddr(host)[0]
    return {"hostname": name[:253]}


def tcp(args, networks):
    host = target(args["target"], networks)
    port = args["port"]
    if type(port) is not int or not 1 <= port <= 65535:
        raise ValueError("invalid_port")
    with socket.create_connection((host, port), timeout=3):
        return {"connected": True}


def trace(args, networks):
    host = target(args["target"], networks)
    binary = shutil.which("traceroute")
    if not binary:
        raise ValueError("unavailable")
    r = subprocess.run(
        [binary, "-n", "-m", "5", "-q", "1", "-w", "1", "--", host],
        capture_output=True,
        timeout=10,
        check=False,
    )
    return {"completed": r.returncode == 0}


def http(args, networks):
    url = args["url"]
    p = urllib.parse.urlsplit(url)
    ip = target(p.hostname, networks)
    if (
        p.username
        or p.password
        or p.fragment
        or (
            p.scheme != "https"
            and not (p.scheme == "http" and ipaddress.ip_address(ip).is_loopback)
        )
    ):
        raise ValueError("unsafe_url")
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), NoRedirect())
    with opener.open(urllib.request.Request(url, method="HEAD"), timeout=3) as r:
        return {"status": r.status}


HANDLERS = dict(zip(KNOWN, (info, ping, dns, tcp, trace, http), strict=True))


def execute(name, args, policy, networks=()):
    if not authorize(name, policy, available()):
        return {"ok": False, "error": "policy_denied"}
    try:
        if not isinstance(args, dict):
            raise ValueError()
        return {"ok": True, "data": HANDLERS[name](args, networks)}
    except (
        OSError,
        ValueError,
        TypeError,
        KeyError,
        subprocess.SubprocessError,
        HTTPException,
    ):
        return {"ok": False, "error": "action_failed"}
