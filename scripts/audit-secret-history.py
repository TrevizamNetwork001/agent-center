#!/usr/bin/env python3
"""Check actual current key material across Git history without emitting values or paths."""
import os
import re
import subprocess
from pathlib import Path

root = Path(__file__).resolve().parents[1]
needles = []
for filename in ['.env', '.env.runtime', '.env.migrator']:
    path = root / filename
    if not path.exists():
        continue
    for line in path.read_bytes().splitlines():
        key, separator, value = line.partition(b'=')
        if separator and len(value) >= 20 and any(word in key for word in [b'PASSWORD', b'APP_KEY', b'TOKEN', b'SECRET']):
            needles.append(value.strip(b'"\''))
key = root / 'secrets/llm-master.key'
if key.exists():
    needles.append(key.read_bytes().strip())
# Examine every historical tree, including files removed from HEAD and alternate paths.
forbidden_paths = set()
commits = subprocess.check_output(['git', 'rev-list', '--all'], cwd=root).splitlines()
for commit in commits:
    names = subprocess.check_output(['git', 'ls-tree', '-r', '-z', '--name-only', commit], cwd=root).split(b'\0')
    for name in names:
        if not name:
            continue
        path = Path(os.fsdecode(name))
        if path.as_posix() == 'backups/.gitkeep' or path.name == '.env.example':
            continue
        if (path.parts[0] in ('backups', 'secrets', 'tools')
                or path.name == '.env' or path.name.startswith('.env.')
                or path.name in ('r2-credentials.json', 'r2-backup.json')
                or path.suffix in ('.dump', '.bundle', '.cms', '.age', '.agekey', '.pem', '.key')):
            forbidden_paths.add(name)
objects = subprocess.check_output(['git', 'rev-list', '--objects', '--all'], cwd=root).splitlines()
objects = list(dict.fromkeys(row.split(b' ', 1)[0] for row in objects))
process = subprocess.Popen(['git', 'cat-file', '--batch'], cwd=root,
                           stdin=subprocess.PIPE, stdout=subprocess.PIPE)
count = hits = 0
for oid in objects:
    process.stdin.write(oid + b'\n')
    process.stdin.flush()
    header = process.stdout.readline().split()
    content = process.stdout.read(int(header[2]))
    process.stdout.read(1)
    if header[1] != b'blob':
        continue
    count += 1
    if any(needle and needle in content for needle in needles) or re.search(rb'-----BEGIN (?:[A-Z]+ )?PRIVATE KEY-----', content) or re.search(rb'AGE-SECRET-KEY-[A-Z0-9]{50,}', content) or content.startswith((b'PGDMP', b'# v2 git bundle', b'# v3 git bundle', b'-- PostgreSQL database dump')):
        hits += 1
process.stdin.close()
process.wait()
print(f'Git complete-history scan: commits={len(commits)}, blobs={count}, secret_or_backup_matches={hits}, forbidden_historical_paths={len(forbidden_paths)}; values withheld.')
raise SystemExit(1 if hits or forbidden_paths else 0)
