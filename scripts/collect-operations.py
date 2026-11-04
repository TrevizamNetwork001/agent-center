#!/usr/bin/env python3
"""Fixed infrastructure probes. No commands or targets from browser/tenant input."""

import argparse
import collections
import json
import os
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path


def collect():
    timestamp = datetime.now(timezone.utc).isoformat()
    rows = []
    for component, container in [
        ("nginx_external", "agent-center-nginx-1"),
        ("fpm_process", "agent-center-web-1"),
        ("postgresql_container", "agent-center-postgres-1"),
        ("queue_container", "agent-center-worker-1"),
        ("diagnostic_container", "agent-center-diagnostic-worker-1"),
    ]:
        try:
            result = subprocess.run(
                [
                    "docker",
                    "inspect",
                    "--format",
                    "{{.State.Health.Status}}",
                    container,
                ],
                capture_output=True,
                check=True,
                timeout=10,
            )
            healthy = result.stdout.strip() == b"healthy"
            rows.append(
                dict(
                    component=component,
                    state="VERDE" if healthy else "NO-GO",
                    reason="container_functional_health",
                    timestamp=timestamp,
                    values={"healthy": healthy},
                    limit=True,
                )
            )
        except (OSError, subprocess.SubprocessError):
            rows.append(
                dict(
                    component=component,
                    state="ATENÇÃO",
                    reason="probe_unavailable",
                    timestamp=timestamp,
                    values={"healthy": None},
                    limit=True,
                )
            )
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    for component, path, expected in [
        ("web_https", "/health/ready", 200),
        ("agent_api_external", "/api/v1/agent/enroll", 422),
    ]:
        status = None
        try:
            request = urllib.request.Request(
                "https://agente.trevizamnetwork.com.br" + path,
                data=b"{}" if expected == 422 else None,
                headers={
                    "Accept": "application/json",
                    "Content-Type": "application/json",
                },
            )
            with opener.open(request, timeout=10) as response:
                status = response.status
        except urllib.error.HTTPError as exc:
            status = exc.code
            exc.close()
        except (OSError, urllib.error.URLError):
            pass
        rows.append(
            dict(
                component=component,
                state="VERDE" if status == expected else "NO-GO",
                reason="https_status_probe",
                timestamp=timestamp,
                values={"http_status": status},
                limit={"expected_status": expected},
            )
        )
    path = Path("/var/log/nginx/agent-center-status.log")
    counts = collections.Counter()
    if path.is_file():
        with path.open() as handle:
            for line in collections.deque(handle, maxlen=10000):
                try:
                    stamp, status, _duration = line.split()
                    if float(stamp) >= time.time() - 300:
                        counts["total"] += 1
                        counts["errors"] += int(int(status) >= 500)
                except ValueError:
                    pass
    rate = counts["errors"] / counts["total"] if counts["total"] else None
    rows.append(
        dict(
            component="http_error_rate",
            state="ATENÇÃO" if rate is None else ("NO-GO" if rate > 0.05 else "VERDE"),
            reason="sanitized_status_log_5m"
            if rate is not None
            else "no_status_samples",
            timestamp=timestamp,
            values={
                "requests": counts["total"],
                "http_5xx": counts["errors"],
                "rate": rate,
            },
            limit={"max_rate": 0.05, "window_seconds": 300, "max_samples": 10000},
        )
    )
    root = Path(__file__).resolve().parents[1]
    for component, filename in [('backup_local', 'local-status.json'),
                                ('backup_external', 'external-status.json')]:
        state, reason, age = 'ATENÇÃO', 'evidence_unavailable', None
        try:
            data = json.loads((root / 'backups' / filename).read_text())
            state, reason = data['state'], data['reason']
            if state not in ('VERDE', 'ATENÇÃO', 'NO-GO'):
                raise ValueError('invalid_state')
            if state == 'VERDE' and data.get('project') != 'agent-center':
                state, reason = 'ATENÇÃO', 'production_scope_unproven'
            if state == 'VERDE':
                checked = datetime.fromisoformat(data['checked_at']).timestamp()
                generation = datetime.strptime(data['generation'], '%Y%m%dT%H%M%SZ').replace(tzinfo=timezone.utc).timestamp()
                age = time.time() - min(checked, generation)
                if age < 0 or age > 26 * 3600:
                    state, reason = 'NO-GO', 'verified_backup_stale'
        except (OSError, ValueError, KeyError, TypeError):
            state, reason = 'ATENÇÃO', 'evidence_unavailable'
        rows.append(dict(component=component, state=state, reason=reason,
                         timestamp=timestamp, values={'age_seconds': age},
                         limit={'max_age_seconds': 26 * 3600}))
    return rows


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    rows = collect()
    args.output.parent.mkdir(parents=True, exist_ok=True)
    fd, path = tempfile.mkstemp(dir=args.output.parent, prefix=".operations-")
    with os.fdopen(fd, "w") as stream:
        json.dump(
            {"collected_at": datetime.now(timezone.utc).isoformat(), "checks": rows},
            stream,
        )
        stream.flush()
        os.fsync(stream.fileno())
    os.chmod(path, 0o644)
    os.replace(path, args.output)


if __name__ == "__main__":
    main()
