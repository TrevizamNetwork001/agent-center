import threading
import time

from . import __version__
from .managed_settings import apply
from .observation_worker import bounded_snapshot
from .preflight import check
from .state import StateError
from .telemetry import snapshot


class Heartbeat(threading.Thread):
    def __init__(self, client, interval, stop, state_dir=None, agent_id=None, config_path=None):
        super().__init__(name="agent-heartbeat", daemon=True)
        self.client, self.interval, self.stop_event = client, interval, stop
        self.state_dir, self.agent_id = state_dir, agent_id
        self.config_path = config_path
        self.successes = 0
        self.last_success = None
        self.max_gap = 0.0
        self.auth_failed = False
        self.policy = None

    def run(self):
        deadline = time.monotonic()
        while not self.stop_event.is_set():
            result = self.client.call(
                "heartbeat", {
                    "version": __version__, "telemetry": snapshot(self.state_dir),
                    "observability": bounded_snapshot(
                        self.state_dir, self.policy, timeout=min(2.0, self.interval / 4)
                    ),
                }
            )
            now = time.monotonic()
            if result.ok and result.body.get("status") == "ok":
                if self.last_success is not None:
                    self.max_gap = max(self.max_gap, now - self.last_success)
                self.last_success = now
                self.successes += 1
                try:
                    policy = result.body.get("policy")
                    self.policy = policy
                    if apply(self.state_dir, policy, self.agent_id) and self.config_path:
                        pending = self.client.call("diagnostics/preflight", {"schema_version": 1})
                        request = pending.body.get("request") if pending.ok else None
                        if isinstance(request, dict):
                            decision = check(request, self.config_path, policy, self.agent_id)
                            self.client.call("diagnostics/preflight", {"schema_version": 1, "result": decision})
                except (StateError, OSError, ValueError, TypeError):
                    pass
            if result.status in (401, 403) and result.body.get("error") != "origin_not_allowed":
                self.auth_failed = True
                return
            deadline += self.interval
            if deadline < now:
                deadline = now + self.interval
            self.stop_event.wait(max(0, deadline - time.monotonic()))
