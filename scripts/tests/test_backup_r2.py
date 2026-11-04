"""Synthetic laboratory only; no Cloudflare calls or production recovery identity."""
import hashlib
import importlib.util
import io
import json
import subprocess
import sys
import tarfile
import tempfile
import unittest
from datetime import UTC, datetime
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).parents[1]))
import backup_r2 as r2
import download_r2

spec = importlib.util.spec_from_file_location('backup_alert', Path(__file__).parents[1] / 'backup-alert.py')
alert = importlib.util.module_from_spec(spec)
spec.loader.exec_module(alert)


class FakeS3:
    def __init__(self):
        self.objects = {}
        self.corrupt = False
        self.wrong_length = False
        self.closed = False
        self.requests = []

    def put_object(self, **kwargs):
        self.requests.append(('PUT', kwargs['Bucket'], kwargs['Key']))
        if kwargs['IfNoneMatch'] != '*' or kwargs['Key'] in self.objects:
            raise ValueError('overwrite_refused')
        self.objects[kwargs['Key']] = (kwargs['Body'].read(), kwargs['Metadata'])

    def head_object(self, **kwargs):
        self.requests.append(('HEAD', kwargs['Bucket'], kwargs['Key']))
        data, meta = self.objects[kwargs['Key']]
        return {'ContentLength': len(data) + int(self.wrong_length), 'Metadata': meta}

    def get_object(self, **kwargs):
        self.requests.append(('GET', kwargs['Bucket'], kwargs['Key']))
        data, _ = self.objects[kwargs['Key']]
        body = data[:-1] + bytes([data[-1] ^ 1]) if self.corrupt else data
        return {'ContentLength': len(data), 'Body': io.BytesIO(body)}

    def close(self):
        self.closed = True


class R2Tests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.config = {'account_id': '0' * 32,
                       'endpoint': 'https://' + '0' * 32 + '.r2.cloudflarestorage.com',
                       'bucket': 'synthetic-lab-only', 'prefix': 'agent-center', 'region': 'auto',
                       'upload_transport': 'sdk'}
        self.client = FakeS3()

    def tearDown(self):
        self.temporary.cleanup()

    def test_r2_endpoint_account_bucket_and_prefix_are_restricted(self):
        r2.validate_config(self.config)
        for changes in [{'endpoint': 'http://localhost'}, {'region': 'us-east-1'},
                        {'endpoint': 'https://attacker.invalid'}, {'prefix': '../other-project'},
                        {'endpoint': self.config['endpoint'] + '?token=bad'}, {'bucket': 'invalid/bucket'}]:
            with self.assertRaises(r2.BackupError):
                r2.validate_config(self.config | changes)

    def test_private_files_reject_public_permissions_and_symlinks(self):
        path = self.root / 'credentials'
        path.write_text('{}')
        path.chmod(0o644)
        with self.assertRaises(r2.BackupError):
            r2.private_file(path)
        path.chmod(0o600)
        self.assertEqual(path, r2.private_file(path))
        link = self.root / 'link'
        link.symlink_to(path)
        with self.assertRaises(r2.BackupError):
            r2.private_file(link)

    def test_daily_weekly_monthly_prefixes_and_unique_objects(self):
        # 2026-11-01 was both Sunday and first day of month.
        keys = r2.object_keys(self.config, '20261101T021500Z', 'a' * 32)
        self.assertEqual(len(keys), 3)
        self.assertTrue(all(key.endswith('.tar.bz2.age') for key in keys))
        self.assertTrue(all('agent-center-2026-11-01_02-15-00Z-' in key for key in keys))
        self.assertTrue(any('/monthly/' in key for key in keys))
        self.assertTrue(any('/weekly/' in key for key in keys))
        self.assertEqual(len(r2.object_keys(self.config, '20261104T021500Z')), 1)
        self.assertNotEqual(r2.object_keys(self.config, '20261104T021500Z'),
                            r2.object_keys(self.config, '20261104T021500Z'))

    def test_conditional_upload_head_and_complete_get(self):
        archive = self.root / 'ciphertext.age'
        archive.write_bytes(b'synthetic-ciphertext')
        digest = hashlib.sha256(archive.read_bytes()).hexdigest()
        proofs = r2.upload(self.client, self.config, archive, '20261104T021500Z', digest, archive.stat().st_size)
        self.assertTrue(proofs[0]['integrity'])
        self.assertEqual([request[0] for request in self.client.requests], ['PUT', 'HEAD', 'GET'])
        self.assertTrue(all(request[1] == self.config['bucket'] for request in self.client.requests))

    def test_native_http_success_without_remote_object_does_not_pass(self):
        archive = self.root / 'ciphertext.age'
        archive.write_bytes(b'synthetic-ciphertext')
        digest = hashlib.sha256(archive.read_bytes()).hexdigest()
        transport = {'client': 'curl', 'exit_code': 0, 'http_status': 200}
        with (patch('r2_native_transport.put', return_value=transport),
              self.assertRaises(KeyError)):
            r2.upload(self.client, self.config | {'upload_transport': 'curl'}, archive,
                      '20261104T021500Z', digest, archive.stat().st_size)

    def test_native_lost_reply_passes_only_with_matching_complete_remote_readback(self):
        archive = self.root / 'ciphertext.age'
        data = b'synthetic-ciphertext'
        archive.write_bytes(data)
        digest = hashlib.sha256(data).hexdigest()
        key = 'agent-center/daily/synthetic.tar.gz.age'
        self.client.objects[key] = (data, {'sha256': digest})
        transport = {'client': 'curl', 'exit_code': 35, 'http_status': 0}
        with (patch('r2_native_transport.put', return_value=transport),
              patch.object(r2, 'object_keys', return_value=[key])):
            proof = r2.upload(self.client, self.config | {'upload_transport': 'curl'}, archive,
                              '20261104T021500Z', digest, len(data))[0]
        self.assertTrue(proof['integrity'])
        self.assertTrue(proof['download'])
        self.assertEqual(proof['transport']['exit_code'], 35)
        self.assertEqual([request[0] for request in self.client.requests], ['HEAD', 'GET'])

    def test_corrupt_get_wrong_head_and_existing_file_never_pass(self):
        data = b'ciphertext'
        digest = hashlib.sha256(data).hexdigest()
        key = 'agent-center/daily/object.tar.gz.age'
        self.client.objects[key] = (data, {'sha256': digest})
        output = self.root / 'download.age'
        self.client.corrupt = True
        with self.assertRaises(r2.BackupError):
            r2.verify_remote(self.client, self.config['bucket'], key, digest, len(data), output)
        self.assertFalse(output.exists())
        self.client.corrupt = False
        self.client.wrong_length = True
        with self.assertRaises(r2.BackupError):
            r2.verify_remote(self.client, self.config['bucket'], key, digest, len(data))
        self.client.wrong_length = False
        output.write_bytes(b'preserve-existing-file')
        with self.assertRaises(FileExistsError):
            r2.verify_remote(self.client, self.config['bucket'], key, digest, len(data), output)
        self.assertEqual(output.read_bytes(), b'preserve-existing-file')

    def fixture(self):
        stamp = datetime.now(UTC).strftime('%Y%m%dT%H%M%SZ')
        members = []
        for category in ['database', 'config', 'secrets', 'storage']:
            directory = self.root / 'backups' / category
            directory.mkdir(parents=True)
            path = directory / (stamp + '.fixture')
            path.write_bytes(b'synthetic fixture: no production data')
            members.append(hashlib.sha256(path.read_bytes()).hexdigest() + '  ' + str(path.relative_to(self.root / 'backups')))
        (self.root / 'backups/config' / (stamp + '.sha256')).write_text('\n'.join(members) + '\n')
        r2.atomic_json(self.root / 'backups/local-status.json',
                       {'state': 'VERDE', 'generation': stamp, 'project': 'agent-center', 'storage_included': True})
        return stamp

    def test_manifest_corruption_and_lab_scope_fail_before_upload(self):
        stamp = self.fixture()
        generation, members = r2.generation_members(self.root)
        self.assertEqual(generation, stamp)
        self.assertEqual(len(members), 5)
        (self.root / 'backups/database' / (stamp + '.fixture')).write_bytes(b'corrupted')
        with self.assertRaises(r2.BackupError):
            r2.generation_members(self.root)
        (self.root / 'backups/database' / (stamp + '.fixture')).write_bytes(b'synthetic fixture: no production data')
        r2.atomic_json(self.root / 'backups/local-status.json',
                       {'state': 'VERDE', 'generation': stamp, 'project': 'lab-only', 'storage_included': True})
        with self.assertRaises(r2.BackupError):
            r2.generation_members(self.root)

    def test_real_age_authenticated_encryption_decrypt_and_tamper_detection(self):
        self.fixture()
        (self.root / 'tools').symlink_to(r2.ROOT / 'tools', target_is_directory=True)
        identity = self.root / 'SYNTHETIC-TEST-IDENTITY'
        # Test identity encrypts only synthetic fixtures and is erased with the temporary directory.
        age = r2.ROOT / 'tools/usr/bin/age'
        keygen = r2.ROOT / 'tools/usr/bin/age-keygen'
        subprocess.run([str(keygen), '-o', str(identity)], check=True,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        recipient = subprocess.check_output([str(keygen), '-y', str(identity)])
        recipient_file = self.root / 'public-recipient'
        recipient_file.write_bytes(recipient)
        config = self.config | {'recipient_file': str(recipient_file)}
        with patch.object(r2, 'new_client', return_value=self.client):
            receipt = r2.run(self.root, config, {})
        self.assertTrue(self.client.closed)
        self.assertTrue(receipt['stages']['integrity_verified'])
        self.assertFalse(receipt['stages']['independent_restore_verified'])
        self.assertFalse(receipt['stages']['external_key_custody_verified'])
        ciphertext, _meta = next(iter(self.client.objects.values()))
        encrypted = self.root / 'remote.age'
        encrypted.write_bytes(ciphertext)
        plaintext = self.root / 'decrypted.tar.bz2'
        subprocess.run([str(age), '--decrypt', '-i', str(identity), '-o', str(plaintext), str(encrypted)],
                       check=True, stderr=subprocess.DEVNULL)
        self.assertEqual(plaintext.read_bytes()[:3], b'BZh')
        with tarfile.open(plaintext) as archive:
            self.assertEqual(set(archive.getnames()), set(r2.generation_members(self.root)[1]))
            self.assertTrue(any(name.startswith('secrets/') for name in archive.getnames()))
        encrypted.write_bytes(ciphertext[:-1] + bytes([ciphertext[-1] ^ 1]))
        tampered_output = self.root / 'unauthenticated.partial'
        result = subprocess.run([str(age), '--decrypt', '-i', str(identity), '-o', str(tampered_output), str(encrypted)],
                                stderr=subprocess.DEVNULL, check=False)
        self.assertNotEqual(result.returncode, 0)
        # No corrupted plaintext is ever extracted or promoted to a restored archive.

    def test_download_uses_remote_and_cannot_read_other_prefixes(self):
        data = b'remote-ciphertext'
        digest = hashlib.sha256(data).hexdigest()
        key = 'agent-center/daily/object.tar.gz.age'
        self.client.objects[key] = (data, {'sha256': digest})
        destination = self.root / 'new-download.age'
        with patch.object(download_r2, 'new_client', return_value=self.client):
            proof = download_r2.download(self.config, {}, key, destination)
        self.assertEqual(destination.read_bytes(), data)
        self.assertFalse(proof['independent_restore_verified'])
        self.assertEqual(proof['source'], 'r2_download_only')
        with self.assertRaises(r2.BackupError):
            download_r2.download(self.config, {}, 'other-bucket/object.age', self.root / 'other')

    def test_download_accepts_the_dated_bzip2_archive_and_keeps_legacy_compatibility(self):
        data = b'synthetic remote age ciphertext'
        digest = hashlib.sha256(data).hexdigest()
        key = r2.object_keys(self.config, '20261104T021500Z')[0]
        self.client.objects[key] = (data, {'sha256': digest})
        output = self.root / 'dated-backup.tar.bz2.age'
        with patch.object(download_r2, 'new_client', return_value=self.client):
            proof = download_r2.download(self.config, {}, key, output)
        self.assertEqual(output.read_bytes(), data)
        self.assertTrue(proof['integrity'])
        self.assertFalse(proof['independent_restore_verified'])

    def test_alert_preserves_missing_configuration_and_failure_before_generation(self):
        self.fixture()
        r2.atomic_json(self.root / 'backups/external-status.json',
                       {'state': 'ATENÇÃO', 'reason': 'EXTERNAL BACKUP NOT CONFIGURED'})
        payload = alert.signal(self.root)
        self.assertTrue(payload['external_not_configured'])
        self.assertFalse(payload['upload_completed'])
        self.assertFalse(payload['independent_restore_verified'])
        r2.atomic_json(self.root / 'backups/external-status.json',
                       {'state': 'NO-GO', 'reason': 'r2_backup_failed'})
        payload = alert.signal(self.root)
        self.assertTrue(payload['transfer_failed'])
        self.assertEqual(payload['state'], 'NO-GO')

    def test_alert_marks_overdue_external_backup_critical(self):
        self.fixture()
        r2.atomic_json(self.root / 'backups/external-status.json',
                       {'state': 'VERDE', 'generation': '20200101T000000Z',
                        'stages': {'upload_completed': True, 'integrity_verified': True}})
        payload = alert.signal(self.root)
        self.assertTrue(payload['backup_overdue'])
        self.assertFalse(payload['remote_integrity_approved'])
        self.assertEqual(payload['state'], 'NO-GO')

    def test_sdk_serializes_conditional_put_without_network(self):
        from botocore.stub import Stubber
        client = r2.new_client(self.config, {'access_key_id': 'synthetic-access-only', 'secret_access_key': 'synthetic-secret-only'})
        with Stubber(client) as stub:
            args = {'Bucket': self.config['bucket'], 'Key': 'agent-center/new.age',
                        'Body': b'ciphertext', 'IfNoneMatch': '*', 'Metadata': {'sha256': 'a' * 64}}
            stub.add_response('put_object', {}, args)
            client.put_object(**args)
        client.close()

    def test_sdk_exception_cannot_expose_credentials_in_status_or_output(self):
        config_file = self.root / 'config.json'
        r2.atomic_json(config_file, {})
        captured = io.StringIO()
        with patch.object(r2, 'ROOT', self.root), patch.object(sys, 'argv', ['backup_r2.py', '--config', str(config_file)]), patch.object(r2, 'load_config', side_effect=RuntimeError('DO-NOT-LOG-THIS-CREDENTIAL')), patch('sys.stdout', captured):
            self.assertEqual(r2.main(), 1)
        self.assertNotIn('DO-NOT-LOG', captured.getvalue())
        self.assertNotIn('DO-NOT-LOG', (self.root / 'backups/external-status.json').read_text())
        self.assertEqual(json.loads((self.root / 'backups/external-status.json').read_text())['state'], 'NO-GO')


if __name__ == '__main__':
    unittest.main()
