"""Allowlisted process inventory. Missing measurements remain null."""

import os
import platform
import shutil
import sqlite3
import threading
import time
from contextlib import closing
from pathlib import Path

from .network_inventory import addresses, resolvers
from .state import StateError, read_private

START = time.monotonic()


def snapshot(state_dir=None):
    data = dict(
        os=platform.system(),
        os_release=platform.release(),
        kernel=platform.release(),
        python=platform.python_version(),
        cpu_count=os.cpu_count(),
        process_alive=True,
        pid=os.getpid(),
        threads=threading.active_count(),
        uptime_seconds=int(time.monotonic() - START),
        device_operations_frozen=True,
        dry_run=False,
        memory_total_bytes=None,
        rss_bytes=None,
        disk_free_bytes=None,
        journal_ok=None,
        journal_pending=None,
        service_status=None,
        local_addresses=addresses(),
        dns_resolvers=resolvers(),
        settings_revision=0,
    )
    try:
        mem = dict(line.split(":", 1) for line in Path("/proc/meminfo").read_text().splitlines())
        data["memory_total_bytes"] = int(mem["MemTotal"].split()[0]) * 1024
        status = dict(
            line.split(":", 1) for line in Path("/proc/self/status").read_text().splitlines()
        )
        data["rss_bytes"] = int(status["VmRSS"].split()[0]) * 1024
        data["disk_free_bytes"] = shutil.disk_usage(state_dir or "/").free
    except (OSError, ValueError, KeyError):
        pass
    if state_dir is not None:
        settings = Path(state_dir) / "managed-settings.json"
        try:
            if settings.exists():
                value = read_private(settings).get("revision", 0)
                data["settings_revision"] = value if type(value) is int and value >= 0 else 0
        except (OSError, ValueError, StateError):
            pass
        journal = Path(state_dir) / "journal.sqlite3"
        if journal.is_file() and not journal.is_symlink():
            try:
                with closing(
                    sqlite3.connect("file:" + str(journal) + "?mode=ro", uri=True, timeout=0.1)
                ) as db:
                    data["journal_ok"] = db.execute("PRAGMA quick_check(1)").fetchone()[0] == "ok"
                    data["journal_pending"] = db.execute(
                        "SELECT count(*) FROM executions WHERE state != 'completed'"
                    ).fetchone()[0]
            except (sqlite3.Error, OSError):
                data["journal_ok"] = False
    return data
