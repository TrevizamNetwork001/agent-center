"""Bounded public artifact fetch from the locally configured Central origin."""

import re
import urllib.request
from urllib.parse import urlsplit

from ..api import Client
from .engine import UpdateError

MAX_ARTIFACT_BYTES = 8_388_608


class DownloadPending(Exception):
    """A bounded slice is durable; the same precheck continues next timer."""


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


def download_operation(client, operation_id, *, cache=None, size=None):
    """Fetch only the artifact bound by Central to this authenticated operation."""
    import uuid

    parsed = urlsplit(client.base)
    if (parsed.scheme != 'https' or not parsed.hostname or parsed.username
            or parsed.password or parsed.query or parsed.fragment or parsed.path not in ('', '/')):
        raise UpdateError('invalid_release')
    try:
        operation_id = str(uuid.UUID(operation_id))
    except (ValueError, TypeError, AttributeError):
        raise UpdateError('invalid_release') from None
    if not isinstance(client.token, str) or not client.token:
        raise UpdateError('invalid_release')
    if cache is not None:
        return download_slices(client, operation_id, cache, size)
    request = urllib.request.Request(
        client.base + '/api/v1/agent/operations/' + operation_id + '/artifact',
        headers={'Accept': 'application/octet-stream', 'Authorization': 'Bearer ' + client.token},
        method='GET',
    )
    try:
        with client.opener.open(request, timeout=15) as response:
            if response.status != 200:
                raise ValueError()
            length = response.headers.get('Content-Length')
            if length is not None and not 0 < int(length) <= MAX_ARTIFACT_BYTES:
                raise ValueError()
            data = response.read(MAX_ARTIFACT_BYTES + 1)
            if not 0 < len(data) <= MAX_ARTIFACT_BYTES:
                raise ValueError()
            return data
    except Exception:
        raise UpdateError('release_download_failed') from None


def download_slices(client, operation_id, cache, size):
    import os
    import stat
    import time
    from pathlib import Path

    if type(size) is not int or not 0 < size <= MAX_ARTIFACT_BYTES:
        raise UpdateError('invalid_release')
    path = Path(cache) / ('.partial-' + operation_id)
    fd = os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_uid != os.geteuid() or info.st_mode & 0o077 or not 0 <= info.st_size <= size:
            raise UpdateError('invalid_release')
        with os.fdopen(fd, 'r+b', closefd=False) as stream:
            stream.seek(0, 2)
            deadline = time.monotonic() + 60
            for _ in range(2):
                start = stream.tell()
                if start == size:
                    break
                end = min(size - 1, start + 524287)
                request = urllib.request.Request(client.base + '/api/v1/agent/operations/' + operation_id + '/artifact',
                    headers={'Accept': 'application/octet-stream', 'Authorization': 'Bearer ' + client.token,
                             'Range': f'bytes={start}-{end}'}, method='GET')
                try:
                    with client.opener.open(request, timeout=15) as response:
                        if response.status != 206 or response.headers.get('Content-Range') != f'bytes {start}-{end}/{size}':
                            raise UpdateError('release_download_failed')
                        remaining = end - start + 1
                        while remaining:
                            data = response.read1(min(65536, remaining))
                            if not data or len(data) > remaining:
                                raise UpdateError('release_download_failed')
                            stream.write(data)
                            remaining -= len(data)
                            if time.monotonic() >= deadline:
                                break
                finally:
                    stream.flush()
                    os.fsync(stream.fileno())
                if time.monotonic() >= deadline:
                    break
            if stream.tell() != size:
                raise DownloadPending()
            stream.seek(0)
            data = stream.read(MAX_ARTIFACT_BYTES + 1)
        return data
    except DownloadPending:
        raise
    except Exception:
        raise UpdateError('release_download_failed') from None
    finally:
        os.close(fd)
