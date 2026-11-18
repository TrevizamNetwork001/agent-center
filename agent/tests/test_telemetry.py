import tempfile
import unittest
from pathlib import Path

from agent_center.journal import Journal
from agent_center.telemetry import snapshot


class TelemetryTests(unittest.TestCase):
    def test_inventory_does_not_leak_descriptors_or_invent_service_health(self):
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory)
            journal = Journal(state / "journal.sqlite3")
            snapshot(state)  # Initialize SQLite shared-memory descriptors before measuring growth.
            before = len(list(Path("/proc/self/fd").iterdir()))
            for _ in range(100):
                data = snapshot(state)
                self.assertTrue(data["journal_ok"])
                self.assertIsNone(data["service_status"])
                self.assertTrue(data["device_operations_frozen"])
                self.assertNotIn("token", data)
            self.assertLessEqual(len(list(Path("/proc/self/fd").iterdir())), before)
            journal.close()

    def test_missing_journal_is_unavailable(self):
        with tempfile.TemporaryDirectory() as directory:
            self.assertIsNone(snapshot(Path(directory))["journal_ok"])
