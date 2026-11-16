"""Offline wheel materialization inside the existing atomic runtime transaction.

No pip, network, package scripts, global dependencies, or release-selected paths.
The signed runtime binds the complete closed wheel inventory. Engine rollback
renames the whole runtime, including these files, without touching Agent state.
"""

import hashlib
import io
import os
import platform
import stat
import sys
import zipfile
from pathlib import Path

from .ssh_catalog import WHEELS

PREFIX = "agent_center/_ssh/"
LIMIT = 40 * 1024 * 1024


def inventory(archive):
    members = [m for m in archive.infolist() if m.filename.startswith(PREFIX)]
    if not members:
        return {}
    if len(members) != len(WHEELS) or {m.filename for m in members} != {
        PREFIX + name for name in WHEELS
    }:
        raise ValueError("ssh_inventory_invalid")
    wheels = {}
    for member in members:
        name = member.filename.removeprefix(PREFIX)
        if member.file_size > 8 * 1024 * 1024:
            raise ValueError("ssh_wheel_oversized")
        data = archive.read(member)
        if hashlib.sha256(data).hexdigest() != WHEELS[name]:
            raise ValueError("ssh_wheel_hash_mismatch")
        wheels[name] = data
    return wheels


def compatible():
    if (
        sys.version_info[:2] != (3, 13)
        or platform.machine() != "x86_64"
        or platform.freedesktop_os_release().get("ID") != "debian"
        or platform.freedesktop_os_release().get("VERSION_ID") != "13"
    ):
        raise ValueError("ssh_runtime_incompatible")


def files(wheels):
    result = {}
    size = 0
    for data in wheels.values():
        with zipfile.ZipFile(io.BytesIO(data)) as wheel:
            for member in wheel.infolist():
                path = Path(member.filename)
                if (
                    path.is_absolute() or ".." in path.parts or "\\" in member.filename
                    or stat.S_ISLNK(member.external_attr >> 16) or member.flag_bits & 1
                    or any(p.endswith(".data") for p in path.parts)
                    or path.suffix == ".pth" or member.filename in result
                ):
                    raise ValueError("ssh_wheel_path_invalid")
                if member.is_dir():
                    continue
                size += member.file_size
                if size > LIMIT or len(result) >= 3000:
                    raise ValueError("ssh_wheel_oversized")
                result[member.filename] = wheel.read(member)
    return result


def materialize(artifact, directory):
    with zipfile.ZipFile(artifact) as archive:
        wheels = inventory(archive)
    if not wheels:
        return
    compatible()
    contents = files(wheels)
    target = Path(directory) / "ssh"
    target.mkdir(mode=0o755)
    for name, data in contents.items():
        path = target / name
        path.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
        with path.open("xb") as output:
            os.fchmod(output.fileno(), 0o644)
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
    # Persist nested directory entries before the Engine's runtime rename.
    for path in [*sorted((p for p in target.rglob("*") if p.is_dir()), reverse=True), target]:
        # The real companion sets umask 077. mkdir(parents=True) applies that
        # mask to intermediate directories too; explicitly permit Agent reads.
        path.chmod(0o755)
        fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(fd)
        finally:
            os.close(fd)


def activate(artifact):
    artifact = Path(artifact).absolute()
    with zipfile.ZipFile(artifact) as archive:
        wheels = inventory(archive)
    if not wheels:
        return False
    compatible()
    target = artifact.parent / "ssh"
    expected = files(wheels)
    owner = artifact.stat().st_uid
    for path in [artifact.parent, target, *target.rglob("*")]:
        info = path.lstat()
        if stat.S_ISLNK(info.st_mode) or info.st_uid != owner or info.st_mode & 0o022:
            raise ValueError("ssh_runtime_permissions")
    actual = {p.relative_to(target).as_posix() for p in target.rglob("*") if p.is_file()}
    if actual != set(expected):
        raise ValueError("ssh_runtime_inventory")
    for name, data in expected.items():
        if (target / name).read_bytes() != data:
            raise ValueError("ssh_runtime_integrity")
    sys.path.insert(0, str(target))
    return True
