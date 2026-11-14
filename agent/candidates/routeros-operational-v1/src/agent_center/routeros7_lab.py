"""RouterOS 7.16.2 simulator adapter. Never connects to an inventory address.

Requires a disposable, non-root network namespace and a private lab scope file.
The operational device freeze is never relaxed. No remote flags enable this adapter.
"""

import base64
import fcntl
import hashlib
import ipaddress
import json
import os
import re
import socket
import time
from datetime import UTC, datetime
from pathlib import Path

from .state import read_private

CAPABILITY = "routeros7_lab:v1"
MARKER = Path("/lab/routeros-agent-lab")
PRIVATE = Path("/lab/agent-private")
VERSION = "7.16.2"
OPERATIONS = (
    "DEVICE_IDENTITY_READ",
    "DEVICE_BGP_PEERS_READ",
    "DEVICE_ROUTE_LOOKUP_READ",
    "DEVICE_INTERFACES_READ",
    "DEVICE_INTERFACE_COUNTERS_READ",
    "DEVICE_RESOURCES_READ",
)
RESOURCE = "/system resource print"
BGP_FIELDS = "name remote.address remote.as remote.afi local.afi uptime established instance remote.port remote.id remote.capabilities remote.messages remote.bytes remote.eor remote.hold-time local.role local.address local.port local.as local.id local.cluster-id local.capabilities local.messages local.bytes local.eor output.procid input.procid multihop hold-time keepalive-time last-started prefix-count".split()
ROUTE_FIELDS = "dst-address routing-table gateway immediate-gw distance active dynamic disabled bgp ospf static connect belongs-to afi contribution pref-src scope target-scope vrf-interface suppress-hw-offload local-address debug.fwp-ptr rpki bgp.peer-cache-id bgp.as-path bgp.origin".split()
INTERFACE_FIELDS = "name type mtu actual-mtu l2mtu max-l2mtu mac-address last-link-up-time last-link-down-time link-downs disabled running rx-byte tx-byte rx-packet tx-packet rx-drop tx-drop tx-queue-drop rx-error tx-error fp-rx-byte fp-tx-byte fp-rx-packet fp-tx-packet".split()
RESOURCE_FIELDS = "uptime version build-time factory-software free-memory total-memory cpu cpu-count cpu-frequency cpu-load free-hdd-space total-hdd-space write-sect-since-reboot write-sect-total bad-blocks architecture-name board-name platform".split()


class Rejected(ValueError):
    pass


def digest(value):
    return hashlib.sha256(
        json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=True)
        .replace("/", "\\/")
        .encode()
    ).hexdigest()


def isolated():
    try:
        if (
            os.geteuid() == 0
            or MARKER.is_symlink()
            or MARKER.read_text() != "SIMULATED_ROUTEROS_TASK_ENGINE_V1\n"
        ):
            return False
        routes = Path("/proc/net/route").read_text().splitlines()[1:]
        for line in routes:
            fields = line.split()
            # Only the fixed benchmark subnet of the disposable runner is accepted.
            if fields[1] != "004D12C6" or fields[7] != "F0FFFFFF" or fields[2] != "00000000":
                return False
        if any(line.split()[-1] != "lo" for line in Path("/proc/net/ipv6_route").read_text().splitlines()):
            return False
        return bool(routes)
    except (OSError, IndexError):
        return False


def ready():
    if not isolated():
        return False
    try:
        import paramiko  # Only installed in the disposable candidate image.

        return bool(paramiko.Transport)
    except ImportError:
        return False


def parameters(op, params):
    if params == []:
        params = {}
    if op not in OPERATIONS or not isinstance(params, dict):
        raise Rejected("routeros_invalid_input")
    allowed = {
        OPERATIONS[1]: {"peer"},
        OPERATIONS[2]: {"destination", "table"},
        OPERATIONS[3]: {"interface"},
        OPERATIONS[4]: {"interface"},
    }.get(op, set())
    if set(params) - allowed or any(not isinstance(v, str) for v in params.values()):
        raise Rejected("routeros_invalid_input")
    if op == OPERATIONS[2]:
        try:
            ipaddress.IPv4Address(params["destination"])
        except (ValueError, KeyError):
            raise Rejected("routeros_invalid_input") from None
        if not re.fullmatch(r"[A-Za-z][A-Za-z0-9_-]{0,31}", params.get("table", "main")):
            raise Rejected("routeros_invalid_input")
    if "peer" in params:
        ipaddress.ip_address(params["peer"])
    if "interface" in params and not re.fullmatch(r"[A-Za-z0-9_.:+/-]{1,120}", params["interface"]):
        raise Rejected("routeros_invalid_input")
    return params


def commands(op, params):
    params = parameters(op, params)
    if op == OPERATIONS[2]:
        return [
            f"/routing route print terse without-paging where dst-address={ipaddress.ip_network(params['destination'] + '/' + str(n), strict=False)} && routing-table={params.get('table', 'main')}"
            for n in range(32, -1, -1)
        ]
    return {
        OPERATIONS[0]: ["/system identity print"],
        OPERATIONS[1]: [
            "/routing bgp session print terse without-paging",
            "/routing bgp connection print count-only",
        ],
        OPERATIONS[3]: ["/interface print terse without-paging"],
        OPERATIONS[4]: ["/interface print stats-detail without-paging"],
        OPERATIONS[5]: [RESOURCE],
    }[op]


def bounded(text):
    if (
        len(text.encode()) > 65536
        or (text and not text.endswith("\n"))
        or re.search(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]", text)
    ):
        raise Rejected("routeros_invalid_output")
    return text.replace("\r\n", "\n")


def rows(text, allowed):
    result = []
    for line in bounded(text).strip().splitlines():
        line = line.strip()
        if not line or line.startswith("Flags: "):
            continue
        match = re.fullmatch(r"[0-9]{1,4}\s+(?:([A-Za-z*]+)\s+)?([a-z][a-z0-9.-]*=.*)", line)
        if not match:
            raise Rejected("routeros_invalid_output")
        row, rest = {"flags": match[1] or ""}, match[2]
        while rest:
            field = re.match(r'([a-z][a-z0-9.-]*)=("[^"\\\x00-\x1f]*"|[^\s"\\]+)(?:\s+|$)', rest)
            if not field or field[1] not in allowed or field[1] in row:
                raise Rejected("routeros_invalid_output")
            row[field[1]] = field[2].strip('"')
            rest = rest[len(field[0]) :]
        for flag in (
            "active",
            "dynamic",
            "disabled",
            "bgp",
            "ospf",
            "static",
            "connect",
            "established",
            "running",
        ):
            if flag in row and row[flag] not in ("true", "false"):
                raise Rejected("routeros_invalid_output")
        result.append(row)
        if len(result) > 128:
            raise Rejected("routeros_output_limit")
    return result


def resource(text):
    result = {}
    for line in bounded(text).strip().splitlines():
        match = re.fullmatch(r"\s*([a-z-]+):\s*([^\x00-\x1f]+)", line)
        if not match or match[1] not in RESOURCE_FIELDS or match[1] in result:
            raise Rejected("routeros_invalid_output")
        result[match[1]] = match[2].strip()
    if result.get("version") != VERSION + " (stable)" or "board-name" not in result:
        raise Rejected("routeros_identity_rejected")
    return result


def number(value):
    if value is None:
        return None
    if not re.fullmatch(r"(?:0|[1-9][0-9]{0,15})", value) or int(value) > 9007199254740991:
        raise Rejected("routeros_invalid_output")
    return int(value)


def duration(value):
    if value is None:
        return None
    match = re.fullmatch(
        r"(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?(?:(\d+)ms)?", value
    )
    if not value or not match:
        raise Rejected("routeros_invalid_output")
    result = sum(
        int(v or 0) * unit
        for v, unit in zip(match.groups(), (604800, 86400, 3600, 60, 1, 0.001), strict=True)
    )
    if result > 9007199254740991:
        raise Rejected("routeros_invalid_output")
    return result


def memory(value):
    if value is None:
        return None
    match = re.fullmatch(r"([0-9]+(?:\.[0-9]+)?)([KMGT]iB|B)?", value)
    if not match:
        raise Rejected("routeros_invalid_output")
    result = int(
        float(match[1])
        * {"B": 1, "KiB": 1024, "MiB": 1048576, "GiB": 1073741824, "TiB": 1099511627776}[
            match[2] or "B"
        ]
    )
    if result > 9007199254740991:
        raise Rejected("routeros_invalid_output")
    return result


def label(value):
    if not re.fullmatch(r"[A-Za-z0-9_.:+/-]{1,120}", value):
        raise Rejected("routeros_invalid_output")
    return value


def parse(op, outputs, params):
    params = parameters(op, params)
    if op == OPERATIONS[0]:
        match = re.fullmatch(r"\s*name: ([A-Za-z0-9_.-]{1,64})\n", bounded(outputs[0]))
        if not match:
            raise Rejected("routeros_invalid_output")
        return {"name": match[1], "version": VERSION}
    if op == OPERATIONS[5]:
        r = resource(outputs[0])
        total, free = memory(r.get("total-memory")), memory(r.get("free-memory"))
        cpu = number(r["cpu-load"].removesuffix("%")) if "cpu-load" in r else None
        if (cpu is not None and cpu > 100) or (
            total is not None and free is not None and (total <= 0 or free > total)
        ):
            raise Rejected("routeros_invalid_output")
        return {
            "cpu_percent": cpu,
            "memory_total_bytes": total,
            "memory_free_bytes": free,
            "memory_percent": round(100 * (total - free) / total, 2)
            if total and free is not None
            else None,
            "uptime_seconds": duration(r.get("uptime")),
            "version": VERSION,
            "availability_reason": "MEASUREMENTS_INCOMPLETE"
            if None in (cpu, total, free)
            else None,
        }
    items = []
    if op == OPERATIONS[1]:
        if not re.fullmatch(r"[0-9]{1,6}\n", bounded(outputs[1])):
            raise Rejected("routeros_invalid_output")
        configured = int(outputs[1])
        for r in rows(outputs[0], BGP_FIELDS):
            ipaddress.ip_address(r["remote.address"])
            asn = number(r.get("remote.as"))
            families = r["remote.afi"].split(",") if "remote.afi" in r else None
            if (
                (asn is not None and not 1 <= asn <= 4294967295)
                or (
                    families is not None
                    and set(families)
                    - {"ip", "ipv6", "l2vpn", "l2vpn-cisco", "vpnv4", "vpnv6", "evpn"}
                )
                or ("E" in r["flags"] and r.get("established") == "false")
            ):
                raise Rejected("routeros_invalid_output")
            items.append(
                {
                    "peer": label(r["name"]),
                    "remote_address": r["remote.address"],
                    "remote_asn": asn,
                    "state": "ESTABLISHED"
                    if "E" in r["flags"] or r.get("established") == "true"
                    else "NOT_ESTABLISHED",
                    "uptime_seconds": duration(r.get("uptime")),
                    "advertised_families": families,
                    "negotiated_families": None,
                }
            )
        if items and not configured:
            raise Rejected("routeros_invalid_output")
        unique = "peer"
        data = {
            "items": items,
            "availability_reason": None
            if items
            else ("NO_SESSIONS_OBSERVED" if configured else "NO_BGP_CONNECTIONS_CONFIGURED"),
            "configuration_present": True if configured else None,
            "connections_configured": configured > 0,
        }
    elif op in (OPERATIONS[3], OPERATIONS[4]):
        for r in rows(outputs[0], INTERFACE_FIELDS):
            item = {
                "interface": label(r["name"]),
                "admin_state": ("DOWN" if r["disabled"] == "true" else "UP")
                if "disabled" in r
                else ("DOWN" if "X" in r["flags"] else "UNKNOWN"),
                "oper_state": ("UP" if r["running"] == "true" else "DOWN")
                if "running" in r
                else ("UP" if "R" in r["flags"] else "UNKNOWN"),
                "speed_bps": None,
            }
            item.update(
                {
                    k.replace("-", "_"): number(r.get(k))
                    for k in "rx-byte tx-byte rx-packet tx-packet rx-drop tx-drop tx-queue-drop rx-error tx-error link-downs".split()
                }
            )
            item.update(sample_timestamp=datetime.now(UTC).isoformat(), rate=None)
            items.append(item)
        unique = "interface"
        data = {
            "items": items,
            "availability_reason": None if items else "NO_INTERFACES_OBSERVED",
            "sample_kind": "ACCUMULATED_COUNTERS" if op == OPERATIONS[4] else "STATE",
        }
    else:
        total = 0
        for command, output in zip(commands(op, params), outputs):
            prefix = command.split("dst-address=", 1)[1].split()[0]
            candidates = rows(output, ROUTE_FIELDS)
            total += len(candidates)
            if total > 128:
                raise Rejected("routeros_output_limit")
            for r in candidates:
                if r.get("dst-address") != prefix or r.get("routing-table") != params.get(
                    "table", "main"
                ):
                    raise Rejected("routeros_invalid_output")
                if not active(r):
                    continue
                gateway = r.get("gateway")
                try:
                    ipaddress.ip_address(gateway)
                except ValueError:
                    gateway = None
                protocol = next(
                    (
                        name
                        for flag, key, name in (
                            ("b", "bgp", "BGP"),
                            ("o", "ospf", "OSPF"),
                            ("s", "static", "STATIC"),
                            ("c", "connect", "CONNECTED"),
                        )
                        if flag in r["flags"] or r.get(key) == "true"
                    ),
                    "UNKNOWN",
                )
                items.append(
                    {
                        "prefix": prefix,
                        "protocol": protocol,
                        "configured_gateway": gateway,
                        "effective_next_hop": None,
                        "preference": number(r.get("distance")),
                        "metric": None,
                        "active": True,
                        "dynamic": r["dynamic"] == "true"
                        if "dynamic" in r
                        else (True if "D" in r["flags"] else None),
                    }
                )
            if items:
                break
        if not items and len(outputs) != 33:
            raise Rejected("routeros_invalid_output")
        return {
            "destination": params["destination"],
            "table": params.get("table", "main"),
            "found": bool(items),
            "items": items,
            "lookup_semantics": "LONGEST_ACTIVE_OBSERVED_RIB_PREFIX",
            "forwarding_verified": False,
            "availability_reason": None if items else "NO_ACTIVE_ROUTE_OBSERVED",
        }
    ids = [i[unique] for i in items]
    if len(ids) != len(set(ids)):
        raise Rejected("routeros_invalid_output")
    if "peer" in params:
        data["items"] = [i for i in items if i["remote_address"] == params["peer"]]
    if "interface" in params:
        data["items"] = [i for i in items if i["interface"] == params["interface"]]
    return data


def active(row):
    return (
        ("A" in row["flags"] or row.get("active") == "true")
        and "X" not in row["flags"]
        and row.get("disabled") != "true"
    )


def validate(args):
    if not ready() or set(args) != {"binding", "binding_digest", "operation", "parameters"}:
        raise Rejected("policy_denied")
    binding = args["binding"]
    parameters(args["operation"], args["parameters"])
    if (
        not isinstance(binding, dict)
        or digest(binding) != args["binding_digest"]
        or binding.get("mode") != "SIMULATED"
        or binding.get("destination") != "127.0.0.1"
        or binding.get("port") != 2223
        or binding.get("operation") != args["operation"]
        or binding.get("parameters_digest") != digest(args["parameters"])
    ):
        raise Rejected("policy_denied")
    if (
        type(binding.get("expires_at")) is not int
        or not time.time() < binding["expires_at"] <= time.time() + 900
    ):
        raise Rejected("policy_denied")
    equipment = binding.get("equipment_id", "")
    if not isinstance(equipment, str) or not re.fullmatch(r"[a-f0-9-]{36}", equipment):
        raise Rejected("policy_denied")
    identity = read_private(PRIVATE / "state" / "identity.json")
    if binding.get("agent_id") != identity.get("agent_id") or binding.get("device_operations_frozen") is not True:
        raise Rejected("policy_denied")
    record = read_private(PRIVATE / (equipment + ".json"))
    if record.get("binding_digest") != args["binding_digest"] or record.get("binding") != binding:
        raise Rejected("policy_denied")
    return record


def collect(args):
    record = validate(args)
    import paramiko
    with (PRIVATE / "execution.lock").open("a+") as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise Rejected("routeros_busy") from None
        deadline = time.monotonic() + 5
        budget = 0
        transport = None
        try:
            # No DNS, proxy, jump host, alternate address or operational credentials.
            sock = socket.create_connection(("127.0.0.1", 2223), timeout=1)
            transport = paramiko.Transport(sock)
            transport.start_client(timeout=1)
            fingerprint = "SHA256:" + base64.b64encode(
                hashlib.sha256(transport.get_remote_server_key().asbytes()).digest()
            ).decode().rstrip("=")
            if fingerprint != args["binding"]["fingerprint"]:
                raise Rejected("routeros_identity_rejected")
            import io

            key = paramiko.RSAKey.from_private_key(io.StringIO(record["private_key"]))
            transport.auth_publickey("lab-readonly", key)
            if not transport.is_authenticated():
                raise Rejected("routeros_auth_failed")

            def read(command):
                nonlocal budget
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    raise TimeoutError()
                channel = transport.open_session(timeout=remaining)
                channel.settimeout(remaining)
                try:
                    channel.exec_command(command)
                    raw = bytearray()
                    while True:
                        if time.monotonic() >= deadline:
                            raise TimeoutError()
                        if channel.recv_stderr_ready():
                            raise Rejected("routeros_invalid_output")
                        if channel.recv_ready():
                            chunk = channel.recv(4096)
                            raw.extend(chunk)
                            budget += len(chunk)
                            if budget > 65536:
                                raise Rejected("routeros_output_limit")
                        elif channel.exit_status_ready():
                            break
                        else:
                            time.sleep(0.005)
                    if channel.recv_exit_status() != 0:
                        raise Rejected("routeros_invalid_output")
                    return bounded(raw.decode("utf-8", errors="strict"))
                finally:
                    channel.close()

            identity = resource(read(RESOURCE))
            if identity["board-name"] != args["binding"]["model"]:
                raise Rejected("routeros_identity_rejected")
            outputs = []
            for command in commands(args["operation"], args["parameters"]):
                validate(args)  # Local revocation/policy change closes even a running query.
                output = read(command)
                outputs.append(output)
                if args["operation"] == OPERATIONS[2] and any(
                    active(r) for r in rows(output, ROUTE_FIELDS)
                ):
                    break
            data = parse(args["operation"], outputs, args["parameters"])
            validate(args)
            return {
                "schema_version": 1,
                "contract_id": args["operation"],
                "mode": "SIMULATED",
                "environment": "LAB",
                "identity": {
                    "manufacturer": "mikrotik",
                    "model": identity["board-name"],
                    "platform_family": "ROUTEROS7",
                    "os_version": VERSION,
                },
                "complete": False,
                "status": "PARTIAL",
                "data": data,
                "binding_digest": args["binding_digest"],
            }
        except paramiko.AuthenticationException:
            raise Rejected("routeros_auth_failed") from None
        except (paramiko.SSHException, EOFError):
            raise Rejected("routeros_transport_failed") from None
        finally:
            if transport:
                transport.close()
