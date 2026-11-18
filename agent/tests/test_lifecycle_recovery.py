import hashlib
import json
import sqlite3
import tempfile
import unittest
from contextlib import closing
from pathlib import Path
from unittest.mock import Mock, patch
from uuid import uuid4

from agent_center.lifecycle.runtime import RuntimeAdapter
from agent_center.state import InstanceLock, StateError, atomic_write


class SnapshotFailureRecovery(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.state = Path(temporary.name)
        self.remote = Mock()
        self.remote.engine.state = self.state
        self.running = True
        self.remote.engine.service.active.side_effect = lambda: self.running
        self.remote.engine.service.stop.side_effect = lambda: setattr(self, 'running', False)
        self.remote.engine.service.start.side_effect = lambda: setattr(self, 'running', True)
        self.adapter = RuntimeAdapter(self.remote, self.state, {})

    def test_failed_instance_lock_restores_previously_running_service(self):
        held = InstanceLock(self.state / 'instance.lock')
        try:
            with self.assertRaises(StateError):
                self.adapter.snapshot(str(uuid4()), {})
        finally:
            held.close()
        self.assertTrue(self.running)
        self.remote._backup.assert_not_called()

    def test_failed_backup_releases_instance_lock_before_restarting(self):
        self.remote._backup.side_effect = OSError('fixture backup failure')

        def restart():
            lock = InstanceLock(self.state / 'instance.lock')
            lock.close()
            self.running = True

        self.remote.engine.service.start.side_effect = restart
        with self.assertRaises(OSError):
            self.adapter.snapshot(str(uuid4()), {})
        self.assertTrue(self.running)

    def test_failed_backup_does_not_start_previously_inactive_service(self):
        self.running = False
        self.remote._backup.side_effect = OSError('fixture backup failure')
        with self.assertRaises(OSError):
            self.adapter.snapshot(str(uuid4()), {})
        self.assertFalse(self.running)
        self.remote.engine.service.start.assert_not_called()

    def test_stop_error_after_service_stops_restores_previous_service(self):
        def failed_stop():
            self.running = False
            raise OSError('fixture stop failure')

        self.remote.engine.service.stop.side_effect = failed_stop
        with self.assertRaises(OSError):
            self.adapter.snapshot(str(uuid4()), {})
        self.assertTrue(self.running)


class RestoredHostVerification(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.operation = str(uuid4())
        self.remote = Mock()
        self.remote.control = self.root / 'control'
        self.remote.engine.state = self.root / 'state'
        self.remote.engine.base = self.root / 'base'
        self.remote.config_dir = self.root / 'config'
        self.remote.postcheck.return_value = True
        self.backup = self.remote.control / 'backups' / self.operation
        manifest = {}
        for name, data, live in [
            ('state/identity.json', b'identity', self.remote.engine.state / 'identity.json'),
            ('runtime/agent.pyz', b'runtime', self.remote.engine.base / 'runtime' / 'agent.pyz'),
            ('config/agent.json', b'config', self.remote.config_dir / 'agent.json'),
        ]:
            saved = self.backup / name
            saved.parent.mkdir(parents=True, exist_ok=True)
            saved.write_bytes(data)
            live.parent.mkdir(parents=True, exist_ok=True)
            live.write_bytes(data)
            manifest[name] = hashlib.sha256(data).hexdigest()
        for directory in (self.backup / 'state', self.remote.engine.state):
            with closing(sqlite3.connect(directory / 'journal.sqlite3')) as db:
                db.executescript('CREATE TABLE executions(id TEXT); CREATE TABLE events(id TEXT); INSERT INTO events VALUES ("old");')
                db.commit()
        manifest['state/journal.sqlite3'] = hashlib.sha256((self.backup / 'state/journal.sqlite3').read_bytes()).hexdigest()
        atomic_write(self.backup / 'manifest.json', manifest)
        self.adapter = RuntimeAdapter(self.remote, self.root, {'from_version': '0.6.2'})
        self.check = patch('agent_center.lifecycle.runtime.self_check')
        self.check.start()
        self.addCleanup(self.check.stop)

    def test_current_journal_can_advance_without_being_replaced(self):
        journal = self.remote.engine.state / 'journal.sqlite3'
        with closing(sqlite3.connect(journal)) as db:
            db.execute('INSERT INTO events VALUES ("new")')
            db.commit()
        before = journal.read_bytes()
        self.assertTrue(self.adapter.restored_health(self.operation))
        self.assertEqual(before, journal.read_bytes())
        self.remote.postcheck.assert_called_once()
        self.remote.engine.service.start.assert_not_called()

    def test_identity_config_runtime_and_backup_tampering_are_refused(self):
        targets = [self.remote.engine.state / 'identity.json', self.remote.config_dir / 'agent.json',
                   self.remote.engine.base / 'runtime' / 'agent.pyz', self.backup / 'runtime' / 'agent.pyz']
        for target in targets:
            with self.subTest(target=target):
                original = target.read_bytes()
                target.write_bytes(b'changed')
                self.assertFalse(self.adapter.restored_health(self.operation))
                target.write_bytes(original)
        self.remote.postcheck.assert_not_called()

    def test_journal_event_loss_is_refused(self):
        with closing(sqlite3.connect(self.remote.engine.state / 'journal.sqlite3')) as db:
            db.execute('DELETE FROM events')
            db.commit()
        self.assertFalse(self.adapter.restored_health(self.operation))
        self.remote.postcheck.assert_not_called()

    def test_unhealthy_component_does_not_clear_hold(self):
        with patch('agent_center.lifecycle.components.Components.rollback_health', return_value=False):
            self.assertFalse(self.adapter.restored_health(self.operation))
        self.remote.postcheck.assert_not_called()

    def test_saved_wal_is_read_without_modifying_backup_or_trusting_shm(self):
        saved = self.backup / 'state/journal.sqlite3'
        with closing(sqlite3.connect(saved)) as db:
            db.execute('PRAGMA journal_mode=WAL')
            db.execute('INSERT INTO events VALUES ("wal-event")')
            db.commit()
            manifest = json.loads((self.backup / 'manifest.json').read_text())
            for path in saved.parent.glob('journal.sqlite3*'):
                manifest['state/' + path.name] = hashlib.sha256(path.read_bytes()).hexdigest()
            atomic_write(self.backup / 'manifest.json', manifest)
            # WAL events must be included in the preservation comparison.
            self.assertFalse(self.adapter.restored_health(self.operation))
            with closing(sqlite3.connect(self.remote.engine.state / 'journal.sqlite3')) as live:
                live.execute('INSERT INTO events VALUES ("new")')
                live.commit()
            before = {p.name: p.read_bytes() for p in saved.parent.glob('journal.sqlite3*')}
            self.assertTrue(self.adapter.restored_health(self.operation))
            self.assertTrue(self.adapter.restored_health(self.operation))
            self.assertEqual(before, {p.name: p.read_bytes() for p in saved.parent.glob('journal.sqlite3*')})

    def test_unauthenticated_wal_and_tampered_database_are_refused(self):
        saved = self.backup / 'state/journal.sqlite3'
        saved.with_name(saved.name + '-wal').write_bytes(b'untracked-wal')
        self.assertFalse(self.adapter.restored_health(self.operation))
        saved.with_name(saved.name + '-wal').unlink()
        saved.write_bytes(b'tampered')
        self.assertFalse(self.adapter.restored_health(self.operation))

    def test_changed_shared_memory_index_is_ignored_and_not_modified(self):
        shm = self.backup / 'state/journal.sqlite3-shm'
        shm.write_bytes(b'original-derived-index')
        manifest = json.loads((self.backup / 'manifest.json').read_text())
        manifest['state/journal.sqlite3-shm'] = hashlib.sha256(shm.read_bytes()).hexdigest()
        atomic_write(self.backup / 'manifest.json', manifest)
        shm.write_bytes(b'changed-derived-index')
        self.assertTrue(self.adapter.restored_health(self.operation))
        self.assertEqual(shm.read_bytes(), b'changed-derived-index')
