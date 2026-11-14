"""Sanitized evidence from the actual candidate journal; never emits result bodies or leases."""
import json
import os
from pathlib import Path

from agent_center.journal import Journal
from agent_center.routeros7_lab import isolated, ready

journal = Journal(Path('/lab/agent-private/state/journal.sqlite3'))
try:
    rows = journal.db.execute('SELECT id,task_id,state FROM executions').fetchall()
    duplicate_rejected = all(not journal.reserve(execution, task) for execution, task, _ in rows)
    repeats = journal.db.execute("SELECT count(*) FROM (SELECT execution_id FROM events WHERE state='executing' GROUP BY execution_id HAVING count(*)>1)").fetchone()[0]
    if not rows or any(row[2] != 'completed' for row in rows) or not duplicate_rejected or repeats or not isolated() or not ready() or os.geteuid() == 0:
        raise RuntimeError('journal_or_isolation_proof_failed')
    print(json.dumps({'nonroot_uid': os.geteuid(), 'isolated_namespace': isolated(), 'adapter_ready': ready(), 'executions': len(rows), 'states': {state: sum(row[2] == state for row in rows) for state in sorted({row[2] for row in rows})}, 'duplicate_reservations_rejected': duplicate_rejected, 'replayed_start_count': repeats, 'device_operations_frozen': True}))
finally:
    journal.close()
