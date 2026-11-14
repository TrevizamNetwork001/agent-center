"""Transactional orchestration independent of the operating-system adapter.

Adapters are provisioned locally, never selected by release metadata. The remote
companion uses RuntimeAdapter only after signed release and compatibility checks.
"""

import hashlib
from pathlib import Path
from uuid import UUID

from ..state import InstanceLock, atomic_write, read_private
from .releases import ReleaseError, verify


class Manager:
    def __init__(self, control, adapter, public_key, platform):
        self.control = Path(control)
        self.adapter = adapter
        self.public_key = public_key
        self.platform = platform

    def apply(self, operation_id, payload, signature, artifact):
        operation_id = str(UUID(operation_id))
        self.control.mkdir(mode=0o700, parents=True, exist_ok=True)
        lock = InstanceLock(self.control / "lifecycle.lock")
        try:
            marker = self.control / (operation_id + ".json")
            if marker.exists():
                record = read_private(marker)
                if record.get("request_digest") != hashlib.sha256(payload).hexdigest():
                    raise ReleaseError("operation_conflict")
                if record["state"] not in ("COMPLETED", "FAILED"):
                    raise ReleaseError("recovery_required")
                return record
            for pending in self.control.glob("*.json"):
                if pending.name == "installed.json":
                    continue
                if read_private(pending).get("state") not in ("COMPLETED", "FAILED"):
                    raise ReleaseError("recovery_required")
            installed = self.control / "installed.json"
            current = read_private(installed) if installed.exists() else {"sequence": 0}
            release = verify(
                payload,
                signature,
                self.public_key,
                artifact,
                minimum_sequence=current["sequence"],
                platform=self.platform,
            )
            previous_components = {item["id"]: item for item in current.get("components", [])}
            for item in release.get("components", []):
                previous = previous_components.get(item["id"])
                if previous and tuple(map(int, item["version"].split("."))) < tuple(
                    map(int, previous["version"].split("."))
                ):
                    raise ReleaseError("component_downgrade")
            # Adapter precheck must validate component schema, local privilege
            # envelope, dependencies, ACL and freeze before making any change.
            plan = self.adapter.precheck(release, artifact)
            record = {
                "operation_id": operation_id,
                "state": "PREPARED",
                "request_digest": hashlib.sha256(payload).hexdigest(),
                "sequence": release["sequence"],
                "sha256": release["sha256"],
            }
            atomic_write(marker, record)
            committed = False
            try:
                backup = self.adapter.snapshot(operation_id, plan)
                record.update(state="INSTALLING", backup=backup)
                atomic_write(marker, record)
                self.adapter.install(plan, artifact)
                record["state"] = "CHECKING"
                atomic_write(marker, record)
                if self.adapter.health(plan) is not True:
                    raise ReleaseError("postcheck_failed")
                finalize = getattr(self.adapter, "finalize", None)
                if finalize is not None:
                    finalize(plan)
                # Never restore Agent identity or journal during component rollback.
                installed_state = dict(release)
                if previous_components or release.get("components"):
                    installed_state["components"] = list(
                        {
                            **previous_components,
                            **{item["id"]: item for item in release.get("components", [])},
                        }.values()
                    )
                atomic_write(installed, installed_state)
                committed = True
                record["state"] = "COMPLETED"
                atomic_write(marker, record)
            except Exception:
                failed_state = record["state"]
                record["error"] = (
                    "backup_failed"
                    if failed_state == "PREPARED"
                    else "install_failed"
                    if failed_state == "INSTALLING"
                    else "postcheck_failed"
                )
                record["state"] = "UNCERTAIN"
                atomic_write(marker, record)
                if committed:
                    return record
                if "backup" in record:
                    try:
                        self.adapter.rollback(record["backup"])
                        if self.adapter.rollback_health() is not True:
                            raise ReleaseError("rollback_failed")
                        record.update(state="FAILED", rollback=True)
                    except Exception:
                        record.update(state="UNCERTAIN", rollback=False)
                else:
                    record.update(state="FAILED", rollback=False)
                atomic_write(marker, record)
            return record
        finally:
            lock.close()

    def reconcile_restored(self, operation_id, request_digest):
        """Verify an already restored host; never install, restart or restore state.

        The caller must hold a fresh Central authorization. Original evidence is
        retained before closing the local hold. A committed release cannot be
        reconciled as a rollback.
        """
        operation_id = str(UUID(operation_id))
        lock = InstanceLock(self.control / "lifecycle.lock")
        try:
            marker = self.control / (operation_id + ".json")
            record = read_private(marker)
            if record.get("request_digest") != request_digest:
                raise ReleaseError("operation_conflict")
            already_verified = record.get("recovery_verified") is True and record["state"] == "FAILED"
            # A killed companion can leave its durable lifecycle phase behind
            # while Central correctly holds the operation as UNCERTAIN. Verify
            # these interrupted phases without replaying installation/rollback.
            recoverable = record["state"] in ("UNCERTAIN", "INSTALLING", "CHECKING")
            if (not recoverable and not already_verified) or record.get("backup") != operation_id:
                raise ReleaseError("recovery_required")
            installed = self.control / "installed.json"
            if installed.exists() and read_private(installed)["sequence"] >= record["sequence"]:
                raise ReleaseError("recovery_required")
            if self.adapter.restored_health(operation_id) is not True:
                raise ReleaseError("rollback_failed")
            if already_verified:
                return record
            evidence = self.control / "recovery-evidence"
            evidence.mkdir(mode=0o700, exist_ok=True)
            original = evidence / (operation_id + ".json")
            if not original.exists():
                atomic_write(original, record)
            record = {**record, "state": "FAILED", "recovery_verified": True}
            record.setdefault("error", "interrupted")
            atomic_write(marker, record)
            return record
        finally:
            lock.close()
