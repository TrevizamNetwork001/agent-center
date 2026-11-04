"""Observe only the dedicated lab container; no secrets are read or emitted."""

import argparse
import json
import subprocess
import time
from datetime import datetime, timezone
from pathlib import Path

PROBE = r"""
import json, pathlib, sqlite3
p=pathlib.Path('/proc/1')
status=dict(line.split(':',1) for line in (p/'status').read_text().splitlines() if ':' in line)
fds=list((p/'fd').iterdir())
sockets=0
for fd in fds:
    try: sockets += int(fd.readlink().as_posix().startswith('socket:'))
    except FileNotFoundError: pass
stat=(p/'stat').read_text().split()
state=json.loads(pathlib.Path('/lab/state/status.json').read_text())
c=sqlite3.connect('file:/lab/state/journal.sqlite3?mode=ro',uri=True)
print(json.dumps(dict(rss_kib=int(status['VmRSS'].split()[0]),threads=int(status['Threads']),fds=len(fds),sockets=sockets,children=len((p/'task/1/children').read_text().split()),cpu_ticks=int(stat[13])+int(stat[14]),journal_ok=c.execute('PRAGMA integrity_check').fetchone()[0]=='ok',journal_events=c.execute('SELECT count(*) FROM events').fetchone()[0],state=state)))
c.close()
"""
parser = argparse.ArgumentParser()
parser.add_argument("--duration", type=int, default=900)
parser.add_argument("--sample", type=int, default=15)
parser.add_argument("--output", default="/tmp/agent-center-recovery2-longrun.json")
parser.add_argument("--container",default="agent-center-recovery2-agent-lab")
parser.add_argument("--worker-container")
parser.add_argument("--queue-container")
args = parser.parse_args()
if args.duration < 900:
    raise SystemExit("Long-run requires at least 900 seconds")
start = time.monotonic()
rows = []
while True:
    result = subprocess.run(
        ["docker", "exec", args.container, "python", "-c", PROBE],
        capture_output=True,
        timeout=20,
        check=True,
    )
    row = json.loads(result.stdout)
    row["timestamp_utc"] = datetime.now(timezone.utc).isoformat()
    if args.worker_container:
        probe = subprocess.run(["docker", "exec", args.worker_container, "php", "artisan", "agent-center:worker-health"], capture_output=True, timeout=20)
        row["worker_and_database_ok"] = probe.returncode == 0
    if args.queue_container:
        code = "require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $age=App\\Jobs\\OperationsPulse::age(); exit($age!==null && $age<90 ? 0:1);"
        probe = subprocess.run(["docker", "exec", args.queue_container, "php", "-r", code], capture_output=True, timeout=20)
        row["queue_progress_ok"] = probe.returncode == 0
    row["elapsed"] = round(time.monotonic() - start, 2)
    rows.append(row)
    print(json.dumps(row), flush=True)
    elapsed = time.monotonic() - start
    if elapsed >= args.duration:
        break
    time.sleep(min(args.sample, args.duration - elapsed))
last = rows[-1]["state"]
summary = dict(
    duration_seconds=round(time.monotonic() - start, 2),
    samples=len(rows),
    heartbeat_successes=last["heartbeat_successes"],
    heartbeat_max_gap=last["heartbeat_max_gap"],
    poll_successes=last["poll_successes"],
    poll_max_gap=last.get("poll_max_gap"),
    rss_min_kib=min(r["rss_kib"] for r in rows),
    rss_max_kib=max(r["rss_kib"] for r in rows),
    rss_growth_kib=rows[-1]["rss_kib"] - rows[0]["rss_kib"],
    fds_min=min(r["fds"] for r in rows),
    fds_max=max(r["fds"] for r in rows),
    threads_max=max(r["threads"] for r in rows),
    sockets_max=max(r["sockets"] for r in rows),
    children_max=max(r["children"] for r in rows),
    cpu_seconds=(rows[-1]["cpu_ticks"] - rows[0]["cpu_ticks"]) / 100,
    journal_ok=all(r["journal_ok"] for r in rows),
    journal_events_max=max(r["journal_events"] for r in rows),
)
summary["pass"] = bool(
    summary["duration_seconds"] >= 900
    and summary["heartbeat_successes"] >= 40
    and summary["heartbeat_max_gap"] < 60
    and summary["poll_successes"] >= 150
    and summary["poll_max_gap"] < 30
    and summary["rss_growth_kib"] < 10240
    and summary["fds_max"] - summary["fds_min"] <= 4
    and summary["threads_max"] <= 4
    and summary["children_max"] <= 1
    and rows[-1]["children"] == 0
    and summary["journal_ok"]
    and all(r["state"]["state"]=="ONLINE" for r in rows)
    and all(r.get("worker_and_database_ok",True) and r.get("queue_progress_ok",True) for r in rows)
)
Path(args.output).write_text(json.dumps(summary, indent=2) + "\n")
print(json.dumps(summary), flush=True)
raise SystemExit(0 if summary["pass"] else 1)
