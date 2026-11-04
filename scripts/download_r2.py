#!/usr/bin/env python3
"""Retrieve exclusively from R2; never fall back to a local backup. No decryption key."""
import argparse
import re
from pathlib import Path

from backup_r2 import ROOT, BackupError, atomic_json, load_config, new_client, verify_remote


def download(config, credentials, key, output):
    if (not key.startswith(config['prefix'] + '/') or '..' in Path(key).parts
            or not key.endswith('.tar.gz.age')):
        raise BackupError('object_outside_authorized_prefix')
    if output.exists() or output.with_suffix(output.suffix + '.proof.json').exists():
        raise BackupError('refusing_existing_recovery_output')
    client = new_client(config, credentials)
    try:
        metadata = client.head_object(Bucket=config['bucket'], Key=key)
        digest = metadata.get('Metadata', {}).get('sha256', '')
        size = metadata.get('ContentLength', 0)
        if not re.fullmatch('[a-f0-9]{64}', digest) or not 0 < size <= 5 * 1024**3:
            raise BackupError('remote_integrity_metadata_missing')
        proof = verify_remote(client, config['bucket'], key, digest, size, output)
    finally:
        client.close()
    proof.update(source='r2_download_only', independent_restore_verified=False)
    atomic_json(output.with_suffix(output.suffix + '.proof.json'), proof)
    return proof


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--config', type=Path, default=ROOT / 'secrets/r2-backup.json')
    parser.add_argument('--key', required=True, help='Authorized object key, never a signed URL')
    parser.add_argument('--output', required=True, type=Path, help='New private output file')
    args = parser.parse_args()
    try:
        config, credentials = load_config(args.config)
        download(config, credentials, args.key, args.output)
    except Exception:
        print('R2 download FAILED; no local fallback and no credentials logged.')
        return 1
    print('R2 ciphertext download and SHA-256: PASS. Decrypt/restore only in the authorized recovery environment.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
