import json
import subprocess
import sys
import threading
import time
import unittest
from pathlib import Path
from unittest.mock import patch

from agent_center import observation_worker as worker
from agent_center.api import Outcome
from agent_center.heartbeat import Heartbeat
from agent_center.main import Runtime


class ObservationBoundaryTests(unittest.TestCase):
    def test_stuck_dns_process_is_killed_and_never_returns_allowed(self):
        actual = subprocess.Popen
        children = []

        def stuck(command, **kwargs):
            child = actual([sys.executable, "-c", "import time; time.sleep(60)"], **kwargs)
            children.append(child)
            return child

        with patch.object(worker.subprocess, "Popen", side_effect=stuck):
            started = time.monotonic()
            verdict = worker.bounded_check({"id": "request"}, "/unused", {}, "agent", timeout=0.1)
        self.assertLess(time.monotonic() - started, 1)
        self.assertEqual("UNKNOWN", verdict["state"])
        self.assertEqual(-9, children[0].returncode)

    def test_heartbeat_continues_when_inspector_hangs(self):
        actual = subprocess.Popen

        def stuck(command, **kwargs):
            return actual([sys.executable, "-c", "import time; time.sleep(60)"], **kwargs)

        class Client:
            def call(self, operation, payload):
                return Outcome(True, 200, {"status": "ok"})

        stop = threading.Event()
        heartbeat = Heartbeat(Client(), 0.08, stop, state_dir=Path("/unused"))
        with patch.object(worker.subprocess, "Popen", side_effect=stuck):
            heartbeat.start()
            try:
                time.sleep(0.45)
            finally:
                stop.set()
                heartbeat.join(2)
        self.assertFalse(heartbeat.is_alive())
        self.assertGreaterEqual(heartbeat.successes, 4)
        self.assertLess(heartbeat.max_gap, 0.2)

    def test_worker_never_accepts_an_arbitrary_operation(self):
        result = subprocess.run(
            [sys.executable, "-m", "agent_center.observation_worker"],
            input=json.dumps({"operation": "shell", "values": {}}).encode(),
            capture_output=True, timeout=2,
        )
        self.assertEqual(2, result.returncode)
        self.assertEqual(b"", result.stdout)
        self.assertEqual(b"", result.stderr)

    def test_cold_start_dependency_recovery_and_loss_refresh_both_views(self):
        runtime = object.__new__(Runtime)
        runtime.capabilities = ("agent_info",)
        runtime.capabilities_checked = 0
        runtime.capabilities_announced = True
        runtime.poller = type("Poller", (), {"capabilities": runtime.capabilities})()
        with patch("agent_center.main.available", return_value=("agent_info", "mtr_path_analysis:v1")):
            self.assertTrue(runtime.refresh_capabilities())
        self.assertEqual(runtime.capabilities, runtime.poller.capabilities)
        self.assertFalse(runtime.capabilities_announced)
        with patch("agent_center.main.available", side_effect=AssertionError("refresh too frequent")):
            self.assertFalse(runtime.refresh_capabilities())
        runtime.capabilities_checked = 0
        with patch("agent_center.main.available", return_value=("agent_info",)):
            self.assertTrue(runtime.refresh_capabilities())
        self.assertEqual(("agent_info",), runtime.poller.capabilities)


if __name__ == "__main__":
    unittest.main()
