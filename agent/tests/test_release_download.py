import hashlib
import io
import json
import tempfile
import unittest
import zipfile
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

from agent_center.api import Client, NoRedirect
from agent_center.update.engine import Engine, UpdateError
from agent_center.update.releases import MAX_ARTIFACT_BYTES, download
from agent_center.update.remote import Remote


class Downloads(unittest.TestCase):
    def setUp(self):
        self.sha = 'a' * 64

    def response(self, data=b'fixture', length='7', status=200):
        response = io.BytesIO(data)
        response.status = status
        response.headers = {'Content-Length': length} if length is not None else {}
        return response

    def test_fixed_origin_digest_path_and_no_bearer_header(self):
        opener = SimpleNamespace(open=lambda request, timeout: self.response())
        with patch('agent_center.update.releases.Client', return_value=SimpleNamespace(base='https://central.example', opener=opener)) as client, patch.object(opener, 'open', return_value=self.response()) as call:
            self.assertEqual(download('https://central.example', self.sha), b'fixture')
            request = call.call_args.args[0]
            self.assertEqual(request.full_url, 'https://central.example/downloads/agent/releases/' + self.sha + '.pyz')
            self.assertFalse(request.has_header('Authorization'))
            self.assertEqual(call.call_args.kwargs['timeout'], 15)
            client.assert_called_once_with('https://central.example', retries=0)
        actual = Client('https://central.example')
        self.assertTrue(any(isinstance(handler, NoRedirect) for handler in actual.opener.handlers))

    def test_invalid_origins_and_digest_never_make_network_requests(self):
        for origin, sha in [('http://central.example', self.sha), ('https://user:pass@central.example', self.sha), ('https://central.example/path', self.sha), ('https://central.example?q=1', self.sha), ('https://central.example', '../escape')]:
            with self.subTest(origin=origin), patch('agent_center.update.releases.Client') as client:
                with self.assertRaisesRegex(UpdateError, 'invalid_release'):
                    download(origin, sha)
                client.assert_not_called()

    def test_redirect_http_error_tls_error_and_oversized_body_are_safe_failures(self):
        cases = [self.response(status=302), self.response(length=str(MAX_ARTIFACT_BYTES + 1)), self.response(data=b'x' * (MAX_ARTIFACT_BYTES + 1), length=None), self.response(data=b'', length=None)]
        for response in cases:
            with patch('agent_center.update.releases.Client', return_value=SimpleNamespace(base='https://central.example', opener=SimpleNamespace(open=lambda *a, **k: response))):
                with self.assertRaisesRegex(UpdateError, '^release_download_failed$'):
                    download('https://central.example', self.sha)
        with patch('agent_center.update.releases.Client', return_value=SimpleNamespace(base='https://central.example', opener=SimpleNamespace(open=lambda *a, **k: (_ for _ in ()).throw(OSError('PRIVATE TOKEN'))))):
            with self.assertRaisesRegex(UpdateError, '^release_download_failed$'):
                download('https://central.example', self.sha)


class Staging(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.base = Path(self.tmp.name) / 'agent'
        (self.base / 'releases').mkdir(parents=True)
        (self.base / 'runtime').mkdir()
        (self.base / 'runtime/agent.pyz').write_bytes(b'OLD')
        (self.base / 'releases/catalog.json').write_text('[]')
        stream = io.BytesIO()
        with zipfile.ZipFile(stream, 'w') as archive:
            archive.writestr('__main__.py', 'pass')
            archive.writestr('agent_center/main.py', 'pass')
        self.data = stream.getvalue()
        self.sha = hashlib.sha256(self.data).hexdigest()
        self.catalog = []
        self.remote = Remote(Engine(self.base, self.base / 'state'), SimpleNamespace(base='https://central.example'), self.catalog, self.base / 'control')
        self.operation = {'kind': 'update', 'phase': 'precheck', 'release': {'sha256': self.sha, 'version': '0.5.9', 'device_operations_frozen': True, 'url': 'https://attacker.example/payload', 'artifact': '../../escape'}}
        trust = patch('agent_center.update.remote.trusted', side_effect=lambda path: path)
        trust.start()
        self.addCleanup(trust.stop)
        self.original_check = patch('agent_center.update.remote.self_check', return_value={})
        self.original_check.start()
        self.addCleanup(self.original_check.stop)

    def test_missing_release_is_staged_atomically_and_survives_new_process(self):
        with patch('agent_center.update.remote.download', return_value=self.data) as fetch:
            candidate, sha = self.remote._candidate(self.operation)
            self.assertEqual(candidate.read_bytes(), self.data)
            self.assertEqual(candidate.stat().st_mode & 0o777, 0o644)
            self.assertEqual(sha, self.sha)
            fetch.assert_called_once_with('https://central.example', self.sha)
            self.remote._candidate(self.operation)
            self.assertEqual(fetch.call_count, 1)
        saved = json.loads((self.base / 'releases/catalog.json').read_text())
        self.assertEqual(saved, self.catalog)
        next_run = Remote(self.remote.engine, self.remote.client, saved, self.remote.control)
        with patch('agent_center.update.remote.download', side_effect=AssertionError('must use staged candidate')):
            self.assertEqual(next_run._candidate(self.operation)[1], self.sha)
        self.assertEqual((self.base / 'runtime/agent.pyz').read_bytes(), b'OLD')
        self.assertEqual(list((self.base / 'releases').glob('.download-*')), [])

    def test_bad_hash_and_unsafe_archive_do_not_change_catalog_or_runtime(self):
        for data in (b'tampered', self.data):
            operation = json.loads(json.dumps(self.operation))
            if data == self.data:
                operation['release']['sha256'] = 'b' * 64
            with patch('agent_center.update.remote.download', return_value=data):
                with self.assertRaisesRegex(UpdateError, 'sha256_mismatch'):
                    self.remote._candidate(operation)
            self.assertEqual((self.base / 'releases/catalog.json').read_text(), '[]')
            self.assertEqual((self.base / 'runtime/agent.pyz').read_bytes(), b'OLD')
            self.assertEqual(list((self.base / 'releases').glob('.download-*')), [])

    def test_failed_self_check_does_not_authorize_catalog_entry(self):
        with patch('agent_center.update.remote.download', return_value=self.data), patch('agent_center.update.remote.self_check', side_effect=UpdateError('incompatible')):
            with self.assertRaisesRegex(UpdateError, 'incompatible'):
                self.remote._candidate(self.operation)
        self.assertEqual((self.base / 'releases/catalog.json').read_text(), '[]')
        self.assertEqual(self.catalog, [])
        self.assertEqual((self.base / 'runtime/agent.pyz').read_bytes(), b'OLD')

    def test_same_companion_downloads_a_later_release_without_another_setup(self):
        with patch('agent_center.update.remote.download', return_value=self.data):
            self.remote._candidate(self.operation)
        stream = io.BytesIO()
        with zipfile.ZipFile(stream, 'w') as archive:
            archive.writestr('__main__.py', 'pass # next')
            archive.writestr('agent_center/main.py', 'pass # next')
        data = stream.getvalue()
        sha = hashlib.sha256(data).hexdigest()
        operation = self.operation | {'release': self.operation['release'] | {'version': '0.5.10', 'sha256': sha}}
        with patch('agent_center.update.remote.download', return_value=data) as fetch:
            candidate, actual = self.remote._candidate(operation)
            self.assertEqual(actual, sha)
            self.assertEqual(candidate.read_bytes(), data)
            fetch.assert_called_once_with('https://central.example', sha)
        self.assertEqual(len(self.catalog), 2)
        self.assertEqual(self.catalog[0]['version'], '0.5.10')
        self.assertEqual((self.base / 'runtime/agent.pyz').read_bytes(), b'OLD')

    def test_missing_rollback_or_apply_candidate_is_not_downloaded(self):
        for change in ({'kind': 'rollback'}, {'phase': 'apply'}):
            with patch('agent_center.update.remote.download') as fetch:
                with self.assertRaisesRegex(UpdateError, 'release_not_staged'):
                    self.remote._candidate(self.operation | change)
                fetch.assert_not_called()

    def test_invalid_release_and_disabled_freeze_never_download(self):
        for change in ({'version': '../escape'}, {'sha256': '../escape'}, {'device_operations_frozen': False}):
            operation = self.operation | {'release': self.operation['release'] | change}
            with patch('agent_center.update.remote.download') as fetch:
                with self.assertRaises(UpdateError):
                    self.remote._candidate(operation)
                fetch.assert_not_called()

    def test_symlink_candidate_is_not_followed_or_replaced(self):
        candidate = self.base / 'releases' / (self.sha + '.pyz')
        candidate.symlink_to(self.base / 'runtime/agent.pyz')
        with patch('agent_center.update.remote.download') as fetch:
            with self.assertRaisesRegex(UpdateError, 'unsafe_artifact'):
                self.remote._candidate(self.operation)
            fetch.assert_not_called()
        self.assertTrue(candidate.is_symlink())
        self.assertEqual((self.base / 'runtime/agent.pyz').read_bytes(), b'OLD')


if __name__ == '__main__':
    unittest.main()
