#!/usr/bin/env python3
"""Check actual current key material across Git history without emitting values or paths."""
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
    if any(needle and needle in content for needle in needles) or re.search(rb'-----BEGIN (?:[A-Z]+ )?PRIVATE KEY-----', content):
        hits += 1
process.stdin.close()
process.wait()
print(f'Git history actual-secret/private-key scan: blobs={count}, matches={hits}; values withheld.')
raise SystemExit(1 if hits else 0)
