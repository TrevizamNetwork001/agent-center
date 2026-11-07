import http.client as http_client
import ipaddress
import platform
import re
import shutil
import socket
import ssl
import subprocess
import time
import urllib.parse

from . import __version__, mtr, mtr_client
from .dns_transport import query as dns_query
from .policy import authorize

KNOWN = (
    "agent_info",
    "icmp_ping:v1",
    "dns_lookup:v1",
    "tcp_connect:v1",
    "traceroute:v1",
    "http_probe:v1",
    mtr.CAPABILITY,
)


def capability_available(name):
    return (
        name in KNOWN
        and (name != mtr.CAPABILITY or mtr.ready())
        and (
            name not in ("icmp_ping:v1", "traceroute:v1")
            or bool(shutil.which("ping" if name == "icmp_ping:v1" else "traceroute"))
        )
    )


def available():
    return tuple(name for name in KNOWN if capability_available(name))


def self_check():
    # Legacy updater validates this six-capability self-check contract.
    # Extension negotiation uses the normal capabilities endpoint.
    caps = tuple(c for c in available() if c != mtr.CAPABILITY)
    assert len(caps) == len(set(caps)) and set(caps) <= set(KNOWN)
    assert all(callable(HANDLERS[c]) for c in caps)
    return caps


class TargetNotAllowed(ValueError):
    """A valid destination is outside the configured network authorization."""


def target(value, networks):
    ip = ipaddress.ip_address(value)
    if not any(ip in ipaddress.ip_network(net) for net in networks):
        raise TargetNotAllowed("target_not_allowed")
    return str(ip)


def info(args, networks):
    return {
        "version": __version__,
        "hostname": socket.gethostname(),
        "python": platform.python_version(),
        "device_operations_frozen": True,
    }


def ping(args, networks):
    host = target(args["target"], networks)
    binary = shutil.which("ping")
    if not binary:
        raise ValueError("unavailable")
    started = time.monotonic()
    r = subprocess.run(
        [binary, "-n", "-c", "5", "-W", "1", "--", host],
        capture_output=True,
        timeout=12,
        check=False,
        env={"LC_ALL": "C", "PATH": "/usr/bin:/bin"},
    )
    output = getattr(r, "stdout", b"")
    if isinstance(output, bytes):
        output = output.decode("ascii", "replace")
    counts = re.search(r"(\d+) packets transmitted, (\d+) (?:packets )?received", output)
    if not counts:
        raise ValueError("probe_output_unavailable")
    sent, received = int(counts[1]), int(counts[2])
    result = {
        "sent": sent,
        "received": received,
        "loss_percent": round(100 * (sent - received) / max(sent, 1), 2),
        "duration_ms": round((time.monotonic() - started) * 1000, 2),
    }
    rtt = re.search(r"= ([\d.]+)/([\d.]+)/([\d.]+)/", output)
    if rtt:
        result.update(
            zip(("min_ms", "avg_ms", "max_ms"), (float(rtt[i]) for i in range(1, 4)), strict=True)
        )
    return result


def dns(args, networks):
    # Domain authorization is checked in execute before reaching the resolver.
    host = args["target"]
    kind = args.get("record_type")
    if kind is None:
        host = target(host, networks)
        return {"hostname": socket.gethostbyaddr(host)[0][:253]}
    if kind not in ("A", "AAAA"):
        raise ValueError("invalid_record")
    started = time.monotonic()
    try:
        rows = socket.getaddrinfo(
            host, 443, socket.AF_INET if kind == "A" else socket.AF_INET6, socket.SOCK_STREAM
        )
        # DNS records are evidence, not destinations for a new connection.
        # execute() authorizes the queried name. Active probes separately enforce networks.
        answers = sorted({str(ipaddress.ip_address(row[4][0])) for row in rows})[:20]
        error = None if answers else "nodata"
    except socket.gaierror as e:
        answers = []
        error = (
            "nodata"
            if e.errno in (socket.EAI_NONAME, getattr(socket, "EAI_NODATA", socket.EAI_NONAME))
            else "dns_failed"
        )
    return {
        "record_type": kind,
        "answers": answers,
        "duration_ms": round((time.monotonic() - started) * 1000, 2),
        "error": error,
    }


def tcp(args, networks):
    host = target(args["target"], networks)
    port = args["port"]
    if type(port) is not int or not 1 <= port <= 65535:
        raise ValueError("invalid_port")
    started = time.monotonic()
    try:
        with socket.create_connection((host, port), timeout=3):
            connected, error = True, None
    except ConnectionRefusedError:
        connected, error = False, "connection_refused"
    except TimeoutError:
        connected, error = False, "timeout"
    except OSError:
        connected, error = False, "unreachable"
    return {
        "connected": connected,
        "error": error,
        "duration_ms": round((time.monotonic() - started) * 1000, 2),
    }


def trace(args, networks):
    host = target(args["target"], networks)
    binary = shutil.which("traceroute")
    if not binary:
        raise ValueError("unavailable")
    started = time.monotonic()
    r = subprocess.run(
        [binary, "-n", "-m", "5", "-q", "1", "-w", "1", "--", host],
        capture_output=True,
        timeout=10,
        check=False,
        env={"LC_ALL": "C", "PATH": "/usr/bin:/bin"},
    )
    output = getattr(r, "stdout", b"")
    if isinstance(output, bytes):
        output = output.decode("ascii", "replace")
    if r.returncode not in (0, 1):
        raise ValueError("probe_failed")
    hops = []
    for line in output.splitlines():
        tokens = line.split()
        if len(tokens) > 1 and tokens[0].isdigit():
            try:
                hops.append(str(ipaddress.ip_address(tokens[1])))
            except ValueError:
                pass
    return {
        "hops": hops[:5],
        "reached_destination": host in hops,
        "duration_ms": round((time.monotonic() - started) * 1000, 2),
    }


class PinnedHTTPS(http_client.HTTPSConnection):
    def __init__(self, host, ip, port=443):
        super().__init__(host, port=port, timeout=3, context=ssl.create_default_context())
        self.ip = ip

    def connect(self):
        raw = socket.create_connection((self.ip, self.port), timeout=self.timeout)
        try:
            self.sock = self._context.wrap_socket(raw, server_hostname=self.host)
        except Exception:
            raw.close()
            raise


def http_parameters(args, networks):
    p = urllib.parse.urlsplit(args["url"])
    ip = target(args.get("target", p.hostname), networks)
    if (
        p.username
        or p.password
        or p.fragment
        or p.query
        or not p.hostname
        or (
            p.scheme != "https"
            and not (p.scheme == "http" and ipaddress.ip_address(ip).is_loopback)
        )
    ):
        raise ValueError("unsafe_url")
    return p, ip


def http(args, networks):
    p, ip = http_parameters(args, networks)
    started = time.monotonic()
    conn = (
        PinnedHTTPS(p.hostname, ip, p.port or 443)
        if p.scheme == "https"
        else http_client.HTTPConnection(ip, p.port or 80, timeout=3)
    )
    try:
        conn.request(
            "HEAD",
            p.path or "/",
            headers={"Host": p.netloc, "User-Agent": "Agent-Center/" + __version__},
        )
        response = conn.getresponse()
        return {
            "status": response.status,
            "tls": p.scheme == "https",
            "resolved_ip": ip,
            "duration_ms": round((time.monotonic() - started) * 1000, 2),
        }
    finally:
        conn.close()


HANDLERS = dict(zip(KNOWN, (info, ping, dns, tcp, trace, http, mtr_client.collect), strict=True))


def public_ip(value):
    ip = ipaddress.ip_address(value)
    mapped = getattr(ip, "ipv4_mapped", None)
    if mapped is not None:
        ip = mapped
    return ip.is_global and not (
        ip.is_multicast or ip.is_loopback or ip.is_link_local or ip.is_reserved or ip.is_unspecified
    )


def public_name(value):
    return (
        isinstance(value, str)
        and bool(re.fullmatch(r"[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?", value.lower().rstrip(".")))
        and "." in value.rstrip(".")
        and not value.lower()
        .rstrip(".")
        .endswith((".localhost", ".local", ".internal", ".lan", ".invalid", ".test"))
    )


def prepare(name, args, policy, networks=(), dns_names=(), auto_public=False):
    """Authorization shared by execution and inspection; never invokes a probe."""
    if not authorize(name, policy, (name,) if capability_available(name) else ()):
        raise TargetNotAllowed("policy_denied")
    if not isinstance(args, dict):
        raise ValueError("invalid_input")
    if name == "dns_lookup:v1" and args.get("record_type") is not None:
        if args.get("target", "").lower().rstrip(".") not in dns_names and not (
            auto_public and public_name(args.get("target"))
        ):
            raise TargetNotAllowed("policy_denied")
    if name == "http_probe:v1":
        hostname = urllib.parse.urlsplit(args.get("url", "")).hostname
        try:
            target(hostname, networks)
        except ValueError:
            if hostname not in dns_names and not (auto_public and public_name(hostname)):
                raise TargetNotAllowed("policy_denied")
    if auto_public and name in (
        "tcp_connect:v1",
        "icmp_ping:v1",
        "traceroute:v1",
        "http_probe:v1",
    ):
        if name == "tcp_connect:v1" and args.get("port") != 443:
            raise TargetNotAllowed("public_port_not_allowed")
        if public_ip(args["target"]):
            address = ipaddress.ip_address(args["target"])
            networks = (*networks, str(address) + ("/32" if address.version == 4 else "/128"))
    # These handlers previously checked numeric targets immediately before I/O.
    # Keep that same check available to read-only inspection.
    if name in ("icmp_ping:v1", "traceroute:v1", "tcp_connect:v1") or (
        name == "dns_lookup:v1" and args.get("record_type") is None
    ):
        target(args["target"], networks)
    if name == "http_probe:v1":
        http_parameters(args, networks)
    return networks


def execute(name, args, policy, networks=(), dns_names=(), auto_public=False):
    try:
        networks = prepare(name, args, policy, networks, dns_names, auto_public)
        if name == mtr.CAPABILITY:
            return {"ok": True, "data": mtr_client.collect(args, networks, dns_names)}
        if (
            name == "dns_lookup:v1"
            and args.get("record_type") is not None
            and args.get("transport") in ("udp", "tcp")
        ):
            return dns_query(args)
        return {"ok": True, "data": HANDLERS[name](args, networks)}
    except mtr.MtrError as error:
        return {"ok": False, "error": str(error)}
    except TargetNotAllowed:
        return {"ok": False, "error": "policy_denied"}
    except ssl.SSLError:
        return {"ok": False, "error": "tls_failed"}
    except TimeoutError:
        return {"ok": False, "error": "timeout"}
    except (
        OSError,
        ValueError,
        TypeError,
        KeyError,
        subprocess.SubprocessError,
        http_client.HTTPException,
    ):
        return {"ok": False, "error": "action_failed"}
