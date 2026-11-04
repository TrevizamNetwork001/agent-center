"""Derive ignored role-specific environments without printing credentials."""

import os
import tempfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
destination = Path(os.environ.get("ROLE_ENV_DIR", str(root)))
source = root / os.environ.get("COMPOSE_ENV_FILE", ".env")
values = dict(
    line.split("=", 1)
    for line in source.read_text().splitlines()
    if "=" in line and not line.startswith("#")
)

values.setdefault("DB_SSLMODE", "verify-full")
values.setdefault("DB_SSLROOTCERT", "/run/pg-tls/ca.crt")


def write_private(name, data):
    fd, temporary = tempfile.mkstemp(prefix=".env.pending-", dir=destination)
    try:
        with os.fdopen(fd, "w") as output:
            os.fchmod(output.fileno(), 0o600)
            output.write("\n".join(f"{key}={value}" for key, value in data.items()) + "\n")
            output.flush()
            os.fsync(output.fileno())
        os.replace(temporary, destination / name)
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
    "DB_SSLMODE",
    "DB_SSLROOTCERT",
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
runtime = {key: value for key, value in values.items() if key in keys}
runtime["DB_USERNAME"] = values["RUNTIME_DB_USER"]
runtime["DB_PASSWORD"] = values["RUNTIME_DB_PASSWORD"]
write_private(".env.runtime", runtime)
