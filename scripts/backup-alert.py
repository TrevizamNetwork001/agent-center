#!/usr/bin/env python3
"""Sanitized administrative signal; optional approved HTTPS receiver, no chat integrations."""
import argparse
import http.client
import json
import ssl
from datetime import datetime, timezone
from urllib.parse import urlsplit

from backup_r2 import ROOT, private_file


def read_status(path):
    data = {}
    try:
        data = json.loads(path.read_text())
        if not isinstance(data, dict):
            return {}, False, None
        generated = datetime.strptime(data['generation'], '%Y%m%dT%H%M%SZ').replace(tzinfo=timezone.utc)
        age = (datetime.now(timezone.utc) - generated).total_seconds()
        fresh = 0 <= age <= 26 * 3600
        return data, fresh, max(0, int(age))
    except (OSError, ValueError, KeyError, TypeError):
        return data, False, None


def signal(root):
    local, local_fresh, local_age = read_status(root / 'backups/local-status.json')
    external, external_fresh, external_age = read_status(root / 'backups/external-status.json')
    stages = external.get('stages', {})
    if not isinstance(stages, dict):
        stages = {}
    payload = dict(project='agent-center', observed_at=datetime.now(timezone.utc).isoformat(),
                   backup_executed=local.get('state') == 'VERDE',
                   local_integrity_approved=local.get('state') == 'VERDE' and local_fresh,
                   local_backup_age_seconds=local_age,
                   upload_completed=stages.get('upload_completed') is True,
                   remote_integrity_approved=stages.get('integrity_verified') is True and external.get('state') == 'VERDE' and external_fresh,
                   retention_verified=stages.get('retention_verified') is True,
                   independent_restore_verified=stages.get('independent_restore_verified') is True,
                   external_key_custody_verified=stages.get('external_key_custody_verified') is True,
                   external_backup_age_seconds=external_age,
                   transfer_failed=external.get('state') == 'NO-GO',
                   backup_overdue=not local_fresh or (bool(stages.get('upload_completed')) and not external_fresh),
                   external_not_configured=external.get('reason') == 'EXTERNAL BACKUP NOT CONFIGURED')
    payload['state'] = 'NO-GO' if payload['transfer_failed'] or payload['backup_overdue'] or not payload['local_integrity_approved'] else ('VERDE' if all(payload[key] for key in ['remote_integrity_approved', 'retention_verified', 'independent_restore_verified', 'external_key_custody_verified']) else 'ATENÇÃO')
    return payload


def notify(config, payload):
    url = urlsplit(config['url'])
    if (url.scheme != 'https' or not url.hostname or url.username or url.password
            or url.query or url.fragment):
        raise ValueError('authorized_https_receiver_required')
    token = private_file(config['token_file']).read_text().strip()
    if not token or '\r' in token or '\n' in token:
        raise ValueError('invalid_alert_token')
    connection = http.client.HTTPSConnection(url.hostname, url.port or 443,
                                           timeout=15, context=ssl.create_default_context())
    try:
        connection.request('POST', url.path or '/', body=json.dumps(payload).encode(),
                           headers={'Authorization': 'Bearer ' + token, 'Content-Type': 'application/json'})
        response = connection.getresponse()
        if not 200 <= response.status < 300:
            raise ValueError('alert_delivery_failed')
        # No redirect following, destination logging, response-body logging or credential output.
    finally:
        connection.close()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--only-problems', action='store_true')
    args = parser.parse_args()
    payload = signal(ROOT)
    if args.only_problems and payload['state'] == 'VERDE':
        return 0
    print(json.dumps(payload, sort_keys=True))
    config_path = ROOT / 'secrets/backup-alert.json'
    if not config_path.exists():
        print('ALERT_RECEIVER_NOT_CONFIGURED: local journal signal only.')
        return 0
    try:
        notify(json.loads(private_file(config_path).read_text()), payload)
    except Exception:
        print('Administrative backup alert delivery FAILED; sensitive details withheld.')
        return 1
    print('Administrative backup alert delivered to configured receiver.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
