import argparse
import getpass
import json
import os
import signal
import sqlite3
import sys
import threading
import time
from pathlib import Path

from . import __version__
from .api import Client
from .capabilities import available, self_check
from .config import Config
from .execution import Executor
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
        self_check()
        self.capabilities = available()
        self.capabilities_checked = time.monotonic()
        self.capabilities_announced = False
        # Independent clients; never share journal, file locks or a connection with heartbeat.
        self.heartbeat = Heartbeat(
            client_factory(config.central_url, identity.token, config.timeout),
            config.heartbeat_interval,
            self.stop,
            state_dir=config.state_dir,
            agent_id=identity.agent_id,
            config_path=config.config_path,
        )
        self.client = client_factory(config.central_url, identity.token, config.timeout)
        self.poller = Poller(self.client, identity, self.capabilities)
        self.state = "BOOT"

    def refresh_capabilities(self):
        now = time.monotonic()
        if now - self.capabilities_checked < 60:
            return False
        updated = available()
        self.capabilities_checked = time.monotonic()
        if updated == self.capabilities:
            return False
        self.capabilities = updated
        self.poller.capabilities = updated
        self.capabilities_announced = False
        return True

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
                "capabilities": list(self.capabilities) if self.capabilities_announced else [],
                "capabilities_announced": self.capabilities_announced,
                "heartbeat_successes": self.heartbeat.successes,
                "heartbeat_max_gap": self.heartbeat.max_gap,
                "poll_successes": self.poller.successes,
                "poll_max_gap": self.poller.max_gap,
            },
        )

    def wait_for_work(self, executor, poll_started, good_poll):
        delay = self.poller.delay(self.config.poll_interval)
        if not good_poll:
            # Completion cannot bypass transport/policy failure backoff.
            self.stop.wait(delay)
            return
        deadline = time.monotonic() + delay
        earliest = poll_started + min(2.0, self.config.poll_interval)
        while not self.stop.is_set():
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                return
            if executor.wake.wait(min(0.1, remaining)):
                executor.wake.clear()
                # Do not advertise capacity before the isolated lane has exited.
                if executor.thread:
                    executor.thread.join(timeout=0.1)
                self.stop.wait(max(0, earliest - time.monotonic()))
                return

    def run(self, logger=None):
        self.config.state_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
        if self.config.state_dir.stat().st_mode & 0o077:
            raise StateError("unsafe_state_directory")
        lock = InstanceLock(self.config.state_dir / "instance.lock")
        journal = None
        executor = None
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
            journal.recover()
            executor = Executor(
                self.config,
                journal,
                Client(self.config.central_url, self.identity.token, self.config.timeout),
                self.stop,
            )
            self.status("CAPABILITIES")
            # Every outcome is retryable at the scheduling layer, including network down at boot.
            announced = False
            while not self.stop.is_set():
                if self.heartbeat.auth_failed:
                    self.status("QUARANTINED")
                    return 3
                if self.refresh_capabilities():
                    announced = False
                if not announced:
                    result = self.client.call(
                        "capabilities",
                        {"capabilities": list(self.capabilities), "task_protocol": 1},
                    )
                    announced = result.ok and result.body.get("capabilities") == list(
                        self.capabilities
                    )
                self.capabilities_announced = announced
                executor.recover()
                self.status("CLAIM")
                poll_started = time.monotonic()
                result = self.poller.poll(0 if not announced or executor.busy or journal.pending() else 1)
                cancellations = result.body.get("cancel_execution_ids", [])
                if isinstance(cancellations, list) and executor.active_id in cancellations:
                    executor.cancel.set()
                if result.ok and result.body.get("task") is not None:
                    executor.accept(result.body["task"], self.poller.policy)
                if result.status in (401, 403) and result.body.get("error") != "origin_not_allowed":
                    self.status("QUARANTINED")
                    return 3
                online = self.poller.policy.valid and announced
                self.status("ONLINE" if online else "DEGRADED")
                if logger and not online:
                    logger.warning("poll_unavailable")
                self.wait_for_work(executor, poll_started, result.ok and self.poller.policy.valid)
            self.status("STOPPED")
            return 0
        finally:
            self.stop.set()
            if self.heartbeat.ident:
                self.heartbeat.join(timeout=2 * self.config.timeout + 2)
            if executor:
                executor.close()
            if journal:
                journal.close()
            lock.close()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", default="/etc/agent-center-agent/config.json")
    parser.add_argument("--self-check", action="store_true")
    parser.add_argument("--enroll", action="store_true")
    parser.add_argument("--action-worker", action="store_true", help=argparse.SUPPRESS)
    parser.add_argument("--observation-worker", action="store_true", help=argparse.SUPPRESS)
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
    if args.action_worker:
        from .action_worker import main as action_main

        return action_main(args.config)
    if args.observation_worker:
        from .observation_worker import main as observation_main

        return observation_main()
    logger, listener = configure()
    try:
        config = Config.load(Path(args.config))
        if args.enroll:
            from .onboarding import enroll

            pending = config.state_dir / "enrollment.json"
            code = None
            if not pending.exists():
                if not sys.stdin.isatty():
                    raise StateError("interactive_enrollment_required")
                code = getpass.getpass("Enrollment code: ")
            ready = enroll(config, code)
            print(json.dumps({"state": "ENROLLED" if ready else "AWAITING_APPROVAL"}))
            return 0 if ready else 4
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
