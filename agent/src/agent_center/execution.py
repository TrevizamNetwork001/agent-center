import json
import os
import selectors
import signal
import subprocess
import sys
import threading
import time
import uuid
from pathlib import Path

from .state import StateError, atomic_write, read_private


class Executor:
    """One execution lane, durable before START, isolated from heartbeat and polling."""

    RENEW_INTERVAL = 10

    def __init__(self, config, journal, client, stop):
        self.config, self.journal, self.client, self.stop = config, journal, client, stop
        self.thread = None
        self.cancel = threading.Event()
        self.wake = threading.Event()
        self.active_id = None
        self.leases = config.state_dir / "leases"
        self.leases.mkdir(mode=0o700, exist_ok=True)
        self.phase = "IDLE"

    @property
    def busy(self):
        return self.thread is not None and self.thread.is_alive()

    def accept(self, task, policy):
        if self.stop.is_set() or self.busy or not policy.valid or not policy.tasks or not isinstance(task, dict):
            return False
        try:
            for key in ("task_id", "execution_id"):
                if not isinstance(task[key], str):
                    return False
                uuid.UUID(task[key])
            if (
                type(task["generation"]) is not int
                or task["generation"] < 1
                or type(task["timeout"]) is not int
                or not 1 <= task["timeout"] <= 20
                or task["capability"] not in policy.allowed
                or task["capability"].startswith("device_")
                or not isinstance(task["input"], dict)
            ):
                return False
            token = task["lease_token"]
            if (
                not isinstance(token, str)
                or len(token) != 64
                or any(c not in "0123456789abcdef" for c in token)
            ):
                return False
            if not self.config.config_path:
                return False
            if not self.journal.reserve(task["execution_id"], task["task_id"]):
                return False
            # Credential file is private, deliberately outside the journal/event stream.
            atomic_write(self.leases / (task["execution_id"] + ".json"), self.envelope(task))
            self.active_id = task["execution_id"]
            self.cancel.clear()
            self.wake.clear()
            self.thread = threading.Thread(
                target=self.run, args=(task,), name="execution", daemon=True
            )
            self.thread.start()
            return True
        except (ValueError, KeyError, TypeError, OSError):
            return False

    @staticmethod
    def envelope(task):
        return {key: task[key] for key in ("task_id", "execution_id", "generation", "lease_token")}

    def child_command(self):
        entry = Path(sys.argv[0])
        if entry.suffix == ".pyz":
            return [
                sys.executable,
                "-I",
                str(entry),
                "--config",
                self.config.config_path,
                "--action-worker",
            ]
        return [sys.executable, "-m", "agent_center.action_worker", self.config.config_path]

    @staticmethod
    def kill(child):
        # Descendants can retain the group and stdout after the leader exits.
        try:
            os.killpg(child.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        child.wait(timeout=3)

    def run(self, task):
        child = None
        output_streams = None
        watchdog = None
        group_closed = False
        expired = threading.Event()
        execution = task["execution_id"]
        try:
            if self.stop.is_set() or self.cancel.is_set():
                self.journal.transition(execution, "uncertain")
                return
            # Unknown START outcome can never authorize replay after a crash.
            self.journal.transition(execution, "executing")
            self.phase = "START"
            start = self.client.call("start", self.envelope(task))
            if not start.ok or start.body.get("status") != "ok":
                self.journal.transition(execution, "uncertain")
                return
            if self.stop.is_set() or self.cancel.is_set():
                result = {"ok": False, "error": "cancelled"}
                self.journal.store_result(execution, result)
                self.deliver(self.envelope(task), result)
                return
            self.phase = "EXECUTE"
            child = subprocess.Popen(
                self.child_command(),
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=subprocess.DEVNULL,
                start_new_session=True,
            )
            # Drain stdout while the worker runs. Waiting for exit before
            # reading can fill the pipe and turn a valid result into a timeout.
            output_streams = selectors.DefaultSelector()
            os.set_blocking(child.stdout.fileno(), False)
            output_streams.register(child.stdout, selectors.EVENT_READ)
            raw = bytearray()
            deadline = time.monotonic() + task["timeout"]

            def enforce_deadline():
                if not group_closed:
                    expired.set()
                    try:
                        os.killpg(child.pid, signal.SIGKILL)
                    except ProcessLookupError:
                        pass

            watchdog = threading.Timer(task["timeout"], enforce_deadline)
            watchdog.daemon = True
            watchdog.start()
            child.stdin.write(
                json.dumps({"capability": task["capability"], "input": task["input"]}).encode()
            )
            child.stdin.close()
            renew = time.monotonic() + self.RENEW_INTERVAL
            result = None
            while child.poll() is None or output_streams.get_map():
                now = time.monotonic()
                if self.stop.is_set() or self.cancel.is_set():
                    result = {"ok": False, "error": "cancelled"}
                    break
                if expired.is_set() or now >= deadline:
                    result = {"ok": False, "error": "execution_timeout"}
                    break
                if now >= renew and child.poll() is None:
                    self.phase = "RENEW"
                    outcome = self.client.call("renew", self.envelope(task))
                    if expired.is_set() or time.monotonic() >= deadline:
                        result = {"ok": False, "error": "execution_timeout"}
                        break
                    if (
                        not outcome.ok
                        or outcome.body.get("cancel")
                        or outcome.body.get("status") != "ok"
                    ):
                        result = {
                            "ok": False,
                            "error": "cancelled" if outcome.body.get("cancel") else "lease_lost",
                        }
                        break
                    renew = time.monotonic() + self.RENEW_INTERVAL
                    self.phase = "EXECUTE"
                for key, _ in output_streams.select(0.05):
                    chunk = os.read(key.fileobj.fileno(), min(4096, 65537 - len(raw)))
                    if not chunk:
                        output_streams.unregister(key.fileobj)
                    else:
                        raw.extend(chunk)
                if len(raw) > 65536:
                    result = {"ok": False, "error": "internal_error"}
                    break
            if expired.is_set() or time.monotonic() >= deadline:
                result = {"ok": False, "error": "execution_timeout"}
            if result is None:
                if len(raw) > 65536 or child.returncode != 0:
                    result = {"ok": False, "error": "internal_error"}
                else:
                    result = json.loads(raw)
                    if not isinstance(result, dict) or not isinstance(result.get("ok"), bool):
                        result = {"ok": False, "error": "internal_error"}
            self.kill(child)
            group_closed = True
            self.journal.store_result(execution, result)
            self.phase = "RESULT"
            self.deliver(self.envelope(task), result)
        except (OSError, ValueError, KeyError, TypeError, RecursionError, subprocess.SubprocessError):
            self.journal.transition(execution, "uncertain")
        finally:
            if watchdog:
                watchdog.cancel()
                watchdog.join(timeout=3)
            if child:
                if not group_closed:
                    self.kill(child)
                if output_streams:
                    output_streams.close()
                if child.stdout:
                    child.stdout.close()
            self.phase = "IDLE"
            self.active_id = None
            self.wake.set()

    def deliver(self, envelope, result):
        outcome = self.client.call("result", envelope | {"result": result})
        if outcome.ok and outcome.body.get("status") == "ok":
            self.journal.transition(envelope["execution_id"], "completed")
            (self.leases / (envelope["execution_id"] + ".json")).unlink(missing_ok=True)

    def recover(self):
        if self.busy:
            return
        pending = self.journal.pending()
        if not pending:
            return
        outcome = self.client.call("reconcile", {"executions": pending})
        if not outcome.ok or not isinstance(outcome.body.get("executions"), list):
            return
        for entry in outcome.body["executions"]:
            if not isinstance(entry, dict):
                continue
            execution = entry.get("execution_id")
            if not isinstance(execution, str) or not any(
                e["execution_id"] == execution for e in pending
            ):
                continue
            path = self.leases / (execution + ".json")
            disposition = entry.get("disposition")
            if disposition in ("acknowledged", "expired", "cancel"):
                self.journal.transition(execution, "completed")
                path.unlink(missing_ok=True)
            elif disposition == "resend_result" and path.exists():
                try:
                    result = self.journal.result(execution)
                    if result is not None:
                        self.deliver(read_private(path), result)
                except (OSError, ValueError, TypeError, StateError):
                    pass

    def close(self):
        self.cancel.set()
        if self.thread:
            self.thread.join(timeout=2 * self.config.timeout + 6)
