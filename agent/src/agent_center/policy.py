from dataclasses import dataclass


@dataclass(frozen=True)
class Policy:
    valid: bool
    allowed: tuple = ()
    frozen: bool = True
    tasks: bool = False

    @classmethod
    def parse(cls, raw, agent_id, capabilities):
        if (
            not isinstance(raw, dict)
            or raw.get("agent_id") != agent_id
            or type(raw.get("schema_version")) is not int
            or raw.get("schema_version") != 1
        ):
            return cls(False)
        if (
            raw.get("device_operations_frozen", True) is not True
            or type(raw.get("tasks_enabled", False)) is not bool
            or raw.get("tasks_enabled", False) is True
            and (type(raw.get("task_protocol")) is not int or raw.get("task_protocol") != 1)
        ):
            return cls(False)
        allowed = raw.get("allowed_capabilities", [])
        if not isinstance(allowed, list) or any(
            not isinstance(v, str) or v.startswith("device_") or v not in capabilities
            for v in allowed
        ):
            # Central may know a prepared capability absent from this particular host.
            if not isinstance(allowed, list) or any(
                not isinstance(v, str) or v.startswith("device_") for v in allowed
            ):
                return cls(False)
        return cls(
            True,
            tuple(v for v in allowed if v in capabilities),
            True,
            raw.get("tasks_enabled", False) is True,
        )


def authorize(name, policy, available):
    if not isinstance(name, str) or name.startswith("device_"):
        return False
    return policy.valid and name in policy.allowed and name in available
