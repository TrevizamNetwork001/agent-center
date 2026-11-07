"""Adapter for the existing runtime updater; no command or path comes from a release."""

import hashlib
import shutil
import sqlite3
import tempfile
import time
from contextlib import closing
from pathlib import Path

from ..state import InstanceLock, read_private
from ..update.engine import UpdateError, self_check


class RuntimeAdapter:
    def __init__(self, remote, candidate, operation):
        self.remote = remote
        self.candidate = Path(candidate)
        self.operation = operation
        self.components = None

    def restored_health(self, operation_id):
        from .components import Components, ceiling
        from .debian_components import DebianComponents

        backup = self.remote.control / "backups" / operation_id
        manifest = read_private(backup / "manifest.json")
        # Verify backup bytes before using them as evidence. Never restore an old
        # identity or journal. Config and identity must still match the backup.
        for name, digest in manifest.items():
            relative = Path(name)
            if relative.is_absolute() or ".." in relative.parts:
                return False
            source = backup / relative
            if source.is_symlink():
                return False
            # SQLite's shared-memory index is a derived coordination cache and
            # read-only WAL readers can change it. Never use that index as
            # recovery evidence; verify the database/WAL and rebuild it in a copy.
            if name != "state/journal.sqlite3-shm" and hashlib.sha256(source.read_bytes()).hexdigest() != digest:
                return False
            live = None
            if relative.parts[0] == "config":
                if self.remote.config_dir is None:
                    return False
                live = self.remote.config_dir / Path(*relative.parts[1:])
            elif name == "state/identity.json":
                live = self.remote.engine.state / "identity.json"
            elif name == "runtime/agent.pyz":
                live = self.remote.engine.base / "runtime" / "agent.pyz"
            if live is not None and (
                live.is_symlink() or hashlib.sha256(live.read_bytes()).hexdigest() != digest
            ):
                return False
        if not {"runtime/agent.pyz", "state/identity.json", "state/journal.sqlite3"}.issubset(manifest):
            return False
        journal = self.remote.engine.state / "journal.sqlite3"
        if journal.is_symlink() or not journal.is_file():
            return False
        # Read the current WAL-aware journal without replacing or resetting it.
        with closing(sqlite3.connect(journal.as_uri() + "?mode=ro", uri=True, timeout=5)) as current:
            if current.execute("PRAGMA quick_check").fetchall() != [("ok",)]:
                return False
            saved = backup / "state" / "journal.sqlite3"
            if saved.is_symlink() or not saved.is_file():
                return False
            wal = saved.with_name(saved.name + "-wal")
            if wal.exists() and "state/journal.sqlite3-wal" not in manifest:
                return False
            # Reading the saved WAL in place can modify its SHM index. Copy
            # only authenticated database/WAL bytes; never copy or trust SHM.
            with tempfile.TemporaryDirectory(prefix="agent-recovery-journal-") as directory:
                copy = Path(directory) / "journal.sqlite3"
                shutil.copyfile(saved, copy)
                if wal.exists():
                    shutil.copyfile(wal, copy.with_name(copy.name + "-wal"))
                with closing(sqlite3.connect(copy.as_uri() + "?mode=ro", uri=True, timeout=5)) as previous:
                    if previous.execute("PRAGMA quick_check").fetchall() != [("ok",)]:
                        return False
                    for table in ("executions", "events"):
                        if current.execute("SELECT count(*) FROM " + table).fetchone()[0] < previous.execute("SELECT count(*) FROM " + table).fetchone()[0]:
                            return False
        self_check(self.remote.engine.base / "runtime" / "agent.pyz", self.operation["from_version"])
        system = getattr(self.remote, "component_system", None) or DebianComponents()
        if getattr(system, "operational", False) is True:
            envelope, base, policy = system.precheck()
            if envelope.get("device_operations_frozen") is not True or base.get("device_operations_frozen", True) is not True:
                return False
            ceiling(base, policy)
        if not Components(system).rollback_health(backup):
            return False
        self.remote.expected_version = self.operation["from_version"]
        self.remote.started_at = time.time()
        return self.remote.postcheck()

    def precheck(self, release, artifact):
        if self.operation["kind"] != "update" or self.operation["phase"] != "apply":
            raise UpdateError("incompatible")
        if self.operation["release"].get("device_operations_frozen") is not True:
            raise UpdateError("incompatible")
        if self.candidate.read_bytes() != artifact:
            raise UpdateError("sha256_mismatch")
        self.remote.engine.dry_run(self.candidate, release["sha256"])
        if release.get("components"):
            from .components import Components
            from .debian_components import DebianComponents

            system = getattr(self.remote, "component_system", None) or DebianComponents()
            self.components = Components(system)
            self.components.precheck(release, artifact)
        return release

    def snapshot(self, operation_id, plan):
        self.remote.engine.service.stop()
        if self.remote.engine.service.active():
            raise UpdateError("busy")
        lock = InstanceLock(self.remote.engine.state / "instance.lock")
        try:
            backup = self.remote._backup(operation_id)
            if self.components:
                self.components.snapshot(self.remote.control / "backups" / backup)
            return backup
        except Exception:
            self.remote.engine.service.start()
            raise
        finally:
            lock.close()

    def install(self, plan, artifact):
        if self.components:
            self.components.install()
        self.remote.engine.apply(self.candidate, plan["sha256"])
        self.remote.started_at = time.time()
        self.remote.engine.service.start()

    def health(self, plan):
        self.remote.expected_version = plan["version"]
        if self.components and getattr(self.components.system, "operational", False):
            self.remote.required_capabilities = ("mtr_path_analysis:v1",)
        return (not self.components or self.components.health()) and self.remote.postcheck()

    def rollback(self, backup):
        # Snapshot is evidence, never restore an old journal or identity.
        backup_path = self.remote.control / "backups" / backup
        if self.components:
            self.components.rollback(backup_path)
        old = (backup_path / "runtime" / "agent.pyz").read_bytes()
        current = self.remote.engine.base / "runtime" / "agent.pyz"
        if not current.exists() or current.read_bytes() != old:
            previous = self.remote.engine.base / ".previous" / "agent.pyz"
            if previous.read_bytes() != old:
                raise UpdateError("rollback_failed")
            self_check(previous, self.operation["from_version"])
            self.remote.engine.rollback(False)
        self.rollback_backup = backup_path
        self.remote.started_at = time.time()
        self.remote.engine.service.start()

    def rollback_health(self):
        self.remote.expected_version = self.operation["from_version"]
        self.remote.required_capabilities = ()
        return (
            not self.components or self.components.rollback_health(self.rollback_backup)
        ) and self.remote.postcheck()
