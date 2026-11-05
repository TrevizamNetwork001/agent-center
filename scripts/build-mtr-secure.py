"""Offline Debian 13 kit: deterministic sources, manifest and outer archive digest."""

import gzip
import hashlib
import importlib.util
import io
import json
import tarfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("agent_builder", ROOT / "agent/packaging/build.py")
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)
output = ROOT / "artifacts/mtr-secure-debian13"
output.mkdir(parents=True, exist_ok=True)
for name, mode in [("helper.pyz", "mtr-helper"), ("agent.pyz", "agent")]:
    builder.build(output / name, mode)
for source in (ROOT / "agent/mtr-debian13").iterdir():
    if source.is_file() and not source.name.endswith(".pyc"):
        (output / source.name).write_bytes(source.read_bytes())
(output / "INSTALL.md").write_bytes((ROOT / "docs/MTR_SECURE_EXECUTOR.md").read_bytes())
names = sorted(
    p.name
    for p in output.iterdir()
    if p.is_file()
    and p.name not in ("manifest.json", "build.json")
    and not p.name.endswith((".tar.gz", ".sha256"))
)
manifest = {name: hashlib.sha256((output / name).read_bytes()).hexdigest() for name in names}
(output / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
buffer = io.BytesIO()
with gzip.GzipFile(fileobj=buffer, mode="wb", mtime=0) as gz:
    with tarfile.open(fileobj=gz, mode="w") as archive:
        for name in sorted([*names, "manifest.json"]):
            data = (output / name).read_bytes()
            info = tarfile.TarInfo("mtr-secure-debian13/" + name)
            info.size = len(data)
            info.mode = 0o644
            info.uid = info.gid = 0
            info.mtime = 0
            archive.addfile(info, io.BytesIO(data))
archive = output / "mtr-secure-debian13.tar.gz"
archive.write_bytes(buffer.getvalue())
sha = hashlib.sha256(archive.read_bytes()).hexdigest()
(output / "mtr-secure-debian13.tar.gz.sha256").write_text(sha + "  mtr-secure-debian13.tar.gz\n")
(output / "build.json").write_text(
    json.dumps({"archive_sha256": sha, "files": manifest}, indent=2) + "\n"
)
print(
    json.dumps(
        {
            "artifact": str(archive),
            "sha256": sha,
            "runtime_sha256": manifest["agent.pyz"],
            "helper_sha256": manifest["helper.pyz"],
        }
    )
)
