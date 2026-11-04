"""Create a dedicated encryption key, never print or overwrite it."""
import base64
import os
import secrets
from pathlib import Path
root=Path(__file__).resolve().parents[1]
directory=root/'secrets'
directory.mkdir(mode=0o700,exist_ok=True)
directory.chmod(0o700)
path=directory/'llm-master.key'
if path.exists():
    if path.is_symlink() or path.stat().st_mode & 0o077:
        raise SystemExit('Unsafe key permissions')
else:
    descriptor=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
    with os.fdopen(descriptor,'wb') as stream:
        stream.write(base64.b64encode(secrets.token_bytes(32))+b'\n')
        stream.flush()
        os.fsync(stream.fileno())
    os.chown(path,33,33)
    directory_fd=os.open(directory,os.O_DIRECTORY)
    try:os.fsync(directory_fd)
    finally:os.close(directory_fd)
print('Dedicated LLM key prepared; value not emitted.')
