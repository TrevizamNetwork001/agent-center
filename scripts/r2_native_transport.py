"""Bounded curl transport for a pre-encrypted archive; signed URL stays private."""
import json
import os
import re
import subprocess
import tempfile
from pathlib import Path
from urllib.parse import urlsplit


class NativeTransportError(Exception):
    """Closed local codes only; never include provider responses or signed URLs."""


def put(client, config, archive, key, digest, size):
    archive = Path(archive)
    if (archive.is_symlink() or not archive.is_file() or archive.stat().st_size != size
            or not 0 < size <= 5 * 1024**3 or not re.fullmatch(r'[a-f0-9]{64}', digest)
            or not key.startswith(config['prefix'] + '/') or '..' in Path(key).parts):
        raise NativeTransportError('invalid_encrypted_archive')
    executable = Path('/usr/bin/curl')
    if not executable.is_file():
        raise NativeTransportError('native_https_client_missing')
    params = {'Bucket': config['bucket'], 'Key': key, 'ContentLength': size,
              'ContentType': 'application/octet-stream', 'IfNoneMatch': '*',
              'Metadata': {'sha256': digest, 'encryption': 'age-v1'}}
    url = client.generate_presigned_url('put_object', Params=params, ExpiresIn=1200)
    endpoint, signed = urlsplit(config['endpoint']), urlsplit(url)
    if (signed.scheme != 'https' or signed.hostname != endpoint.hostname
            or signed.port not in (None, 443) or signed.username or signed.password
            or signed.fragment or not signed.query or any(x in url for x in '\r\n')):
        raise NativeTransportError('invalid_signed_upload_destination')
    with tempfile.TemporaryDirectory(prefix='r2-native-private-') as directory:
        os.chmod(directory, 0o700)
        config_file = Path(directory) / 'upload.cfg'
        response_file = Path(directory) / 'response'
        for path, data in [(config_file, 'url = ' + json.dumps(url) + '\n'), (response_file, '')]:
            fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, 'w') as stream:
                stream.write(data)
        # Native TLS verification stays enabled. No redirects, insecure flag,
        # private key, credential or signed URL is supplied in process arguments.
        # Conditional PUT makes transport retries safe from accidental overwrite.
        result = subprocess.run([
            str(executable), '--disable', '--config', str(config_file), '--proto', '=https',
            '--http2', '--ipv4', '--retry', '5', '--retry-all-errors',
            '--retry-delay', '2', '--retry-max-time', '600', '--silent',
            '--header', 'Expect:', '--connect-timeout', '10', '--max-time', '300',
            '--max-redirs', '0', '--request', 'PUT', '--upload-file', str(archive),
            '--header', 'Content-Type: application/octet-stream',
            '--header', 'If-None-Match: *', '--header', 'x-amz-meta-sha256: ' + digest,
            '--header', 'x-amz-meta-encryption: age-v1', '--output', str(response_file),
            '--write-out', '%{http_code} %{http_version}',
        ], capture_output=True, check=False, timeout=950)
        # A lost response may follow a committed write. The caller MUST still
        # perform HEAD and complete GET/SHA-256; this result alone proves nothing.
        match = re.fullmatch(rb'(\d{3}) (0|1\.0|1\.1|2|3)', result.stdout)
        return {'client': 'curl', 'exit_code': result.returncode,
                'http_status': int(match[1]) if match else None,
                'http_version': match[2].decode() if match else None}
