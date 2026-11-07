import threading
import time

from . import __version__
from .managed_settings import apply
from .observability import check, record_applied
from .observability import snapshot as operational_snapshot
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
        self.permission_result = None

    def run(self):
        deadline = time.monotonic()
        while not self.stop_event.is_set():
            report = operational_snapshot(self.state_dir, self.policy)
            payload = {
                "version": __version__,
                "telemetry": snapshot(self.state_dir),
                "observability": report,
            }
            if self.permission_result is not None:
                payload["permission_result"] = self.permission_result
            result = self.client.call("heartbeat", payload)
            now = time.monotonic()
            if result.ok and result.body.get("status") == "ok":
                if self.last_success is not None:
                    self.max_gap = max(self.max_gap, now - self.last_success)
                self.last_success = now
                self.successes += 1
                try:
                    self.policy = result.body.get("policy")
                    if apply(self.state_dir, self.policy, self.agent_id):
                        record_applied(self.state_dir)
                    request = result.body.get("permission_request")
                    if isinstance(request, dict):
                        self.permission_result = check(
                            request, self.config_path, self.policy, self.agent_id
                        )
                except (StateError, OSError, ValueError, TypeError):
                    pass
            if result.status in (401, 403) and result.body.get("error") != "origin_not_allowed":
                self.auth_failed = True
                return
            deadline += self.interval
            if deadline < now:
                deadline = now + self.interval
            self.stop_event.wait(max(0, deadline - time.monotonic()))
