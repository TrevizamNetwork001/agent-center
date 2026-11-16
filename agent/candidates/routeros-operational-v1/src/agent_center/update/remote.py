"""Narrow updater companion. No command, URL or path supplied by the browser is executed.

Artifacts are root owned, fetched only by digest from the configured Central. This
companion has its own systemd timer so stopping the unprivileged Agent cannot
kill an in-flight update. Interrupted operations are held, never replayed.
"""

import base64
import hashlib
import json
import os
import platform
import pwd
import re
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
import time
import uuid
from pathlib import Path

from .. import __version__
from ..api import Client
from ..identity import Identity
from ..lifecycle.releases import ReleaseError, verify
from ..state import InstanceLock, atomic_write, read_private
from .engine import Engine, UpdateError, self_check, validate
from .releases import download, download_operation

BASE = Path("/opt/agent-center-agent")
STATE = Path("/var/lib/agent-center-agent")
CONTROL = Path("/var/lib/agent-center-updater")
CONFIG = Path("/etc/agent-center-agent/operations.json")

SAFE_ERRORS = {
    "component_environment_unavailable",
    "component_install_failed",
    "component_files_require_review",
    "component_stop_failed",
    "component_bootstrap_required",
    "component_permission_not_authorized",
    "component_dependency_missing",
    "component_dependency_incompatible",
    "component_busy",
    "component_dropins_require_review",
    "component_policy_exceeds_base",
    "invalid_component_policy",
    "empty_component_policy",
    "component_hash_mismatch",
    "component_requires_lifecycle_service",
    "principal_hardening_changed",
    "release_download_failed",
    "invalid_release",
    "release_not_staged",
    "sha256_mismatch",
    "incompatible",
    "postcheck_failed",
    "busy",
    "self_check_failed",
    "self_check_permission_denied",
    "self_check_timeout",
    "self_check_exec_failed",
    "self_check_privilege_failed",
    "self_check_invalid_output",
    "rollback_failed",
    "service_restart_failed",
}


def trusted(path):
    # All ancestors must be root controlled; writable paths cannot authorize code.
    for part in (path, *path.parents):
        st = part.lstat()
        if part.is_symlink() or st.st_uid != 0 or st.st_mode & 0o022:
            raise UpdateError("untrusted_catalog")
    return path


def read_identity(path):
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    try:
        st = os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or st.st_mode & 0o077 or st.st_size > 4096:
            raise UpdateError("unsafe_identity")
        if st.st_uid != pwd.getpwnam("agent-center-agent").pw_uid:
            raise UpdateError("unsafe_identity")
        with os.fdopen(fd, "r", closefd=False) as stream:
            return Identity.validate(json.load(stream))
    finally:
        os.close(fd)


class Remote:
    def __init__(self, engine, client, catalog, control, config_dir=None, postcheck=None):
        self.engine, self.client, self.catalog = engine, client, catalog
        self.control = Path(control)
        self.config_dir = Path(config_dir) if config_dir else None
        self.postcheck = postcheck or self._postcheck
        self.expected_version = None
        self.started_at = None
        self.signed_release = None
        self.required_capabilities = ()
        try:
            system = platform.freedesktop_os_release()
            self.platform = system.get("ID", "") + system.get("VERSION_ID", "")
        except OSError:
            self.platform = "unsupported"

    def _call(self, operation, payload):
        result = self.client.call(operation, payload)
        if not result.ok:
            # Only fixed codes and validated scalar metadata reach the journal.
            # Never log a response body, exception, URL, identity or payload.
            allowed = {
                "invalid_operation", "invalid_payload", "oversized_response",
                "incomplete_response", "origin_not_allowed", "invalid_response",
                "network_error",
            }
            status = result.status
            status = status if type(status) is int and 0 <= status <= 599 else 0
            reason = result.error if type(result.error) is str else ""
            if reason not in allowed and reason != "http_" + str(status):
                reason = "unavailable"
            print(json.dumps({
                "event": "updater_api_failed", "operation": operation,
                "reason": reason, "status": status,
                "retryable": result.retryable is True,
            }), flush=True)
        elif operation == "operations/report" and payload.get("status") in ("FAILED", "UNCERTAIN"):
            reason = payload.get("error")
            allowed = SAFE_ERRORS | {"interrupted", "precheck_failed", "update_failed", "backup_failed"}
            if type(reason) is not str or reason not in allowed:
                reason = "maintenance_failed"
            print(json.dumps({
                "event": "updater_operation_failed", "status": payload["status"],
                "reason": reason,
            }), flush=True)
        return result

    def _verify_signed_release(self, release, artifact):
        if "lifecycle" not in release:
            if (CONFIG.parent / "lifecycle-public.pem").exists():
                raise UpdateError("invalid_release")
            return
        envelope = release["lifecycle"]
        try:
            if not isinstance(envelope, dict) or set(envelope) != {"manifest", "signature"}:
                raise ValueError()
            payload = base64.b64decode(envelope["manifest"], validate=True)
            key = trusted(CONFIG.parent / "lifecycle-public.pem").read_bytes()
            ledger = self.control / "lifecycle" / "installed.json"
            sequence = read_private(ledger)["sequence"] if ledger.exists() else 0
            signed = verify(
                payload,
                envelope["signature"],
                key,
                artifact,
                minimum_sequence=sequence,
                platform=self.platform,
            )
            if signed["sha256"] != release["sha256"] or signed["version"] != release["version"]:
                raise ValueError()
            self.signed_release = signed
        except (OSError, ValueError, TypeError, KeyError, ReleaseError):
            raise UpdateError("invalid_release") from None

    def _postcheck(self):
        if not self.expected_version or not self.engine.service.active():
            return False
        start = self.started_at if self.started_at is not None else time.time()
        for _ in range(45):
            path = self.engine.state / "status.json"
            try:
                # Never use this reported data to authorize code or change paths.
                fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
                with os.fdopen(fd) as stream:
                    info = os.fstat(stream.fileno())
                    if (
                        not stat.S_ISREG(info.st_mode)
                        or info.st_size > 1_048_576
                        or info.st_mode & 0o077
                    ):
                        return False
                    if info.st_uid != self.engine.state.stat().st_uid:
                        return False
                    data = json.loads(stream.read(1_048_577))
                if (
                    isinstance(data, dict)
                    and info.st_mtime >= start
                    and type(data.get("schema_version")) is int
                    and data["schema_version"] == 1
                    and data.get("version") == self.expected_version
                    and data.get("state") == "ONLINE"
                    and type(data.get("heartbeat_successes")) is int
                    and data["heartbeat_successes"] > 0
                    and type(data.get("poll_successes")) is int
                    and data["poll_successes"] > 0
                    and all(
                        cap in data.get("capabilities", []) for cap in self.required_capabilities
                    )
                    and (
                        not self.required_capabilities or data.get("capabilities_announced") is True
                    )
                ):
                    return True
            except (OSError, ValueError):
                pass
            time.sleep(1)
        return False

    def _stage_release(self, operation):
        release = operation["release"]
        if (
            operation.get("kind") != "update"
            or operation.get("phase") != "precheck"
            or release.get("device_operations_frozen") is not True
        ):
            raise UpdateError("release_not_staged")
        directory = trusted(self.engine.base / "releases")
        catalog_path = trusted(directory / "catalog.json")
        current = json.loads(catalog_path.read_text())
        if not isinstance(current, list):
            raise UpdateError("invalid_release")
        sha = release["sha256"]
        candidate = directory / (sha + ".pyz")
        if candidate.exists() or candidate.is_symlink():
            validate(candidate, sha)
        else:
            data = (
                download_operation(self.client, operation.get("id"))
                if "lifecycle" in release
                else download(self.client.base, sha)
            )
            fd, temporary = tempfile.mkstemp(prefix=".download-", dir=directory)
            temporary = Path(temporary)
            try:
                with os.fdopen(fd, "wb") as stream:
                    stream.write(data)
                    stream.flush()
                    os.fsync(stream.fileno())
                validate(temporary, sha)
                temporary.chmod(0o644)
                os.replace(temporary, candidate)
            finally:
                temporary.unlink(missing_ok=True)
        # Do not authorize a durable catalog entry until its declared runtime verifies.
        self._verify_signed_release(release, candidate.read_bytes())
        self.engine.dry_run(candidate, sha, checker=lambda staged: self_check(staged, release["version"]))
        entry = {"sha256": sha, "version": release["version"], "device_operations_frozen": True}
        if any(row.get("sha256") == sha and row != entry for row in current):
            raise UpdateError("invalid_release")
        updated = [entry, *[row for row in current if row.get("sha256") != sha]]
        atomic_write(catalog_path, updated)
        self.catalog[:] = updated
        return entry

    def _candidate(self, operation):
        release = operation["release"]
        sha = release.get("sha256")
        if (
            not isinstance(sha, str)
            or not re.fullmatch(r"[a-f0-9]{64}", sha)
            or not isinstance(release.get("version"), str)
            or not re.fullmatch(r"[0-9]+[.][0-9]+[.][0-9]+", release["version"])
        ):
            raise UpdateError("invalid_release")
        entry = next(
            (
                e
                for e in self.catalog
                if e.get("sha256") == sha and e.get("version") == release.get("version")
            ),
            None,
        )
        if entry is None:
            entry = self._stage_release(operation)
        if entry.get("device_operations_frozen") is not True:
            raise UpdateError("release_not_staged")
        candidate = self.engine.base / "releases" / (sha + ".pyz")
        # No paths or download URLs from the server are honored.
        validate(candidate, sha)
        self._verify_signed_release(release, candidate.read_bytes())
        self.engine.dry_run(candidate, sha, checker=lambda staged: self_check(staged, release["version"]))
        return candidate, sha

    def _backup(self, operation_id):
        backup = self.control / "backups" / operation_id
        backup.mkdir(parents=True, mode=0o700, exist_ok=False)
        # Agent stopped + instance lock held. Copy symlinks, never follow them.
        shutil.copytree(self.engine.state, backup / "state", symlinks=True)
        shutil.copytree(self.engine.base / "runtime", backup / "runtime", symlinks=True)
        if self.config_dir:
            shutil.copytree(self.config_dir, backup / "config", symlinks=True)
        manifest = {}
        for path in backup.rglob("*"):
            if path.is_file() and not path.is_symlink():
                manifest[str(path.relative_to(backup))] = hashlib.sha256(
                    path.read_bytes()
                ).hexdigest()
        atomic_write(backup / "manifest.json", manifest)
        return operation_id

    def run(self):
        self.control.mkdir(parents=True, mode=0o700, exist_ok=True)
        with_lock = InstanceLock(self.control / "helper.lock")
        try:
            # Deliver durable reports before claiming anything else.
            for marker in self.control.glob("*.json"):
                record = read_private(marker)
                if record.get("status") == "PROCESSING":
                    record = {
                        "operation_id": record["operation_id"],
                        "status": "UNCERTAIN",
                        "error": "interrupted",
                    }
                    atomic_write(marker, record)
                result = self._call("operations/report", record)
                if not result.ok:
                    return False
                marker.unlink()
                if record.get("status") in ("FAILED", "UNCERTAIN"):
                    return False
            result = self._call(
                "operations/claim",
                {
                    "updater_version": __version__,
                    "runtime_package_protocol": 2,
                    "release_download_protocol": 1,
                    "signed_release_protocol": 1,
                    "component_protocol": 1,
                    "recovery_protocol": 1,
                },
            )
            if not result.ok:
                return False
            operation = result.body.get("operation")
            if operation is None:
                return True
            operation_id = str(uuid.UUID(operation["id"]))
            if operation.get("phase") == "reconcile":
                return self._reconcile_restored(operation, operation_id)
            marker = self.control / (operation_id + ".json")
            atomic_write(marker, {"operation_id": operation_id, "status": "PROCESSING"})
            report = {"operation_id": operation_id, "status": "FAILED"}
            was_active = False
            stopped = False
            try:
                if operation["kind"] not in ("update", "rollback") or operation["phase"] not in (
                    "precheck",
                    "apply",
                ):
                    raise UpdateError("incompatible")
                candidate, sha = self._candidate(operation)
                if self.signed_release and self.signed_release.get("components"):
                    from ..lifecycle.components import Components
                    from ..lifecycle.debian_components import DebianComponents

                    try:
                        Components(
                            getattr(self, "component_system", None) or DebianComponents()
                        ).precheck(self.signed_release, candidate.read_bytes())
                    except ReleaseError as exc:
                        raise UpdateError(str(exc)) from None
                self.expected_version = operation["release"]["version"]
                if operation["kind"] == "rollback":
                    previous = self.engine.base / ".previous" / "agent.pyz"
                    validate(previous, sha)
                    self.engine.rollback(True)
                else:
                    self.engine.dry_run(candidate, sha)
                evidence = {
                    "sha256": sha,
                    "version": operation["release"]["version"],
                    "freeze_preserved": True,
                }
                report["evidence"] = evidence
                if operation["phase"] == "precheck":
                    report.update(status="PLANNED", evidence=evidence)
                else:
                    if self.signed_release is not None:
                        from ..lifecycle.manager import Manager
                        from ..lifecycle.runtime import RuntimeAdapter

                        envelope = operation["release"]["lifecycle"]
                        key = trusted(CONFIG.parent / "lifecycle-public.pem").read_bytes()
                        result = Manager(
                            self.control / "lifecycle",
                            RuntimeAdapter(self, candidate, operation),
                            key,
                            self.platform,
                        ).apply(
                            operation_id,
                            base64.b64decode(envelope["manifest"], validate=True),
                            envelope["signature"],
                            candidate.read_bytes(),
                        )
                        evidence["postcheck"] = result["state"] == "COMPLETED"
                        if result.get("backup") == operation_id:
                            evidence["backup"] = operation_id
                        if self.signed_release.get("components"):
                            evidence["components"] = [
                                {
                                    "id": item["id"],
                                    "version": item["version"],
                                    "profile": item["profile"],
                                    "status": "installed"
                                    if result["state"] == "COMPLETED"
                                    else "rolled_back"
                                    if result.get("rollback")
                                    else "uncertain",
                                    "operational_verified": result["state"] == "COMPLETED"
                                    and getattr(
                                        getattr(self, "component_system", None), "operational", True
                                    ),
                                }
                                for item in self.signed_release["components"]
                            ]
                        if "rollback" in result:
                            evidence["rollback"] = result["rollback"]
                        report.update(status=result["state"], evidence=evidence)
                        if result["state"] != "COMPLETED":
                            report["error"] = (
                                "component_install_failed"
                                if result.get("error") == "install_failed"
                                and self.signed_release.get("components")
                                else "update_failed"
                                if result.get("error") == "install_failed"
                                else result.get("error", "postcheck_failed")
                            )
                        atomic_write(marker, report)
                        if self._call("operations/report", report).ok:
                            marker.unlink()
                            return result["state"] == "COMPLETED"
                        return False
                    was_active = self.engine.service.active()
                    stopped = True
                    self.engine.service.stop()
                    if self.engine.service.active():
                        raise UpdateError("busy")
                    lock = InstanceLock(self.engine.state / "instance.lock")
                    try:
                        evidence["backup"] = self._backup(operation_id)
                    finally:
                        lock.close()
                    if operation["kind"] == "update":
                        self.engine.apply(candidate, sha)
                    else:
                        self.engine.rollback(False)
                    # An authorized maintenance apply must also recover an inactive Agent.
                    self.started_at = time.time()
                    self.engine.service.start()
                    evidence["postcheck"] = False
                    if not self.postcheck():
                        try:
                            if operation["kind"] != "update":
                                raise UpdateError("rollback_failed")
                            self_check(
                                self.engine.base / ".previous" / "agent.pyz",
                                operation["from_version"],
                            )
                            self.expected_version = operation["from_version"]
                            self.started_at = time.time()
                            self.engine.rollback(False)
                            if not self.engine.service.active():
                                self.engine.service.start()
                            if not self.postcheck():
                                raise UpdateError("rollback_failed")
                            evidence["rollback"] = True
                        except Exception:
                            report["status"] = "UNCERTAIN"
                            evidence["rollback"] = False
                            raise UpdateError("rollback_failed") from None
                        raise UpdateError("postcheck_failed")
                    evidence["postcheck"] = True
                    report.update(status="COMPLETED", evidence=evidence)
            except Exception as exc:
                error = (
                    str(exc)
                    if isinstance(exc, UpdateError) and str(exc) in SAFE_ERRORS
                    else (
                        "precheck_failed"
                        if operation.get("phase") == "precheck"
                        else operation.get("kind", "update") + "_failed"
                    )
                )
                report.update(error=error)
                if stopped and was_active and report["status"] != "UNCERTAIN":
                    try:
                        if not self.engine.service.active():
                            self_check(
                                self.engine.base / "runtime" / "agent.pyz",
                                operation["from_version"],
                            )
                            self.engine.service.start()
                    except Exception:
                        report.update(status="UNCERTAIN", error="service_restart_failed")
                print(
                    json.dumps({"event": "maintenance_failed", "error": report["error"]}),
                    flush=True,
                )
            atomic_write(marker, report)
            if self._call("operations/report", report).ok:
                marker.unlink()
                return report["status"] in ("PLANNED", "COMPLETED")
            return False
        finally:
            with_lock.close()

    def _reconcile_restored(self, operation, operation_id):
        from ..lifecycle.manager import Manager
        from ..lifecycle.runtime import RuntimeAdapter

        try:
            payload = base64.b64decode(operation["release"]["lifecycle"]["manifest"], validate=True)
            Manager(
                self.control / "lifecycle", RuntimeAdapter(self, self.engine.base, operation),
                b"", self.platform,
            ).reconcile_restored(operation_id, hashlib.sha256(payload).hexdigest())
        except Exception:
            # Failed verification leaves both holds unchanged. No update replay.
            print('{"event":"updater_recovery_refused","reason":"verification_failed"}', flush=True)
            return False
        report = {
            "operation_id": operation_id, "status": "FAILED", "error": "interrupted",
            "evidence": {
                "recovery_verified": True, "backup": operation_id,
                "version": operation["from_version"], "sha256": operation["release"]["sha256"],
                "postcheck": True, "freeze_preserved": True,
            },
        }
        marker = self.control / (operation_id + ".json")
        atomic_write(marker, report)
        if self._call("operations/report", report).ok:
            marker.unlink()
            return True
        return False


def main():
    os.umask(0o077)
    if os.geteuid() != 0:
        return 2
    try:
        trusted(BASE / "releases" / "catalog.json")
        # The root companion's ledger, reports and backups cannot live in an
        # Agent-writable namespace. Check it before loading any credential.
        if CONTROL.exists() or CONTROL.is_symlink():
            trusted(CONTROL)
            if not CONTROL.is_dir() or CONTROL.stat().st_mode & 0o077:
                raise UpdateError("untrusted_control")
        else:
            trusted(CONTROL.parent)
        # A missing trust anchor must never select an unsigned legacy path.
        key = trusted(CONFIG.parent / "lifecycle-public.pem")
        if not key.is_file() or key.stat().st_size > 4096:
            raise UpdateError("invalid_release")
        # Execute from an immutable private copy before replacing installed ZIPs.
        # Lazy imports must never mix an old process with a newly installed ZIP.
        entry = Path(sys.argv[0])
        if entry.suffix == ".pyz" and "--lifecycle-stable" not in sys.argv[1:]:
            trusted(entry)
            with tempfile.TemporaryDirectory(prefix=".execution-", dir=CONTROL) as directory:
                stable = Path(directory) / "remote-updater.pyz"
                stable.write_bytes(entry.read_bytes())
                stable.chmod(0o600)
                child = subprocess.Popen(
                    [sys.executable, "-I", str(stable), "--lifecycle-stable"],
                    stdin=subprocess.DEVNULL, start_new_session=True,
                )
                try:
                    return child.wait(timeout=230)
                except subprocess.TimeoutExpired:
                    os.killpg(child.pid, signal.SIGKILL)
                    child.wait(timeout=3)
                    return 2
        cfg = read_private(trusted(CONFIG))
        # Config validator forbids credentials/query/non-HTTPS; helper has no HTTP lab bypass.
        from urllib.parse import urlsplit

        url = cfg["central_url"]
        parsed = urlsplit(url)
        if (
            parsed.scheme != "https"
            or not parsed.hostname
            or parsed.username
            or parsed.password
            or parsed.query
            or parsed.fragment
            or parsed.path not in ("", "/")
        ):
            return 2
        catalog = json.loads((BASE / "releases" / "catalog.json").read_text())
        for release in catalog:
            trusted(BASE / "releases" / (release["sha256"] + ".pyz"))
        identity = read_identity(STATE / "identity.json")
        remote = Remote(
            Engine(BASE, STATE),
            # Allow bounded TLS/HTTP latency without replaying a claim or report.
            Client(url.rstrip("/"), identity.token, timeout=10, retries=0),
            catalog,
            CONTROL,
            CONFIG.parent,
        )
        return 0 if remote.run() else 1
    except Exception:
        print('{"event":"updater_failed","reason":"refused_or_unavailable"}')
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
