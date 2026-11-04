"""Derive ignored role-specific environments without printing credentials."""

import os
import tempfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
values = dict(
    line.split("=", 1)
    for line in (root / ".env").read_text().splitlines()
    if "=" in line and not line.startswith("#")
)


def write_private(name, data):
    fd, temporary = tempfile.mkstemp(prefix=".env.pending-", dir=root)
    try:
        with os.fdopen(fd, "w") as output:
            os.fchmod(output.fileno(), 0o600)
            output.write("\n".join(f"{key}={value}" for key, value in data.items()) + "\n")
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, root / name)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


migrator = values.copy()
migrator["DB_USERNAME"] = values["APP_DB_USER"]
migrator["DB_PASSWORD"] = values["APP_DB_PASSWORD"]
write_private(".env.migrator", migrator)
keys = {
    "APP_NAME",
    "APP_ENV",
    "APP_KEY",
    "APP_DEBUG",
    "APP_URL",
    "TRUSTED_PROXY_CIDRS",
    "APP_LOCALE",
    "TZ",
    "LOG_CHANNEL",
    "LOG_LEVEL",
    "DB_CONNECTION",
    "DB_HOST",
    "DB_PORT",
    "DB_DATABASE",
    "DB_USERNAME",
    "DB_PASSWORD",
    "SESSION_DRIVER",
    "SESSION_ENCRYPT",
    "SESSION_SECURE_COOKIE",
    "CACHE_STORE",
    "QUEUE_CONNECTION",
}
write_private(".env.runtime", {key: value for key, value in values.items() if key in keys})
