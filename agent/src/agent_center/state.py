import fcntl
import json
import os
import tempfile
from pathlib import Path


class StateError(Exception):
    pass


def atomic_write(path: Path, data: dict):
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    if path.is_symlink():
        raise StateError("unsafe_path")
    fd, temp = tempfile.mkstemp(prefix=".pending-", dir=path.parent)
    try:
        with os.fdopen(fd, "w") as out:
            os.fchmod(out.fileno(), 0o600)
            json.dump(data, out, sort_keys=True, separators=(",", ":"))
            out.flush()
            os.fsync(out.fileno())
        os.replace(temp, path)
        directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def read_private(path: Path):
    st = path.lstat()
    if path.is_symlink() or not path.is_file() or st.st_mode & 0o077:
        raise StateError("unsafe_permissions")
    if st.st_uid not in (0, os.geteuid()) or st.st_size > 1_048_576:
        raise StateError("unsafe_file")
    try:
        value = json.loads(path.read_text())
        if not isinstance(value, dict):
            raise StateError("invalid_state")
        return value
    except (ValueError, UnicodeError, RecursionError) as exc:
        raise StateError("invalid_state") from exc


class InstanceLock:
    def __init__(self, path: Path):
        path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        if path.is_symlink():
            raise StateError("unsafe_lock")
        self.fd = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
        try:
            fcntl.flock(self.fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            os.close(self.fd)
            raise StateError("already_running") from None

    def close(self):
        os.close(self.fd)
