"""Apply only typed diagnostic data from the authenticated Central, never commands."""

import ipaddress
import logging

from .policy import Policy
import re

from .state import StateError, atomic_write, read_private


def normalize(data):
    if not isinstance(data, dict) or set(data) != {
        "revision",
        "source_ips",
        "auto_public_targets",
        "allowed_networks",
        "allowed_dns_names",
    }:
        raise StateError("invalid_managed_settings")
    if (
        type(data["revision"]) is not int
        or not 1 <= data["revision"] <= 2147483647
        or type(data["auto_public_targets"]) is not bool
    ):
        raise StateError("invalid_managed_settings")
    for key, maximum in [("source_ips", 16), ("allowed_networks", 32), ("allowed_dns_names", 100)]:
        if (
            not isinstance(data[key], list)
            or len(data[key]) > maximum
            or any(not isinstance(v, str) for v in data[key])
        ):
            raise StateError("invalid_managed_settings")
    try:
        ips = sorted({str(ipaddress.ip_address(v)) for v in data["source_ips"]})
        networks = sorted(
            {str(ipaddress.ip_network(v, strict=False)) for v in data["allowed_networks"]}
        )
        names = sorted({v.lower().rstrip(".") for v in data["allowed_dns_names"]})
        if any(not re.fullmatch(r"[a-z0-9][a-z0-9.-]{0,252}", v) for v in names):
            raise ValueError()
    except ValueError:
        raise StateError("invalid_managed_settings") from None
    return {
        "revision": data["revision"],
        "source_ips": ips,
        "auto_public_targets": data["auto_public_targets"],
        "allowed_networks": networks,
        "allowed_dns_names": names,
    }


def apply(state_dir, policy, agent_id):
    if not state_dir or not Policy.parse(policy, agent_id, ()).valid:
        logging.getLogger("agent_center").warning("policy_rejected")
        return False
    if "agent_settings" not in policy:
        return False
    data = normalize(policy["agent_settings"])
    path = state_dir / "managed-settings.json"
    if path.exists() or path.is_symlink():
        previous = normalize(read_private(path))
        if previous["revision"] > data["revision"]:
            return False
        if previous["revision"] == data["revision"]:
            if previous != data:
                raise StateError("managed_revision_conflict")
            return True
    atomic_write(path, data)
    return True
