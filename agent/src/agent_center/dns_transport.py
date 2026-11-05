"""Bounded A/AAAA queries to the host's configured DNS resolvers over UDP/TCP."""

import ipaddress
import secrets
import socket
import struct
import time

from .network_inventory import resolvers


class InvalidResponse(ValueError):
    pass


def name_at(packet, offset, visited=None):
    visited = set() if visited is None else visited
    if offset in visited or len(visited) > 32:
        raise InvalidResponse()
    visited.add(offset)
    labels = []
    end = None
    while True:
        if offset >= len(packet):
            raise InvalidResponse()
        size = packet[offset]
        if size & 0xC0 == 0xC0:
            if offset + 1 >= len(packet):
                raise InvalidResponse()
            pointer = ((size & 0x3F) << 8) | packet[offset + 1]
            suffix, _ = name_at(packet, pointer, visited)
            labels.extend(suffix.split(".") if suffix else [])
            end = offset + 2
            break
        if size & 0xC0 or size > 63 or offset + size + 1 > len(packet):
            raise InvalidResponse()
        offset += 1
        if size == 0:
            end = offset
            break
        labels.append(packet[offset : offset + size].decode("ascii").lower())
        offset += size
        if len(labels) > 127:
            raise InvalidResponse()
    name = ".".join(labels)
    if len(name) > 253:
        raise InvalidResponse()
    return name, end


def parse(packet, identifier, hostname, qtype):
    if not 12 <= len(packet) <= 65535:
        raise InvalidResponse()
    transaction, flags, questions, answers, _, _ = struct.unpack("!6H", packet[:12])
    if (
        transaction != identifier
        or not flags & 0x8000
        or flags & 0x7800
        or questions != 1
        or answers > 128
    ):
        raise InvalidResponse()
    name, offset = name_at(packet, 12)
    if (
        offset + 4 > len(packet)
        or name != hostname
        or struct.unpack("!2H", packet[offset : offset + 4]) != (qtype, 1)
    ):
        raise InvalidResponse()
    offset += 4
    values = []
    truncated = bool(flags & 0x0200)
    for _ in range(answers):
        try:
            _, offset = name_at(packet, offset)
            if offset + 10 > len(packet):
                raise InvalidResponse()
            kind, klass, _, size = struct.unpack("!2HIH", packet[offset : offset + 10])
            offset += 10
            if offset + size > len(packet):
                raise InvalidResponse()
            if kind == qtype and klass == 1:
                if size != (4 if qtype == 1 else 16):
                    raise InvalidResponse()
                values.append(str(ipaddress.ip_address(packet[offset : offset + size])))
            offset += size
        except InvalidResponse:
            if truncated:
                break
            raise
    return sorted(set(values))[:20], flags & 15, truncated


def exact(stream, count):
    data = b""
    while len(data) < count:
        chunk = stream.recv(count - len(data))
        if not chunk:
            raise InvalidResponse()
        data += chunk
    return data


def query(args):
    resolver = str(ipaddress.ip_address(args["resolver"]))
    if resolver not in resolvers():
        return {"ok": False, "error": "policy_denied"}
    hostname = args["target"].lower().rstrip(".")
    labels = hostname.encode("idna").split(b".")
    if not hostname or len(hostname) > 253 or any(not 1 <= len(v) <= 63 for v in labels):
        raise ValueError()
    qtype = {"A": 1, "AAAA": 28}[args["record_type"]]
    identifier = secrets.randbits(16)
    request = (
        struct.pack("!6H", identifier, 0x0100, 1, 0, 0, 0)
        + b"".join(bytes([len(v)]) + v for v in labels)
        + b"\0"
        + struct.pack("!2H", qtype, 1)
    )
    started = time.monotonic()
    try:
        if args["transport"] == "udp":
            family = socket.AF_INET6 if ":" in resolver else socket.AF_INET
            with socket.socket(family, socket.SOCK_DGRAM) as stream:
                stream.settimeout(3)
                stream.connect((resolver, 53))
                stream.send(request)
                packet = stream.recv(4096)
        elif args["transport"] == "tcp":
            with socket.create_connection((resolver, 53), timeout=3) as stream:
                stream.sendall(struct.pack("!H", len(request)) + request)
                size = struct.unpack("!H", exact(stream, 2))[0]
                packet = exact(stream, size)
        else:
            raise ValueError()
        answers, rcode, truncated = parse(packet, identifier, hostname, qtype)
        return {
            "ok": True,
            "data": {
                "answers": [] if rcode else answers,
                "record_type": args["record_type"],
                "duration_ms": round((time.monotonic() - started) * 1000, 2),
                "error": "dns_failed" if rcode else (None if answers or truncated else "nodata"),
                "transport": args["transport"],
                "resolver": resolver,
                "rcode": rcode,
                "truncated": truncated,
            },
        }
    except (InvalidResponse, UnicodeError, struct.error):
        return {"ok": False, "error": "dns_failed"}
    except TimeoutError:
        return {"ok": False, "error": "timeout"}
    except ConnectionRefusedError:
        return {"ok": False, "error": "connection_refused"}
    except OSError:
        return {"ok": False, "error": "unreachable"}
