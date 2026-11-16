"""Read-only observations over the existing heartbeat and authenticated Unix socket."""

import hashlib
import ipaddress
import json
import socket
from datetime import UTC, datetime
from pathlib import Path

from . import __version__, mtr, mtr_client
from .capabilities import HANDLERS, KNOWN, TargetNotAllowed, capability_available, prepare
from .config import Config
from .managed_settings import normalize
from .policy import Policy, authorize
from .state import StateError, atomic_write, read_private

SCHEMA = 1
LIMIT = 16384


def timestamp():
    return datetime.now(UTC).isoformat(timespec="microseconds")


def digest(value):
    return hashlib.sha256(
        json.dumps(value, sort_keys=True, separators=(",", ":")).encode()
    ).hexdigest()


def managed(state_dir):
    value = normalize(read_private(state_dir / "managed-settings.json"))
    # Source IPs govern communication, not target authorization; never transmit them here.
    return {
        key: value[key]
        for key in ("revision", "allowed_networks", "allowed_dns_names", "auto_public_targets")
    }


def record_applied(state_dir):
    settings = managed(state_dir)
    path = state_dir / "policy-observed.json"
    observed = {"revision": settings["revision"], "digest": digest(settings)}
    try:
        previous = read_private(path)
        if all(previous.get(key) == value for key, value in observed.items()):
            return
    except FileNotFoundError:
        pass
    atomic_write(path, observed | {"applied_at": timestamp()})


def snapshot(state_dir, policy=None):
    report = {
        "schema_version": SCHEMA,
        "collected_at": timestamp(),
        "version": __version__,
        "policy_revision": None,
        "policy_digest": None,
        "policy_applied_at": None,
        "base": None,
        "ceiling": None,
        "managed": None,
        "helper": None,
        "capabilities": [],
        "last_error": None,
    }
    try:
        settings = managed(state_dir)
        report.update(
            managed=settings, policy_revision=settings["revision"], policy_digest=digest(settings)
        )
        try:
            observed = read_private(state_dir / "policy-observed.json")
            if observed.get("revision") == settings["revision"] and observed.get(
                "digest"
            ) == digest(settings):
                report["policy_applied_at"] = observed.get("applied_at")
        except (StateError, OSError, ValueError, TypeError):
            pass
    except (StateError, OSError, ValueError, TypeError):
        report["last_error"] = "POLICY_NOT_APPLIED"
    try:
        result = mtr_client.exchange({"op": "observe", "protocol_version": SCHEMA})
        if (
            result.get("schema_version") == SCHEMA
            and result.get("ok") is True
            and all(isinstance(result.get(key), dict) for key in ("base", "ceiling", "helper"))
        ):
            report.update(base=result["base"], ceiling=result["ceiling"], helper=result["helper"])
        else:
            report["last_error"] = "UNSUPPORTED_VERSION"
    except (mtr.MtrError, KeyError, TypeError):
        report["last_error"] = "HELPER_UNAVAILABLE"
        report["helper"] = {
            "name": "mtr",
            "version": None,
            "installed": None,
            "service_active": None,
            "socket_available": None,
            "authenticated": False,
            "healthy": None,
        }
        try:
            report["helper"]["socket_available"] = mtr_client.SOCKET.is_socket()
        except OSError:
            pass
    parsed = (
        Policy.parse(policy, policy.get("agent_id"), KNOWN)
        if isinstance(policy, dict)
        else Policy(False)
    )
    for name in KNOWN:
        state, reason = "UNKNOWN", "STATUS_UNKNOWN"
        if parsed.valid and not parsed.tasks:
            state, reason = "BLOCKED", "POLICY_NOT_APPLIED"
        elif parsed.valid and not authorize(name, parsed, KNOWN):
            state, reason = "BLOCKED", "MANAGED_POLICY_DENIED"
        elif name == mtr.CAPABILITY:
            if report["helper"] is None:
                state, reason = "UNKNOWN", report["last_error"] or "HELPER_UNAVAILABLE"
            elif report["helper"].get("authenticated") is not True:
                state, reason = "UNAVAILABLE", "HELPER_UNAVAILABLE"
            elif report["helper"].get("healthy") is False:
                state, reason = "UNAVAILABLE", "HELPER_UNHEALTHY"
            elif report["helper"].get("healthy") is None:
                state, reason = "UNKNOWN", "STATUS_UNKNOWN"
            elif report["managed"] is None:
                state, reason = "BLOCKED", "POLICY_NOT_APPLIED"
            elif parsed.valid:
                state, reason = "AVAILABLE", None
        if name != mtr.CAPABILITY and not capability_available(name):
            state, reason = "UNAVAILABLE", "DEPENDENCY_MISSING"
        installed = callable(HANDLERS.get(name))
        if not installed:
            state, reason = "UNAVAILABLE", "CAPABILITY_UNAVAILABLE"
        report["capabilities"].append(
            {
                "name": name,
                "installed": installed,
                "state": state,
                "reason": reason,
                "permitted": parsed.tasks and authorize(name, parsed, KNOWN)
                if parsed.valid
                else None,
                "healthy": report["helper"].get("healthy")
                if name == mtr.CAPABILITY and report["helper"]
                else None,
            }
        )
    if len(json.dumps(report).encode()) > LIMIT:
        return {
            "schema_version": SCHEMA,
            "collected_at": timestamp(),
            "last_error": "STATUS_UNKNOWN",
        }
    return report


def check_standard(request, config, policy, result):
    name, destination = request["capability"], request["destination"]
    if not capability_available(name):
        return result | {"state": "DENIED", "reason": "DEPENDENCY_MISSING"}
    args = {"target": destination}
    if name == "dns_lookup:v1":
        args["record_type"] = "A"
    elif name != "agent_info":
        try:
            ipaddress.ip_address(destination)
        except ValueError:
            args["target"] = socket.getaddrinfo(destination, 443, type=socket.SOCK_STREAM)[0][4][0]
    if name == "tcp_connect:v1":
        args["port"] = 443
    if name == "http_probe:v1":
        try:
            host = (
                f"[{destination}]"
                if ipaddress.ip_address(destination).version == 6
                else destination
            )
        except ValueError:
            host = destination
        args["url"] = "https://" + host + "/"
    try:
        prepare(
            name,
            args,
            policy,
            config.allowed_networks,
            config.allowed_dns_names,
            config.auto_public_targets,
        )
    except TargetNotAllowed:
        # Use the same authorizer with the base envelope to identify the denying layer.
        # The existing executor applies the administrative helper ceiling only to MTR.
        try:
            prepare(
                name,
                args,
                policy,
                config.base_networks,
                config.base_dns_names,
                config.base_auto_public_targets,
            )
        except TargetNotAllowed:
            return result | {"state": "DENIED", "reason": "POLICY_BASE_DENIED", "layer": "base"}
        return result | {"state": "DENIED", "reason": "MANAGED_POLICY_DENIED", "layer": "managed"}
    return result | {"state": "ALLOWED", "reason": None, "layer": None}


def check(request, config_path, policy, agent_id):
    """No collect, no handler invocation, no probes. DNS is part of real authorization."""
    if not isinstance(request, dict) or any(
        not isinstance(request.get(key), str) or not 1 <= len(request[key]) <= maximum
        for key, maximum in (("id", 36), ("capability", 60), ("destination", 253), ("protocol", 8))
    ):
        return None
    result = {
        "id": request["id"],
        "state": "UNKNOWN",
        "reason": "STATUS_UNKNOWN",
        "layer": None,
        "checked_at": timestamp(),
        "revision": None,
        "digest": None,
    }
    if request.get("capability", "").startswith("device_"):
        return result | {"state": "DENIED", "reason": "DEVICE_OPERATIONS_FROZEN", "layer": "freeze"}
    name = request["capability"]
    if name not in KNOWN or not callable(HANDLERS.get(name)):
        return result | {"state": "DENIED", "reason": "CAPABILITY_UNAVAILABLE"}
    parsed = Policy.parse(policy, agent_id, KNOWN)
    if not parsed.valid or not parsed.tasks:
        return result | {"reason": "POLICY_NOT_APPLIED", "layer": "managed"}
    if not authorize(name, parsed, KNOWN):
        return result | {"state": "DENIED", "reason": "MANAGED_POLICY_DENIED", "layer": "managed"}
    try:
        config = Config.load(Path(config_path))
        settings = managed(config.state_dir)
        if isinstance(config, Config) and config.managed_snapshot != settings:
            return result | {"reason": "POLICY_NOT_APPLIED", "layer": "managed"}
        result.update(revision=settings["revision"], digest=digest(settings))
        if settings["revision"] != request.get("revision") or digest(settings) != request.get(
            "digest"
        ):
            return result | {"reason": "POLICY_NOT_APPLIED", "layer": "managed"}
        if name != mtr.CAPABILITY:
            return check_standard(request, config, parsed, result)
        destination = request["destination"]
        try:
            ip = str(ipaddress.ip_address(destination))
        except ValueError:
            if not isinstance(destination, str) or not 1 <= len(destination) <= 253:
                return result
            ip = socket.getaddrinfo(destination, 443, type=socket.SOCK_STREAM)[0][4][0]
        args = {
            "target": ip,
            "destination": destination,
            "protocol": request["protocol"],
            "cycles": 5,
            "max_hops": 16,
            "interval": 1,
        }
        # Same function called by mtr_client.collect, before its helper exchange.
        mtr.authorize(args, config.allowed_networks, config.allowed_dns_names)
        verdict = mtr_client.exchange({"op": "check", "input": args})
        if verdict.get("schema_version") != SCHEMA:
            return result | {"reason": "UNSUPPORTED_VERSION"}
        if verdict.get("ok") is True:
            return result | {"state": "ALLOWED", "reason": None, "layer": None}
        if verdict.get("reason") in (
            "POLICY_BASE_DENIED",
            "ADMIN_CEILING_DENIED",
            "HELPER_UNHEALTHY",
        ):
            return result | {
                "state": "DENIED",
                "reason": verdict["reason"],
                "layer": verdict.get("layer"),
            }
    except mtr.MtrError as error:
        if str(error) == "policy_denied":
            # Config is an intersection; helper must identify base/ceiling before assigning managed blame.
            try:
                verdict = mtr_client.exchange({"op": "check", "input": args})
                if verdict.get("schema_version") == SCHEMA:
                    if verdict.get("ok") is True:
                        return result | {
                            "state": "DENIED",
                            "reason": "MANAGED_POLICY_DENIED",
                            "layer": "managed",
                        }
                    if verdict.get("reason") in ("POLICY_BASE_DENIED", "ADMIN_CEILING_DENIED"):
                        return result | {
                            "state": "DENIED",
                            "reason": verdict["reason"],
                            "layer": verdict.get("layer"),
                        }
            except mtr.MtrError:
                pass
        return result | {"reason": "HELPER_UNAVAILABLE"}
    except (StateError, OSError, ValueError, TypeError, KeyError, IndexError):
        return result
    return result
