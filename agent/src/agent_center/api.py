import http.client
import json
import socket
import ssl
import time
import urllib.error
import urllib.request
from dataclasses import dataclass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


@dataclass(frozen=True)
class Outcome:
    ok: bool
    status: int
    body: dict
    error: str = ""
    retryable: bool = False


class Client:
    def __init__(self, base, token=None, timeout=5, retries=1):
        self.base, self.token, self.timeout, self.retries = base, token, timeout, retries
        self.opener = urllib.request.build_opener(
            urllib.request.ProxyHandler({}),
            NoRedirect(),
            urllib.request.HTTPSHandler(context=ssl.create_default_context()),
        )

    def call(self, operation, data=None):
        if operation not in {
            "enroll",
            "enrollment-status",
            "operations/claim",
            "operations/report",
            "heartbeat",
            "capabilities",
            "claim",
            "start",
            "renew",
            "result",
            "reconcile",
        }:
            return Outcome(False, 0, {}, "invalid_operation")
        try:
            payload = json.dumps(data or {}).encode()
        except (ValueError, TypeError):
            return Outcome(False, 0, {}, "invalid_payload")
        attempts = 1 if operation in {"enroll", "enrollment-status"} else self.retries + 1
        for attempt in range(attempts):
            status, retryable = 0, True
            headers = {"Content-Type": "application/json", "Accept": "application/json"}
            if self.token:
                headers["Authorization"] = "Bearer " + self.token
            try:
                req = urllib.request.Request(
                    self.base + "/api/v1/agent/" + operation, payload, headers, method="POST"
                )
                with self.opener.open(req, timeout=self.timeout) as response:
                    raw = response.read(1_048_577)
                    status = response.status
                    if len(raw) > 1_048_576:
                        return Outcome(False, status, {}, "oversized_response")
                    body = json.loads(raw)
                    if not isinstance(body, dict):
                        return Outcome(False, status, {}, "incomplete_response")
                    return Outcome(True, status, body)
            except urllib.error.HTTPError as error:
                status = error.code
                if status == 403:
                    try:
                        denied = json.loads(error.read(4097))
                        if isinstance(denied, dict) and denied.get("error") == "origin_not_allowed":
                            error.close()
                            return Outcome(
                                False,
                                status,
                                {"error": "origin_not_allowed"},
                                "origin_not_allowed",
                                False,
                            )
                    except (ValueError, OSError):
                        pass
                error.close()
                retryable = status == 429 or status >= 500
                reason = "http_" + str(status)
            except (json.JSONDecodeError, UnicodeError, http.client.IncompleteRead):
                reason = "invalid_response"
            except (
                OSError,
                urllib.error.URLError,
                http.client.HTTPException,
                socket.timeout,
                ValueError,
            ):
                reason = "network_error"
            if not retryable or attempt + 1 == attempts:
                return Outcome(False, status, {}, reason, retryable)
            time.sleep(min(0.25 * 2**attempt, 1))
        return Outcome(False, 0, {}, "network_error", True)
