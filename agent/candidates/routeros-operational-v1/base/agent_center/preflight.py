"""Bounded read-only preflight. No DNS resolution, execution or authorization cache."""
import hashlib
import ipaddress
import json
import logging
import time

from . import __version__, mtr, mtr_client
from .capabilities import available
from .config import Config
from .managed_settings import normalize
from .policy import Policy, authorize
from .state import StateError, read_private
from pathlib import Path


def check(request, config_path, policy, agent_id):
    response = {"schema_version": 1, "id": request.get("id"), "state": "UNKNOWN", "reason": "unverified", "helper_healthy": None, "agent_version": __version__, "policy_digest": request.get("policy_digest")}
    try:
        parsed = Policy.parse(policy, agent_id, available())
        if not parsed.valid:
            logging.getLogger("agent_center").warning("policy_rejected")
            return response
        if set(request) != {"schema_version", "id", "destination", "protocol", "policy_digest", "policy_revision", "expires_at"} or request["schema_version"] != 1:
            return response
        if not time.time() < request["expires_at"] <= time.time() + 60:
            return response
        destination = str(ipaddress.ip_address(request["destination"]))
        if destination != request["destination"] or request["protocol"] not in ("icmp", "tcp"):
            return response
        if read_private(Path(config_path)).get("allow_managed_settings", True) is not True:
            return response
        config = Config.load(Path(config_path))
        managed = normalize(read_private(config.state_dir / "managed-settings.json"))
        if managed != normalize(policy["agent_settings"]) or managed["revision"] != request["policy_revision"]:
            return response
        if not parsed.tasks or mtr.CAPABILITY not in policy["allowed_capabilities"]:
            response.update(state="DENIED", reason="policy_denied")
            return response
        if not authorize(mtr.CAPABILITY, parsed, available()):
            response.update(reason="capability_unavailable")
            return response
        args = {"target": destination, "destination": destination, "protocol": request["protocol"], "cycles": 5, "max_hops": 16, "interval": 1}
        # Config.load performs the existing base/managed intersection. The very
        # same mtr.authorize is called by mtr_client.collect on execution.
        try:
            mtr.authorize(args, config.allowed_networks, config.allowed_dns_names)
        except mtr.MtrError as error:
            if str(error) == "policy_denied":
                response.update(state="DENIED", reason="policy_denied")
            return response
        helper = mtr_client.exchange({"op": "preflight", "protocol_version": 1, "input": args})
        if helper.get("schema_version") != 1 or helper.get("helper_version") != __version__:
            return response
        if helper.get("reason") == "policy_denied":
            response.update(state="DENIED", reason="policy_denied")
        elif helper.get("ok") is True and helper.get("helper_healthy") is True:
            response.update(state="ALLOWED", reason="authorized", helper_healthy=True, helper_version=helper["helper_version"], effective_revision=hashlib.sha256(json.dumps([managed, helper["policy_digest"], __version__], sort_keys=True).encode()).hexdigest())
        elif helper.get("reason") == "helper_unavailable":
            response.update(reason="helper_unavailable", helper_healthy=False)
    except (StateError, OSError, ValueError, KeyError, TypeError):
        pass
    return response
