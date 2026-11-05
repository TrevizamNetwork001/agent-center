"""Narrow updater companion. No command, URL or path supplied by the browser is executed.

Artifacts are root owned, fetched only by digest from the configured Central. This
companion has its own systemd timer so stopping the unprivileged Agent cannot
kill an in-flight update. Interrupted operations are held, never replayed.
"""

import hashlib
import json
import os
import pwd
import re
import shutil
import stat
import tempfile
import time
import uuid
from pathlib import Path

from .. import __version__
from ..api import Client
from ..identity import Identity
from ..state import InstanceLock, atomic_write, read_private
from .engine import Engine, UpdateError, self_check, validate
from .releases import download

BASE = Path("/opt/agent-center-agent")
STATE = Path("/var/lib/agent-center-agent")
CONTROL = Path("/var/lib/agent-center-updater")
CONFIG = Path("/etc/agent-center-agent/operations.json")

SAFE_ERRORS = {
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
            data = download(self.client.base, sha)
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
        self_check(candidate, release["version"])
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
        self_check(candidate, release["version"])
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
                result = self.client.call("operations/report", record)
                if not result.ok:
                    return False
                marker.unlink()
                if record.get("status") in ("FAILED", "UNCERTAIN"):
                    return False
            result = self.client.call(
                "operations/claim", {"updater_version": __version__, "release_download_protocol": 1}
            )
            if not result.ok:
                return False
            operation = result.body.get("operation")
            if operation is None:
                return True
            operation_id = str(uuid.UUID(operation["id"]))
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
            if self.client.call("operations/report", report).ok:
                marker.unlink()
                return report["status"] in ("PLANNED", "COMPLETED")
            return False
        finally:
            with_lock.close()


def main():
    os.umask(0o077)
    if os.geteuid() != 0:
        return 2
    try:
        trusted(BASE / "releases" / "catalog.json")
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
            Client(url.rstrip("/"), identity.token, retries=0),
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
