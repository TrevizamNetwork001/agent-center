import tempfile
import unittest
import uuid
from pathlib import Path
from unittest.mock import Mock, patch

from agent_center.api import Outcome
from agent_center.config import Config
from agent_center.onboarding import enroll
from agent_center.state import StateError


class OnboardingTests(unittest.TestCase):
    def test_receipt_is_resumed_and_identity_is_only_saved_after_approval(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            agent_id = str(uuid.uuid4())
            receipt = str(uuid.uuid4()) + "." + "b" * 64
            config = Config("http://127.0.0.1:18084", root, root / "logs", root / "identity.json")
            client = Mock()
            client.call.side_effect = [
                Outcome(
                    True, 202, dict(receipt=receipt, agent_id=agent_id, state="AWAITING_APPROVAL")
                ),
                Outcome(True, 200, dict(state="AWAITING_APPROVAL")),
                Outcome(
                    True,
                    200,
                    dict(state="ENROLLED", agent_id=agent_id, token=agent_id + "." + "c" * 64),
                ),
            ]
            with patch("agent_center.onboarding.Client", return_value=client):
                self.assertFalse(enroll(config, str(uuid.uuid4()) + "." + "a" * 64))
                self.assertFalse(config.identity_file.exists())
                self.assertEqual((root / "enrollment.json").stat().st_mode & 0o777, 0o600)
                self.assertTrue(enroll(config))
                self.assertTrue(config.identity_file.is_file())
                self.assertFalse((root / "enrollment.json").exists())
                self.assertEqual(
                    sum(call.args[0] == "enroll" for call in client.call.call_args_list), 1
                )
                with self.assertRaises(StateError):
                    enroll(config)

    def test_invalid_request_never_creates_identity_or_receipt(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            config = Config("http://127.0.0.1:18084", root, root / "logs", root / "identity.json")
            client = Mock()
            client.call.return_value = Outcome(False, 401, {})
            with patch("agent_center.onboarding.Client", return_value=client):
                with self.assertRaises(StateError):
                    enroll(config, "invalid")
            self.assertFalse(config.identity_file.exists())
            self.assertFalse((root / "enrollment.json").exists())
