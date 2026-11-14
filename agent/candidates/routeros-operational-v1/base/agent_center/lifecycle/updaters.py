"""Closed, signed-source updater maintenance; never accepts executable paths."""

import hashlib
import io
import os
import tempfile
import zipfile
from pathlib import Path

from ..state import atomic_write, read_private
from .releases import ReleaseError

ENTRYPOINTS = {
    "remote-updater.pyz": b"from agent_center.update.remote import main\nraise SystemExit(main())\n",
    "updater.pyz": b"from agent_center.update.cli import main\nraise SystemExit(main())\n",
}


def derive(artifact, name):
    if name not in ENTRYPOINTS:
        raise ReleaseError("unsupported_updater")
    output = io.BytesIO()
    output.write(b"#!/usr/bin/env python3\n")
    with zipfile.ZipFile(io.BytesIO(artifact)) as source, zipfile.ZipFile(output, "w") as target:
        for member in source.infolist():
            target.writestr(member, ENTRYPOINTS[name] if member.filename == "__main__.py" else source.read(member))
    return output.getvalue()


class Updaters:
    def __init__(self, base, config_dir, *, trust=None):
        from ..update.remote import trusted

        self.base, self.config_dir = Path(base), Path(config_dir)
        self.trust = trust or trusted
        self.plan = None

    def precheck(self, artifact):
        policy = self.config_dir / "lifecycle-policy.json"
        self.trust(policy)
        envelope = read_private(policy)
        if (
            envelope.get("schema_version") != 1
            or envelope.get("device_operations_frozen") is not True
            or "lifecycle-updater" not in envelope.get("components", [])
            or "lifecycle-updater" not in envelope.get("profiles", [])
        ):
            raise ReleaseError("updater_permission_not_authorized")
        self.trust(self.base)
        for name in ENTRYPOINTS:
            path = self.base / name
            self.trust(path)
            info = path.stat()
            if not path.is_file() or info.st_size > 8388608:
                raise ReleaseError("unsafe_updater")
        self.plan = {name: derive(artifact, name) for name in ENTRYPOINTS}

    def snapshot(self, backup):
        directory = Path(backup) / "updaters"
        directory.mkdir(mode=0o700)
        metadata = {}
        for name in ENTRYPOINTS:
            path = self.base / name
            self.trust(path)
            info = path.stat()
            data = path.read_bytes()
            with (directory / name).open("xb") as target:
                os.fchmod(target.fileno(), 0o600)
                target.write(data)
                target.flush()
                os.fsync(target.fileno())
            metadata[name] = {"sha256": hashlib.sha256(data).hexdigest(), "size": len(data),
                              "mode": info.st_mode & 0o777}
        atomic_write(directory / "manifest.json", metadata)
        fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)

    def write(self, name, data, mode):
        if name not in ENTRYPOINTS:
            raise ReleaseError("unsupported_updater")
        destination = self.base / name
        self.trust(destination)
        fd, temporary = tempfile.mkstemp(prefix=".updater-", dir=self.base)
        try:
            with os.fdopen(fd, "wb") as target:
                os.fchmod(target.fileno(), mode)
                target.write(data)
                target.flush()
                os.fsync(target.fileno())
            os.replace(temporary, destination)
            fd = os.open(self.base, os.O_RDONLY | os.O_DIRECTORY)
            try:
                os.fsync(fd)
            finally:
                os.close(fd)
        finally:
            Path(temporary).unlink(missing_ok=True)

    def install(self):
        if self.plan is None:
            raise ReleaseError("updater_precheck_required")
        for name, data in self.plan.items():
            self.write(name, data, 0o644)
        if any((self.base / name).read_bytes() != data for name, data in self.plan.items()):
            raise ReleaseError("updater_install_failed")

    def saved(self, backup):
        directory = Path(backup) / "updaters"
        self.trust(directory)
        self.trust(directory / "manifest.json")
        metadata = read_private(directory / "manifest.json")
        if set(metadata) != set(ENTRYPOINTS):
            raise ReleaseError("invalid_updater_backup")
        saved = {}
        for name, info in metadata.items():
            path = directory / name
            self.trust(path)
            st = path.stat()
            if not path.is_file() or st.st_size > 8388608 or st.st_mode & 0o077:
                raise ReleaseError("invalid_updater_backup")
            data = path.read_bytes()
            if (len(data) != info["size"] or hashlib.sha256(data).hexdigest() != info["sha256"]
                    or info["mode"] not in (0o600, 0o644)):
                raise ReleaseError("invalid_updater_backup")
            saved[name] = (data, info["mode"])
        return saved

    def rollback(self, backup):
        # Authenticate every file before the first write; never touch Agent state.
        for name, (data, mode) in self.saved(backup).items():
            self.write(name, data, mode)

    def rollback_health(self, backup):
        return all((self.base / name).read_bytes() == data
                   and (self.base / name).stat().st_mode & 0o777 == mode
                   for name, (data, mode) in self.saved(backup).items())
