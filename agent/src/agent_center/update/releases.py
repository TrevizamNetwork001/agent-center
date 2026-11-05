"""Bounded public artifact fetch from the locally configured Central origin."""

import re
import urllib.request
from urllib.parse import urlsplit

from ..api import Client
from .engine import UpdateError

MAX_ARTIFACT_BYTES = 8_388_608


def download(origin, sha):
    if not isinstance(origin, str) or not isinstance(sha, str):
        raise UpdateError("invalid_release")
    parsed = urlsplit(origin)
    if (
        parsed.scheme != "https"
        or not parsed.hostname
        or parsed.username
        or parsed.password
        or parsed.query
        or parsed.fragment
        or parsed.path not in ("", "/")
        or not re.fullmatch(r"[a-f0-9]{64}", sha)
    ):
        raise UpdateError("invalid_release")
    # No URL, path, proxy or credential from an operation is accepted.
    client = Client(origin.rstrip("/"), retries=0)
    request = urllib.request.Request(
        client.base + "/downloads/agent/releases/" + sha + ".pyz",
        headers={"Accept": "application/octet-stream"},
        method="GET",
    )
    try:
        with client.opener.open(request, timeout=15) as response:
            if response.status != 200:
                raise ValueError()
            length = response.headers.get("Content-Length")
            if length is not None and not 0 < int(length) <= MAX_ARTIFACT_BYTES:
                raise ValueError()
            data = response.read(MAX_ARTIFACT_BYTES + 1)
            if not data or len(data) > MAX_ARTIFACT_BYTES:
                raise ValueError()
            return data
    except Exception:
        raise UpdateError("release_download_failed") from None
