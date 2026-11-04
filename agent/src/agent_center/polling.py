import time

from .policy import Policy


class Poller:
    def __init__(self, client, identity, capabilities):
        self.client, self.identity, self.capabilities = client, identity, capabilities
        self.policy = Policy(False)
        self.successes = 0
        self.failures = 0
        self.last_success = None
        self.max_gap = 0.0

    def poll(self):
        result = self.client.call("claim", {})
        if result.ok and "task" in result.body:
            self.policy = Policy.parse(
                result.body.get("policy"), self.identity.agent_id, self.capabilities
            )
            if self.policy.valid and result.body["task"] is None:
                now = time.monotonic()
                if self.last_success is not None:
                    self.max_gap = max(self.max_gap, now - self.last_success)
                self.last_success = now
                self.successes += 1
                self.failures = 0
                return result
        self.policy = Policy(False)
        self.failures += 1
        return result

    def delay(self, normal):
        return min(60, normal * 2 ** min(self.failures, 4))
