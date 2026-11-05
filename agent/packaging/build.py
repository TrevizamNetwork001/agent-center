"""Deterministic zipapps built exclusively from authoritative Agent source."""

import hashlib
import json
import sys
import zipfile
from pathlib import Path


def build(output, mode="agent"):
    modules = {"agent": "agent_center.main", "updater": "agent_center.update.cli", "remote-updater": "agent_center.update.remote"}
    if mode not in modules:
        raise ValueError("invalid_build_mode")
    root = Path(__file__).resolve().parents[1] / "src"
    sources = {}
    for file in sorted((root / "agent_center").rglob("*.py")):
        if file.is_symlink():
            raise ValueError("source_symlink_forbidden")
        data = file.read_bytes()
        compile(data, str(file.relative_to(root)), "exec")
        sources[file.relative_to(root).as_posix()] = data
    source_hash = hashlib.sha256(json.dumps({name: hashlib.sha256(data).hexdigest() for name, data in sources.items()}, sort_keys=True, separators=(",", ":")).encode()).hexdigest()
    sources["__main__.py"] = ("from " + modules[mode] + " import main\nraise SystemExit(main())\n").encode()
    output = Path(output)
    with output.open("wb") as stream:
        stream.write(b"#!/usr/bin/env python3\n")
        with zipfile.ZipFile(stream, "w", compression=zipfile.ZIP_DEFLATED) as archive:
            for name, data in sorted(sources.items()):
                info = zipfile.ZipInfo(name, (2026, 1, 1, 0, 0, 0))
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                archive.writestr(info, data)
    output.chmod(0o644)
    return hashlib.sha256(output.read_bytes()).hexdigest(), source_hash


if __name__ == "__main__":
    print(build(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else "agent")[0])
