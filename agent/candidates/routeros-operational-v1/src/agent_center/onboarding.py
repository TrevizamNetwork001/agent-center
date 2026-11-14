"""Resumable approval receipt; never retries the single-use enrollment request."""

import hashlib
import socket
from pathlib import Path

from . import __version__
from .api import Client
from .capabilities import self_check
from .identity import Identity
from .state import StateError, atomic_write, read_private


def enroll(config, code=None):
    receipt_path = config.state_dir / "enrollment.json"
    client = Client(config.central_url, timeout=config.timeout, retries=0)
    if config.identity_file.exists():
        raise StateError("identity_already_exists")
    if receipt_path.exists():
        receipt = read_private(receipt_path)["receipt"]
    else:
        if not code:
            raise StateError("enrollment_code_required")
        machine = Path("/etc/machine-id")
        fingerprint = (
            hashlib.sha256(machine.read_bytes()).hexdigest() if machine.is_file() else None
        )
        payload = dict(
            code=code,
            hostname=socket.gethostname(),
            version=__version__,
            capabilities=list(self_check()),
        )
        if fingerprint:
            payload["fingerprint"] = fingerprint
        response = client.call("enroll", payload)
        if not response.ok or response.status != 202:
            raise StateError("enrollment_request_failed")
        receipt = response.body["receipt"]
        atomic_write(receipt_path, {"receipt": receipt})
    response = client.call("enrollment-status", {"receipt": receipt})
    if not response.ok:
        raise StateError("enrollment_completion_failed")
    if response.body.get("state") == "AWAITING_APPROVAL":
        return False
    Identity.validate(response.body).save(config.identity_file)
    receipt_path.unlink()
    return True
