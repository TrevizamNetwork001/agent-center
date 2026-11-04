import argparse
import getpass
import json
import os
import signal
import socket
import sqlite3
import sys
import threading
from pathlib import Path

from . import __version__
from .api import Client
from .capabilities import self_check
from .config import Config
from .heartbeat import Heartbeat
from .identity import Identity
from .journal import Journal
from .logging import configure
from .polling import Poller
from .state import InstanceLock, StateError, atomic_write, read_private

STATES = (
    "BOOT",
    "CONFIG",
    "IDENTITY",
    "RECOVERY",
    "CAPABILITIES",
    "ONLINE",
    "HEARTBEAT/POLL",
    "CLAIM",
    "START",
    "RENEW",
    "EXECUTE",
    "RESULT",
    "RECONCILE",
    "IDLE",
    "DEGRADED",
    "QUARANTINED",
    "STOPPED",
)


class Runtime:
    def __init__(self, config, identity, client_factory=Client):
        self.config, self.identity = config, identity
        self.stop = threading.Event()
        self.capabilities = self_check()
        # Independent clients; never share journal, file locks or a connection with heartbeat.
        self.heartbeat = Heartbeat(
            client_factory(config.central_url, identity.token, config.timeout),
            config.heartbeat_interval,
            self.stop,
        )
        self.client = client_factory(config.central_url, identity.token, config.timeout)
        self.poller = Poller(self.client, identity, self.capabilities)
        self.state = "BOOT"

    def status(self, state):
        if state not in STATES:
            raise StateError("invalid_state")
        edges = {
            "BOOT": {"CONFIG"},
            "CONFIG": {"IDENTITY"},
            "IDENTITY": {"RECOVERY"},
            "RECOVERY": {"CAPABILITIES"},
            "CAPABILITIES": {"CLAIM"},
            "ONLINE": {"CLAIM"},
            "DEGRADED": {"CLAIM"},
            "CLAIM": {"ONLINE", "DEGRADED", "START"},
            "START": {"RENEW"},
            "RENEW": {"EXECUTE"},
            "EXECUTE": {"RESULT"},
            "RESULT": {"RECONCILE"},
            "RECONCILE": {"IDLE"},
            "IDLE": {"CLAIM"},
        }
        if state not in {"QUARANTINED", "STOPPED"} and state not in edges.get(self.state, set()):
            raise StateError("invalid_transition")
        self.state = state
        atomic_write(
            self.config.state_dir / "status.json",
            {
                "schema_version": 1,
                "state": state,
                "version": __version__,
                "heartbeat_successes": self.heartbeat.successes,
                "heartbeat_max_gap": self.heartbeat.max_gap,
                "poll_successes": self.poller.successes,
                "poll_max_gap": self.poller.max_gap,
            },
        )

    def run(self, logger=None):
        self.config.state_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        if self.config.state_dir.stat().st_mode & 0o077:
            raise StateError("unsafe_state_directory")
        lock = InstanceLock(self.config.state_dir / "instance.lock")
        journal = None
        try:
            status_file = self.config.state_dir / "status.json"
            if status_file.exists():
                old = read_private(status_file)
                if old.get("schema_version") != 1:
                    raise StateError("invalid_status")
            self.status("CONFIG")
            self.status("IDENTITY")
            self.heartbeat.start()
            journal = Journal(self.config.state_dir / "journal.sqlite3")
            self.status("RECOVERY")
            pending = journal.recover()
            self.status("CAPABILITIES")
            # Every outcome is retryable at the scheduling layer, including network down at boot.
            announced = False
            while not self.stop.is_set():
                if self.heartbeat.auth_failed:
                    self.status("QUARANTINED")
                    return 3
                if not announced:
                    result = self.client.call(
                        "capabilities", {"capabilities": list(self.capabilities)}
                    )
                    announced = result.ok and result.body.get("capabilities") == list(
                        self.capabilities
                    )
                if pending:
                    self.client.call("reconcile", {"executions": pending})
                    # Hold remains hold; never replay an execution with unknown outcome.
                self.status("CLAIM")
                result = self.poller.poll()
                if result.status in (401, 403):
                    self.status("QUARANTINED")
                    return 3
                online = self.poller.policy.valid and announced and result.body.get("task") is None
                self.status("ONLINE" if online else "DEGRADED")
                if logger and not online:
                    logger.warning("poll_unavailable")
                self.stop.wait(self.poller.delay(self.config.poll_interval))
            self.status("STOPPED")
            return 0
        finally:
            self.stop.set()
            if self.heartbeat.ident:
                self.heartbeat.join(timeout=2 * self.config.timeout + 2)
            if journal:
                journal.close()
            lock.close()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", default="/etc/agent-center-agent/config.json")
    parser.add_argument("--self-check", action="store_true")
    parser.add_argument("--enroll", action="store_true")
    args = parser.parse_args()
    os.umask(0o077)
    if sys.version_info < (3, 13):
        return 2
    if args.self_check:
        print(
            json.dumps(
                {
                    "version": __version__,
                    "state_schema": 1,
                    "journal_schema": 1,
                    "capabilities": self_check(),
                    "device_operations_frozen": True,
                }
            )
        )
        return 0
    if os.geteuid() == 0:
        print('{"event":"startup_failed","reason":"root_forbidden"}')
        return 2
    logger, listener = configure()
    try:
        config = Config.load(Path(args.config))
        if args.enroll:
            if config.identity_file.exists():
                raise StateError("identity_already_exists")
            if not sys.stdin.isatty():
                raise StateError("interactive_enrollment_required")
            code = getpass.getpass("Enrollment code: ")
            result = Client(config.central_url, timeout=config.timeout, retries=0).call(
                "enroll",
                {
                    "code": code,
                    "hostname": socket.gethostname(),
                    "version": __version__,
                    "capabilities": list(self_check()),
                },
            )
            if not result.ok:
                logger.error("startup_failed")
                return 2
            Identity.validate(result.body).save(config.identity_file)
            return 0
        identity = Identity.load(config.identity_file)
        runtime = Runtime(config, identity)
        for sig in (signal.SIGTERM, signal.SIGINT):
            signal.signal(sig, lambda *_: runtime.stop.set())
        logger.info("online")
        return runtime.run(logger)
    except (OSError, ValueError, StateError, TypeError, KeyError, sqlite3.Error):
        logger.error("startup_failed")
        return 2
    finally:
        listener.stop()


if __name__ == "__main__":
    raise SystemExit(main())
