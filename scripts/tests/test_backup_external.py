"""Local HTTPS laboratory; these tests never constitute external backup evidence."""
import hashlib
import http.server
import importlib.util
import json
import ssl
import subprocess
import tempfile
import threading
import unittest
from datetime import datetime, timezone
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('backup_external', Path(__file__).parents[1] / 'backup-external.py')
backup = importlib.util.module_from_spec(spec)
spec.loader.exec_module(backup)


class Handler(http.server.BaseHTTPRequestHandler):
    objects = {}
    corrupt = False

    def log_message(self, *_args):
        pass

    def do_PUT(self):
        if self.headers.get('Authorization') != 'Bearer laboratory-only':
            self.send_error(403)
            return
        if self.path in self.objects:
            self.send_error(412)
            return
        self.objects[self.path] = self.rfile.read(int(self.headers['Content-Length']))
        self.send_response(201)
        self.end_headers()

    def do_GET(self):
        value = b'corrupted' if self.corrupt else self.objects[self.path]
        self.send_response(200)
        self.send_header('Content-Length', str(len(value)))
        self.end_headers()
        self.wfile.write(value)


class BackupTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temporary = tempfile.TemporaryDirectory()
        cls.root = Path(cls.temporary.name)
        cls.cert = cls.root / 'recipient.crt'
        cls.key = cls.root / 'recipient.key'
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
                        '-keyout', str(cls.key), '-out', str(cls.cert), '-days', '1',
                        '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost'],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        cls.server = http.server.HTTPServer(('127.0.0.1', 0), Handler)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(cls.cert, cls.key)
        cls.server.socket = context.wrap_socket(cls.server.socket, server_side=True)
        cls.thread = threading.Thread(target=cls.server.serve_forever, daemon=True)
        cls.thread.start()
        cls.url = f'https://localhost:{cls.server.server_port}/backup'

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.server.server_close()
        cls.thread.join()
        cls.temporary.cleanup()

    def setUp(self):
        Handler.objects = {}
        Handler.corrupt = False

    def test_https_no_userinfo_query_or_http(self):
        for url in ['http://localhost/x', 'https://user:password@localhost/x',
                    'https://localhost/x?token=sensitive', 'file:///tmp/x']:
            with self.assertRaises(ValueError):
                backup.destination(url)

    def test_readback_detects_corruption_and_wrong_ca(self):
        path = self.root / 'payload'
        path.write_bytes(b'ciphertext-lab')
        digest, size = backup.transfer(self.url, 'laboratory-only', path, str(self.cert))
        self.assertEqual(digest, hashlib.sha256(path.read_bytes()).hexdigest())
        self.assertEqual(size, len(path.read_bytes()))
        with self.assertRaises(ValueError):
            backup.transfer(self.url, 'laboratory-only', path, str(self.cert))
        Handler.corrupt = True
        with self.assertRaises(ValueError):
            backup.transfer(self.url + '-corrupt', 'laboratory-only', path, str(self.cert))
        with self.assertRaises(ssl.SSLCertVerificationError):
            backup.transfer(self.url + '-untrusted', 'laboratory-only', path)

    def test_complete_encrypt_transfer_readback_and_offline_decrypt(self):
        fixture = self.root / 'fixture'
        (fixture / 'backups').mkdir(parents=True, exist_ok=True)
        stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')
        entries = []
        for category in ['database', 'config', 'secrets', 'storage']:
            directory = fixture / 'backups' / category
            directory.mkdir(exist_ok=True)
            path = directory / (stamp + '.fixture')
            path.write_bytes(b'laboratory-dummy-data')
            entries.append(hashlib.sha256(path.read_bytes()).hexdigest() + '  ' + str(path.relative_to(fixture / 'backups')))
        (fixture / 'backups/config' / (stamp + '.sha256')).write_text('\n'.join(entries) + '\n')
        token = fixture / 'token'
        token.write_text('laboratory-only')
        token.chmod(0o600)
        config = dict(base_url=self.url, token_file=str(token),
                      recipient_certificate=str(self.cert), tls_ca_file=str(self.cert))
        with patch.object(backup, 'ROOT', fixture):
            backup.export(config)
        encrypted = self.root / 'export.cms'
        encrypted.write_bytes(next(iter(Handler.objects.values())))
        plaintext = self.root / 'restored.tar.gz'
        subprocess.run(['openssl', 'cms', '-decrypt', '-binary', '-inform', 'DER',
                        '-in', str(encrypted), '-recip', str(self.cert), '-inkey', str(self.key),
                        '-out', str(plaintext)], check=True, stderr=subprocess.DEVNULL)
        listing = subprocess.check_output(['tar', '-tzf', str(plaintext)], text=True)
        self.assertIn('storage/' + stamp + '.fixture', listing)
        status = json.loads((fixture / 'backups/external-status.json').read_text())
        self.assertFalse(status['restore_from_external_verified'])
        self.assertFalse(status['retention_verified'])
        # Wrong private key is rejected, rather than silently producing a recoverable archive.
        with self.assertRaises(subprocess.CalledProcessError):
            subprocess.run(['openssl', 'cms', '-decrypt', '-binary', '-inform', 'DER',
                            '-in', str(encrypted), '-recip', str(self.cert), '-inkey', '/dev/null'],
                           check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    def test_private_config_permissions(self):
        path = self.root / 'unsafe-config'
        path.write_text('{}')
        path.chmod(0o644)
        with self.assertRaises(ValueError):
            backup.private_file(path)
        path.chmod(0o600)
        self.assertEqual(backup.private_file(path), path)


if __name__ == '__main__':
    unittest.main()
