import io
import re
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock

sys.path.insert(0, str(Path(__file__).resolve().parents[4] / 'agent/candidates/routeros-operational-v1/src'))
from agent_center.update.engine import UpdateError
from agent_center.update.releases import DownloadPending, download_operation


class ResumableDownloadTests(unittest.TestCase):
    def test_slices_resume_without_repeating_download_or_authorizing_installation(self):
        payload = b'bounded-fixture' * 160000
        operation = 'e63aed58-e98b-4dfa-8b7a-9fef01982cb6'
        ranges = []

        def open_response(request, timeout):
            self.assertEqual(15, timeout)
            start, end = map(int, re.fullmatch(r'bytes=(\d+)-(\d+)', request.get_header('Range')).groups())
            self.assertLessEqual(end - start + 1, 524288)
            ranges.append((start, end))
            response = io.BytesIO(payload[start:end + 1])
            response.status = 206
            response.headers = {'Content-Range': f'bytes {start}-{end}/{len(payload)}'}
            return response

        client = SimpleNamespace(base='https://central.fixture.test', token='fixture', opener=SimpleNamespace(open=open_response))
        with tempfile.TemporaryDirectory() as directory:
            cache = Path(directory)
            for size in [1048576, 2097152]:
                with self.assertRaises(DownloadPending):
                    download_operation(client, operation, cache=cache, size=len(payload))
                partial = cache / ('.partial-' + operation)
                self.assertEqual(size, partial.stat().st_size)
                self.assertEqual(0o600, partial.stat().st_mode & 0o777)
            self.assertEqual(payload, download_operation(client, operation, cache=cache, size=len(payload)))
        self.assertEqual(0, ranges[0][0])
        self.assertTrue(all(ranges[i][0] == ranges[i - 1][1] + 1 for i in range(1, len(ranges))))

    def test_bad_range_and_private_cache_symlink_are_rejected(self):
        operation = 'e63aed58-e98b-4dfa-8b7a-9fef01982cb6'
        response = io.BytesIO(b'bad')
        response.status = 206
        response.headers = {'Content-Range': 'bytes 1-3/3'}
        client = SimpleNamespace(base='https://central.fixture.test', token='fixture', opener=Mock())
        client.opener.open.return_value = response
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            with self.assertRaises(UpdateError):
                download_operation(client, operation, cache=root, size=3)
            partial = root / ('.partial-' + operation)
            partial.unlink()
            outside = root / 'preserve'
            outside.write_bytes(b'unchanged')
            partial.symlink_to(outside)
            with self.assertRaises(OSError):
                download_operation(client, operation, cache=root, size=3)
            self.assertEqual(b'unchanged', outside.read_bytes())
