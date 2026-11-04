import json
import sqlite3
import threading
from pathlib import Path

EDGES = {
    "claimed": {"executing", "uncertain", "completed"},
    "executing": {"result_pending", "uncertain"},
    "result_pending": {"completed", "uncertain"},
    "uncertain": {"completed"},
    "completed": set(),
}


class Journal:
    def __init__(self, path: Path):
        if path.is_symlink():
            raise ValueError("unsafe_journal")
        self.lock = threading.Lock()
        self.db = sqlite3.connect(path, timeout=5, check_same_thread=False)
        path.chmod(0o600)
        self.db.execute("PRAGMA journal_mode=WAL")
        self.db.execute("PRAGMA synchronous=FULL")
        if self.db.execute("PRAGMA user_version").fetchone()[0] > 1:
            raise ValueError("unsupported_journal_schema")
        self.db.executescript(
            "CREATE TABLE IF NOT EXISTS executions(id TEXT PRIMARY KEY,task_id TEXT NOT NULL,state TEXT NOT NULL); CREATE TABLE IF NOT EXISTS events(seq INTEGER PRIMARY KEY AUTOINCREMENT,execution_id TEXT NOT NULL,state TEXT NOT NULL);"
        )

        columns = {row[1] for row in self.db.execute("PRAGMA table_info(executions)")}
        if "result" not in columns:
            self.db.execute("ALTER TABLE executions ADD COLUMN result TEXT")
        self.db.execute("PRAGMA user_version=1")
        self.db.commit()

    def run_once(self, execution_id, task_id, action):
        if not self.reserve(execution_id, task_id) or not self.transition(
            execution_id, "executing"
        ):
            return None
        try:
            result = action()
            encoded = json.dumps(result, sort_keys=True)
            if len(encoded) > 65536:
                raise ValueError("oversized_result")
            with self.lock, self.db:
                self.db.execute(
                    "UPDATE executions SET result=?,state='result_pending' WHERE id=? AND state='executing'",
                    (encoded, execution_id),
                )
                self.db.execute(
                    "INSERT INTO events(execution_id,state) VALUES(?,'result_pending')",
                    (execution_id,),
                )
            return result
        except Exception:
            self.transition(execution_id, "uncertain")
            return None

    def store_result(self, execution_id, result):
        encoded = json.dumps(result, sort_keys=True)
        if len(encoded) > 65536:
            raise ValueError("oversized_result")
        with self.lock, self.db:
            c = self.db.execute(
                "UPDATE executions SET result=?,state='result_pending' WHERE id=? AND state='executing'",
                (encoded, execution_id),
            )
            if c.rowcount:
                self.db.execute(
                    "INSERT INTO events(execution_id,state) VALUES(?,'result_pending')",
                    (execution_id,),
                )

    def result(self, execution_id):
        with self.lock:
            row = self.db.execute(
                "SELECT result FROM executions WHERE id=?", (execution_id,)
            ).fetchone()
            return json.loads(row[0]) if row and row[0] else None

    def reserve(self, execution_id, task_id):
        with self.lock, self.db:
            c = self.db.execute(
                "INSERT OR IGNORE INTO executions(id,task_id,state) VALUES(?,?,'claimed')",
                (execution_id, task_id),
            )
            if c.rowcount:
                self.db.execute(
                    "INSERT INTO events(execution_id,state) VALUES(?,'claimed')", (execution_id,)
                )
                return True
            return False

    def transition(self, execution_id, state):
        with self.lock, self.db:
            row = self.db.execute(
                "SELECT state FROM executions WHERE id=?", (execution_id,)
            ).fetchone()
            if not row or state not in EDGES[row[0]]:
                return False
            self.db.execute("UPDATE executions SET state=? WHERE id=?", (state, execution_id))
            self.db.execute(
                "INSERT INTO events(execution_id,state) VALUES(?,?)", (execution_id, state)
            )
            return True

    def recover(self):
        with self.lock, self.db:
            rows = self.db.execute("SELECT id FROM executions WHERE state='executing'").fetchall()
            for (execution_id,) in rows:
                self.db.execute(
                    "UPDATE executions SET state='uncertain' WHERE id=?", (execution_id,)
                )
                self.db.execute(
                    "INSERT INTO events(execution_id,state) VALUES(?,'uncertain')", (execution_id,)
                )
        return self.pending()

    def pending(self):
        with self.lock:
            return [
                dict(execution_id=r[0], task_id=r[1], state=r[2])
                for r in self.db.execute(
                    "SELECT id,task_id,state FROM executions WHERE state!='completed' ORDER BY rowid LIMIT 100"
                )
            ]

    def check(self):
        with self.lock:
            return self.db.execute("PRAGMA integrity_check").fetchone()[0] == "ok"

    def close(self):
        self.db.close()
