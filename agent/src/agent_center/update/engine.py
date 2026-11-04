import hashlib
import io
import json
import os
import pwd
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import zipfile
from pathlib import Path

from ..state import InstanceLock, atomic_write


class UpdateError(Exception):
    pass


class Service:
    def active(self):
        result = subprocess.run(
            ["/usr/bin/systemctl", "is-active", "--quiet", "agent-center-agent.service"],
            check=False,
        )
        return result.returncode == 0

    def stop(self):
        subprocess.run(
            ["/usr/bin/systemctl", "stop", "agent-center-agent.service"], check=True, timeout=40
        )

    def start(self):
        subprocess.run(
            ["/usr/bin/systemctl", "start", "agent-center-agent.service"], check=True, timeout=40
        )
        time.sleep(0.5)
        if not self.active():
            raise UpdateError("service_start_failed")


def drop_privileges():
    if os.geteuid() == 0:
        try:
            account = pwd.getpwnam("agent-center-agent")
            uid, gid = account.pw_uid, account.pw_gid
        except KeyError:
            uid, gid = 65534, 65534
        os.setgroups([])
        os.setgid(gid)
        os.setuid(uid)


def self_check(path):
    result = subprocess.run(
        [sys.executable, "-I", str(path), "--self-check"],
        capture_output=True,
        timeout=15,
        check=True,
        preexec_fn=drop_privileges if os.geteuid() == 0 else None,
    )
    body = json.loads(result.stdout)
    if (
        body.get("version") != "0.5.2"
        or body.get("state_schema") != 1
        or body.get("journal_schema") != 1
        or body.get("device_operations_frozen") is not True
        or not isinstance(body.get("capabilities"), list)
    ):
        raise UpdateError("self_check_failed")
    return body


def validate(artifact, expected):
    if artifact.is_symlink() or not artifact.is_file() or artifact.stat().st_size > 8_388_608:
        raise UpdateError("unsafe_artifact")
    data = artifact.read_bytes()
    if len(expected) != 64 or hashlib.sha256(data).hexdigest() != expected:
        raise UpdateError("sha256_mismatch")
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        entries = archive.infolist()
        if len(entries) > 200 or sum(e.file_size for e in entries) > 20_971_520:
            raise UpdateError("unsafe_archive")
        names = {e.filename for e in entries}
        if "__main__.py" not in names or "agent_center/main.py" not in names:
            raise UpdateError("invalid_archive")
        for e in entries:
            p = Path(e.filename)
            mode = e.external_attr >> 16
            if (
                p.is_absolute()
                or ".." in p.parts
                or "\\" in e.filename
                or stat.S_ISLNK(mode)
                or not (e.filename == "__main__.py" or (p.parts and p.parts[0] == "agent_center"))
                or (not e.is_dir() and p.suffix != ".py")
            ):
                raise UpdateError("unsafe_archive")
    return data


class Engine:
    def __init__(self, base, state, service=None, checker=self_check):
        self.base, self.state = Path(base), Path(state)
        self.service = service or Service()
        self.checker = checker

    def _sync(self):
        fd = os.open(self.base, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)

    def _move(self, source, target):
        os.replace(source, target)
        self._sync()

    def _archive(self, name):
        path = self.base / name
        if path.exists():
            self._move(path, self.base / (name + "." + str(time.time_ns())))

    def dry_run(self, artifact, expected):
        data = validate(Path(artifact), expected)
        with tempfile.TemporaryDirectory(prefix="agent-dry-run-") as directory:
            directory = Path(directory)
            directory.chmod(0o755)
            candidate = directory / "agent.pyz"
            candidate.write_bytes(data)
            candidate.chmod(0o644)
            self.checker(candidate)
        return {"valid": True, "sha256": expected, "apply": False}

    def apply(self, artifact, expected):
        self.dry_run(
            artifact, expected
        )  # Mandatory validation even if the operator skipped a separate dry-run.
        if self.base.is_symlink() or not self.base.is_dir():
            raise UpdateError("unsafe_base")
        was_active = self.service.active()
        self.service.stop()
        if self.service.active():
            raise UpdateError("service_still_active")
        lock = None
        stage = None
        try:
            lock = InstanceLock(self.state / "instance.lock")
            runtime = self.base / "runtime"
            previous = self.base / ".previous"
            # Recover an interrupted rename without reverting state or journal.
            if not runtime.exists() and previous.is_dir():
                self._move(previous, runtime)
            if not runtime.is_dir() or runtime.is_symlink():
                raise UpdateError("missing_runtime")
            stage = Path(tempfile.mkdtemp(prefix=".staging-", dir=self.base))
            stage.chmod(0o755)
            candidate = stage / "agent.pyz"
            candidate.write_bytes(validate(Path(artifact), expected))
            candidate.chmod(0o644)
            with candidate.open("rb") as handle:
                os.fsync(handle.fileno())
            self.checker(candidate)
            atomic_write(self.base / "update.json", {"state": "prepared", "sha256": expected})
            self._archive(".previous")
            self._move(runtime, previous)
            try:
                self._move(stage, runtime)
                stage = None
                self.checker(runtime / "agent.pyz")
                atomic_write(self.base / "update.json", {"state": "swapped", "sha256": expected})
                # Keep lock until checks finish; release before starting the service.
                lock.close()
                lock = None
                if was_active:
                    self.service.start()
            except Exception:
                if self.service.active():
                    self.service.stop()
                recovery_lock = lock or InstanceLock(self.state / "instance.lock")
                try:
                    self._archive(".failed")
                    if runtime.exists():
                        self._move(runtime, self.base / ".failed")
                    if previous.exists():
                        self._move(previous, runtime)
                    atomic_write(self.base / "update.json", {"state": "rolled_back"})
                finally:
                    if lock is None:
                        recovery_lock.close()
                if lock:
                    lock.close()
                    lock = None
                if was_active:
                    self.service.start()
                raise UpdateError("new_runtime_failed_rolled_back") from None
            atomic_write(self.base / "update.json", {"state": "complete", "sha256": expected})
            return {"applied": True, "sha256": expected}
        finally:
            if lock:
                lock.close()
            if stage and stage.exists():
                shutil.rmtree(stage)

    def rollback(self, dry_run=True):
        previous = self.base / ".previous"
        if previous.is_symlink() or not previous.is_dir():
            raise UpdateError("missing_previous")
        self.checker(previous / "agent.pyz")
        if dry_run:
            return {"valid": True, "apply": False}
        was_active = self.service.active()
        self.service.stop()
        if self.service.active():
            raise UpdateError("service_still_active")
        lock = InstanceLock(self.state / "instance.lock")
        try:
            self._archive(".failed")
            if (self.base / "runtime").exists():
                self._move(self.base / "runtime", self.base / ".failed")
            self._move(previous, self.base / "runtime")
            self.checker(self.base / "runtime" / "agent.pyz")
            atomic_write(self.base / "update.json", {"state": "rolled_back"})
        finally:
            lock.close()
        if was_active:
            self.service.start()
        return {"rolled_back": True}
