import re
import uuid
from dataclasses import dataclass
from pathlib import Path

from .state import InstanceLock, StateError, atomic_write, read_private


@dataclass(frozen=True, repr=False)
class Identity:
    agent_id: str
    token: str

    @classmethod
    def validate(cls, data):
        try:
            agent_id = str(uuid.UUID(data["agent_id"]))
            token = data["token"]
            if not isinstance(token, str) or not re.fullmatch(
                re.escape(agent_id) + r"\.[0-9a-f]{64}", token
            ):
                raise ValueError()
            return cls(agent_id, token)
        except (KeyError, ValueError, TypeError, AttributeError) as exc:
            raise StateError("invalid_identity") from exc

    @classmethod
    def load(cls, path: Path):
        return cls.validate(read_private(path))

    def save(self, path: Path):
        lock = InstanceLock(path.parent / "instance.lock")
        try:
            if path.exists():
                raise StateError("identity_already_exists")
            atomic_write(path, {"agent_id": self.agent_id, "token": self.token})
        finally:
            lock.close()
