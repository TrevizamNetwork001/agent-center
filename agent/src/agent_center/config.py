import ipaddress
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import urlsplit

from .state import StateError, read_private


@dataclass(frozen=True)
class Config:
    central_url: str
    state_dir: Path
    log_dir: Path
    identity_file: Path
    heartbeat_interval: float = 20
    poll_interval: float = 5
    timeout: float = 5
    device_operations_frozen: bool = True
    allowed_networks: tuple = ()

    @classmethod
    def load(cls, path: Path):
        data = read_private(path)
        try:
            url = data["central_url"]
            parsed = urlsplit(url)
            loopback = parsed.hostname == "localhost"
            try:
                loopback = loopback or ipaddress.ip_address(parsed.hostname).is_loopback
            except ValueError:
                pass
            if not (
                parsed.scheme == "https"
                or (
                    parsed.scheme == "http" and loopback and data.get("allow_http_loopback") is True
                )
            ):
                raise ValueError()
            if (
                parsed.username
                or parsed.password
                or parsed.query
                or parsed.fragment
                or parsed.path not in ("", "/")
                or not parsed.hostname
            ):
                raise ValueError()
            paths = [Path(data[k]) for k in ("state_dir", "log_dir", "identity_file")]
            if (
                any(not p.is_absolute() or p.is_symlink() for p in paths)
                or paths[2].parent != paths[0]
            ):
                raise ValueError()
            intervals = [
                data.get("heartbeat_interval", 20),
                data.get("poll_interval", 5),
                data.get("timeout", 5),
            ]
            if any(type(v) not in (int, float) for v in intervals) or not (
                1 <= intervals[0] <= 30 and 1 <= intervals[1] <= 60 and 0.1 <= intervals[2] <= 10
            ):
                raise ValueError()
            if data.get("device_operations_frozen", True) is not True:
                raise ValueError()
            nets = tuple(str(ipaddress.ip_network(v)) for v in data.get("allowed_networks", []))
            return cls(url.rstrip("/"), *paths, *intervals, True, nets)
        except (KeyError, TypeError, ValueError) as exc:
            raise StateError("invalid_config") from exc
