"""Bounded MTR collection. No installation, elevation, shell, ASN or reverse queries."""

import ipaddress
import json
import math
import os
import re
import selectors
import shutil
import socket
import subprocess
import time

CAPABILITY = "mtr_path_analysis:v1"
ENV = {"LC_ALL": "C", "PATH": "/usr/sbin:/usr/bin:/sbin:/bin"}
LIMIT = 49152


class MtrError(ValueError):
    pass


def bounded(command, timeout):
    # Drain both pipes while the child runs; never accumulate arbitrary output or deadlock.
    with subprocess.Popen(
        command, stdout=subprocess.PIPE, stderr=subprocess.PIPE, env=ENV
    ) as child:
        streams = selectors.DefaultSelector()
        for stream in (child.stdout, child.stderr):
            os.set_blocking(stream.fileno(), False)
            streams.register(stream, selectors.EVENT_READ)
        output = {child.stdout: bytearray(), child.stderr: bytearray()}
        end = time.monotonic() + timeout
        try:
            while streams.get_map():
                if time.monotonic() >= end:
                    raise MtrError("execution_timeout")
                for key, _ in streams.select(min(0.1, max(0, end - time.monotonic()))):
                    data = os.read(key.fileobj.fileno(), 4096)
                    if not data:
                        streams.unregister(key.fileobj)
                    else:
                        output[key.fileobj].extend(data)
                        if sum(map(len, output.values())) > LIMIT:
                            raise MtrError("mtr_output_limit")
            child.wait(timeout=max(0.01, end - time.monotonic()))
            return child.returncode, bytes(output[child.stdout]), bytes(output[child.stderr])
        finally:
            streams.close()
            if child.poll() is None:
                child.kill()
            child.wait()


def installation():
    binary = shutil.which("mtr")
    if not binary:
        raise MtrError("mtr_not_installed")
    rc, version, _ = bounded([binary, "--version"], 1)
    if rc or not re.search(rb"\bmtr\s+[0-9]+\.[0-9]+", version.lower()):
        raise MtrError("mtr_unsupported")
    rc, help_text, _ = bounded([binary, "--help"], 1)
    needed = (
        b"--json",
        b"--report",
        b"--no-dns",
        b"--report-cycles",
        b"--max-ttl",
        b"--interval",
        b"--tcp",
        b"--port",
    )
    if rc or any(option not in help_text for option in needed):
        raise MtrError("mtr_unsupported")
    return binary


def permitted(value, networks):
    ip = ipaddress.ip_address(value)
    return any(ip in ipaddress.ip_network(net) for net in networks)


def authorize(args, networks, dns_names):
    if set(args) != {"target", "destination", "protocol", "cycles", "max_hops", "interval"}:
        raise MtrError("mtr_invalid_input")
    if (
        args["protocol"] not in ("icmp", "tcp")
        or type(args["cycles"]) is not int
        or not 5 <= args["cycles"] <= 10
    ):
        raise MtrError("mtr_invalid_input")
    if (
        type(args["max_hops"]) is not int
        or not 1 <= args["max_hops"] <= 16
        or type(args["interval"]) is not int
        or args["interval"] != 1
    ):
        raise MtrError("mtr_invalid_input")
    ip = ipaddress.ip_address(args["target"])
    destination = args["destination"]
    if not isinstance(destination, str) or len(destination) > 253:
        raise MtrError("mtr_invalid_input")
    try:
        literal = ipaddress.ip_address(destination)
    except ValueError:
        literal = None
    network_allowed = permitted(str(ip), networks)
    if literal is not None:
        if literal != ip or not network_allowed:
            raise MtrError("policy_denied")
    else:
        if (
            not re.fullmatch(r"[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?", destination)
            or destination not in dns_names
        ):
            raise MtrError("policy_denied")
        # Re-resolve for authorization, then execute *only* the numeric pinned address.
        # Reject mixed private/public answers unless each non-global address has explicit CIDR authorization.
        rows = socket.getaddrinfo(destination, 443, type=socket.SOCK_STREAM)
        addresses = {ipaddress.ip_address(r[4][0]) for r in rows}
        if ip not in addresses or any(
            not a.is_global and not permitted(str(a), networks) for a in addresses
        ):
            raise MtrError("policy_denied")
        if not ip.is_global and not network_allowed:
            raise MtrError("policy_denied")
    if ip.is_multicast or ip.is_unspecified or ip.is_link_local or getattr(ip, "ipv4_mapped", None):
        raise MtrError("policy_denied")
    return str(ip)


def parse(raw, target, cycles, max_hops):
    if not raw or len(raw) > LIMIT:
        raise MtrError("mtr_invalid_output")
    try:
        report = json.loads(raw)
        hubs = report["report"]["hubs"]
        if not isinstance(hubs, list) or not 1 <= len(hubs) <= max_hops:
            raise ValueError()
        hops, sanitized, previous = [], [], 0
        for hub in hubs:
            index, host = hub["count"], hub["host"]
            if type(index) is not int or not previous < index <= max_hops:
                raise ValueError()
            previous = index
            address = None if host == "???" else str(ipaddress.ip_address(host))
            values = {k: hub[k] for k in ("Loss%", "Snt", "Avg", "Best", "Wrst", "StDev")}
            if any(
                type(v) not in (int, float) or not math.isfinite(v) or v < 0 or v > 60000
                for v in values.values()
            ):
                raise ValueError()
            sent, loss = values["Snt"], values["Loss%"]
            if (
                type(sent) is not int
                or not 1 <= sent <= cycles
                or loss > 100
                or not values["Best"] <= values["Avg"] <= values["Wrst"]
            ):
                raise ValueError()
            if address is None and loss != 100:
                raise ValueError()
            if address is None and any(values[k] for k in ("Avg", "Best", "Wrst", "StDev")):
                raise ValueError()
            # MTR Loss% is rounded; do not manufacture received counts from a rounded percentage.
            received = hub.get("Rcv")
            if received is not None and (
                type(received) is not int
                or not 0 <= received <= sent
                or abs(loss - 100 * (sent - received) / sent) > 0.11
            ):
                raise ValueError()
            responded = address is not None and loss < 100
            hops.append(
                {
                    "hop_index": index,
                    "address": address,
                    "hostname": None,
                    "sent": sent,
                    "received": received,
                    "loss_percent": loss,
                    "avg_ms": values["Avg"] if responded else None,
                    "best_ms": values["Best"] if responded else None,
                    "worst_ms": values["Wrst"] if responded else None,
                    "stdev_ms": values["StDev"] if responded else None,
                    "responded": responded,
                    "asn": None,
                }
            )
            sanitized.append({"count": index, "host": address or "???", **values})
        reached = hops[-1]["address"] == target and hops[-1]["responded"]
        return hops, reached, {"report": {"hubs": sanitized}}
    except (ValueError, TypeError, KeyError, RecursionError):
        raise MtrError("mtr_invalid_output") from None


def collect(args, networks, dns_names):
    started = time.monotonic()
    host = authorize(args, networks, dns_names)
    if os.geteuid() == 0:
        raise MtrError("mtr_permission_denied")
    binary = installation()
    command = [
        binary,
        "--report",
        "--json",
        "--no-dns",
        "--report-cycles",
        str(args["cycles"]),
        "--max-ttl",
        str(args["max_hops"]),
        "--interval",
        "1",
        "--gracetime",
        "1",
        "-4" if ipaddress.ip_address(host).version == 4 else "-6",
    ]
    if args["protocol"] == "tcp":
        command += ["--tcp", "--port", "443"]
    command += ["--", host]
    rc, raw, stderr = bounded(command, max(0.1, 18 - (time.monotonic() - started)))
    if rc:
        if any(
            word in stderr.lower() for word in (b"permission denied", b"operation not permitted")
        ):
            raise MtrError("mtr_permission_denied")
        raise MtrError("mtr_execution_failed")
    hops, reached, evidence = parse(raw, host, args["cycles"], args["max_hops"])
    evidence["report"]["mtr"] = {"protocol": args["protocol"]}
    return {
        "destination": args["destination"],
        "resolved_ip": host,
        "protocol": args["protocol"],
        "cycles": args["cycles"],
        "total_duration_ms": round((time.monotonic() - started) * 1000, 2),
        "destination_reached": reached,
        "hops": hops,
        "status": "collected",
        "error": None,
        "raw_sanitized": evidence,
    }


def ready():
    """Never advertise live collection with the default empty capability bounding set."""
    try:
        if os.geteuid() == 0:
            return False
        with open("/proc/self/status", encoding="ascii") as status_file:
            status = status_file.read()
        match = re.search(r"^CapEff:\s*([0-9a-f]+)$", status, re.MULTILINE)
        if not match or not int(match[1], 16) & (1 << 13):
            return False
        installation()
        return True
    except (OSError, ValueError, subprocess.SubprocessError):
        return False
