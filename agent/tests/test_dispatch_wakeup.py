import threading
import time
import unittest
from types import SimpleNamespace
from unittest.mock import Mock

from agent_center.main import Runtime


class WakeupTests(unittest.TestCase):
    def runtime(self, interval=5, factor=1):
        runtime = object.__new__(Runtime)
        runtime.config = SimpleNamespace(poll_interval=interval)
        runtime.stop = threading.Event()
        runtime.poller = SimpleNamespace(delay=lambda value: value * factor)
        return runtime

    def test_acknowledged_completion_wakes_without_sleeping_poll_period(self):
        r = self.runtime()
        e = SimpleNamespace(wake=threading.Event(), thread=Mock())
        signal = threading.Timer(0.05, e.wake.set)
        signal.start()
        start = time.monotonic()
        r.wait_for_work(e, start - 2.1, True)
        self.assertLess(time.monotonic() - start, 0.5)
        e.thread.join.assert_called_once()
        self.assertFalse(e.wake.is_set())
        signal.join()

    def test_wakeup_keeps_dispatch_rate_floor(self):
        r = self.runtime(interval=0.15)
        e = SimpleNamespace(wake=threading.Event(), thread=None)
        e.wake.set()
        start = time.monotonic()
        r.wait_for_work(e, start, True)
        self.assertGreaterEqual(time.monotonic() - start, 0.14)

    def test_completion_does_not_bypass_failed_poll_backoff(self):
        r = self.runtime(interval=0.05, factor=2)
        e = SimpleNamespace(wake=threading.Event(), thread=None)
        e.wake.set()
        start = time.monotonic()
        r.wait_for_work(e, start, False)
        self.assertGreaterEqual(time.monotonic() - start, 0.09)
        self.assertTrue(e.wake.is_set())

    def test_idle_wait_does_not_spin_and_stop_remains_responsive(self):
        r = self.runtime()
        e = SimpleNamespace(wake=threading.Event(), thread=None)
        signal = threading.Timer(0.03, r.stop.set)
        signal.start()
        start = time.monotonic()
        r.wait_for_work(e, start, True)
        self.assertLess(time.monotonic() - start, 0.5)
        signal.join()
