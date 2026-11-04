"""Synthetic credentials only; no network or actual signed URL is used."""
import hashlib
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).parents[1]))
import r2_native_transport as native


class NativeTransportTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.archive = Path(self.temporary.name) / 'synthetic.age'
        self.archive.write_bytes(b'synthetic encrypted archive')
        self.config = {'endpoint': 'https://' + '0' * 32 + '.r2.cloudflarestorage.com',
                       'bucket': 'synthetic-lab-only', 'prefix': 'agent-center'}
        self.key = 'agent-center/daily/synthetic.tar.gz.age'
        self.digest = hashlib.sha256(self.archive.read_bytes()).hexdigest()
        self.url = self.config['endpoint'] + '/synthetic?signature=synthetic-sensitive-value'
        self.client = Mock()
        self.client.generate_presigned_url.return_value = self.url

    def tearDown(self):
        self.temporary.cleanup()

    def call(self):
        return native.put(self.client, self.config, self.archive, self.key,
                          self.digest, self.archive.stat().st_size)

    def test_signed_url_stays_in_private_file_and_cleanup_is_guaranteed(self):
        seen = []

        def process(args, **kwargs):
            seen.append(Path(args[args.index('--config') + 1]))
            private = seen[0]
            self.assertEqual(os.stat(private).st_mode & 0o777, 0o600)
            self.assertEqual(os.stat(private.parent).st_mode & 0o777, 0o700)
            self.assertIn(self.url, private.read_text())
            self.assertNotIn(self.url, ' '.join(args))
            self.assertNotIn('synthetic-sensitive-value', ' '.join(args))
            self.assertNotIn('--insecure', args)
            self.assertNotIn('-k', args)
            self.assertNotIn('--location', args)
            self.assertEqual(args[args.index('--proto') + 1], '=https')
            self.assertEqual(args[args.index('--max-redirs') + 1], '0')
            self.assertIn('If-None-Match: *', args)
            self.assertIn('--retry-all-errors', args)
            self.assertIn('--ipv4', args)
            self.assertIn('Expect:', args)
            self.assertTrue(kwargs['capture_output'])
            self.assertEqual(kwargs['timeout'], 950)
            return subprocess.CompletedProcess(args, 0, b'200 1.1', b'synthetic-provider-detail')

        with patch.object(native.subprocess, 'run', side_effect=process):
            result = self.call()
        self.assertEqual(result, {'client': 'curl', 'exit_code': 0,
                                  'http_status': 200, 'http_version': '1.1'})
        self.assertFalse(seen[0].exists())
        self.assertNotIn('synthetic-provider-detail', str(result))
        signed = self.client.generate_presigned_url.call_args
        self.assertEqual(signed.kwargs['ExpiresIn'], 1200)
        self.assertEqual(signed.kwargs['Params']['IfNoneMatch'], '*')
        self.assertEqual(signed.kwargs['Params']['Metadata']['sha256'], self.digest)

    def test_redirect_wrong_host_and_http_are_rejected_before_transport(self):
        for url in ['http://invalid.example/x?signature=synthetic',
                    'https://invalid.example/x?signature=synthetic',
                    self.config['endpoint'] + '/x',
                    self.config['endpoint'] + '/x?signature=synthetic#fragment']:
            self.client.generate_presigned_url.return_value = url
            with patch.object(native.subprocess, 'run') as process:
                with self.assertRaises(native.NativeTransportError):
                    self.call()
                process.assert_not_called()

    def test_transport_failure_is_returned_without_provider_stderr_or_success_claim(self):
        result = subprocess.CompletedProcess([], 35, b'000 0', b'synthetic-sensitive-error')
        with patch.object(native.subprocess, 'run', return_value=result):
            proof = self.call()
        self.assertEqual(proof['exit_code'], 35)
        self.assertNotIn('integrity', proof)
        self.assertNotIn('synthetic-sensitive', str(proof))

    def test_timeout_also_removes_private_signed_configuration(self):
        seen = []

        def timeout(args, **_kwargs):
            seen.append(Path(args[args.index('--config') + 1]))
            raise subprocess.TimeoutExpired('synthetic-client', 950)

        with (patch.object(native.subprocess, 'run', side_effect=timeout),
              self.assertRaises(subprocess.TimeoutExpired)):
            self.call()
        self.assertFalse(seen[0].exists())


if __name__ == '__main__':
    unittest.main()
