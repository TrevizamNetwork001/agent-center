"""Build the complete verified kit from authoritative Agent source."""

import ast
import gzip
import hashlib
import importlib.util
import io
import json
import re
import shutil
import tarfile
import tomllib
from pathlib import Path

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("agent_build", root / "agent/packaging/build.py")
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)
version = ast.literal_eval(ast.parse((root / "agent/src/agent_center/__init__.py").read_text()).body[-1].value)
assert re.fullmatch(r"[0-9]+[.][0-9]+[.][0-9]+", version) and version == tomllib.loads((root / "agent/pyproject.toml").read_text())["project"]["version"]
kit = root / "artifacts/agentkit" / version
kit.mkdir(parents=True, exist_ok=True)
hashes = {}
for filename, mode in (("agent.pyz", "agent"), ("updater.pyz", "updater"), ("remote-updater.pyz", "remote-updater")):
    sha, source_hash = builder.build(kit / filename, mode)
    hashes[filename] = sha
release = dict(version=version, sha256=hashes["agent.pyz"], artifact="agent.pyz",
               compatibility="Debian 12/13; Linux CPython 3.13; state/journal schema 1",
               date="2026-11-05", status="available", device_operations_frozen=True,
               git_ref="source-sha256:" + source_hash, source_sha256=source_hash,
               changelog="Wake the scheduling loop after isolated execution finishes, preserving journal recovery, one execution lane, failed-poll backoff and a two-second minimum dispatch cadence. No local policy or privilege changes.",
               tested_capabilities=["agent_info", "dns_lookup:v1", "tcp_connect:v1", "http_probe:v1"])
(kit / "catalog.json").write_text(json.dumps([release], indent=2) + "\n")
legacy = root / "artifacts/agentkit/0.5.3"
for name in ("install-agent.py", "update-agent.sh", "rollback-agent.sh", "complete-enrollment.sh", "logrotate.conf"):
    shutil.copyfile(legacy / name, kit / name)
for name in ("agent-center-agent.service", "agent-center-updater.service", "agent-center-updater.timer"):
    shutil.copyfile(root / "agent/systemd" / name, kit / name)
names = ["agent.pyz", "updater.pyz", "remote-updater.pyz", "catalog.json", "update-agent.sh", "rollback-agent.sh", "complete-enrollment.sh", "logrotate.conf", "agent-center-agent.service", "agent-center-updater.service", "agent-center-updater.timer"]
manifest = {name: hashlib.sha256((kit / name).read_bytes()).hexdigest() for name in sorted(names)}
(kit / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
compressed = io.BytesIO()
with gzip.GzipFile(fileobj=compressed, mode="wb", mtime=0) as gz:
    with tarfile.open(fileobj=gz, mode="w") as archive:
        for name in sorted([*names, "manifest.json", "install-agent.py"]):
            file = kit / name
            data = file.read_bytes()
            info = tarfile.TarInfo("agentkit/" + file.name)
            info.size = len(data)
            info.mode = 0o644
            info.uid = info.gid = 0
            info.mtime = 0
            archive.addfile(info, io.BytesIO(data))
bundle = kit / ("agent-center-agent-" + version + ".tar.gz")
bundle.write_bytes(compressed.getvalue())
summary = dict(version=version, runtime_sha256=hashes["agent.pyz"],
               updater_sha256=hashes["updater.pyz"], companion_sha256=hashes["remote-updater.pyz"],
               manifest_sha256=hashlib.sha256((kit / "manifest.json").read_bytes()).hexdigest(),
               bundle_sha256=hashlib.sha256(bundle.read_bytes()).hexdigest(), source_sha256=source_hash)
(kit / "build.json").write_text(json.dumps(summary, indent=2) + "\n")
print(json.dumps(summary))
