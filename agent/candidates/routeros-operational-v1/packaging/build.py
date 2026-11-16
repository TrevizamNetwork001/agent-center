"""Deterministic zipapps built exclusively from authoritative Agent source."""

import hashlib
import json
import sys
import zipfile
from pathlib import Path


def build(output, mode="agent", wheels=None):
    modules = {"updater-bootstrap": "agent_center.lifecycle.updater_bootstrap", "mtr-helper": "agent_center.mtr_helper", "agent": "agent_center.main", "updater": "agent_center.update.cli", "remote-updater": "agent_center.update.remote"}
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
    if wheels is not None:
        if mode != "agent":
            raise ValueError("ssh_bundle_agent_only")
        import runpy

        catalog = runpy.run_path(str(root / "agent_center/lifecycle/ssh_catalog.py"))["WHEELS"]
        directory = Path(wheels)
        if {p.name for p in directory.iterdir()} != set(catalog):
            raise ValueError("ssh_inventory_invalid")
        for name, digest in catalog.items():
            path = directory / name
            if path.is_symlink() or hashlib.sha256(path.read_bytes()).hexdigest() != digest:
                raise ValueError("ssh_wheel_hash_mismatch")
            sources["agent_center/_ssh/" + name] = path.read_bytes()
        sources["__main__.py"] = (
            "import sys\n"
            "sys.dont_write_bytecode = True\n"
            "from agent_center.lifecycle.ssh_runtime import activate\n"
            "activate(sys.argv[0])\n"
            "import paramiko, cryptography, bcrypt, nacl, cffi, pycparser\n"
            "assert (paramiko.__version__, cryptography.__version__, bcrypt.__version__, "
            "nacl.__version__, cffi.__version__, pycparser.__version__) == "
            "('3.5.1', '46.0.3', '5.0.0', '1.6.0', '2.0.0', '2.23')\n"
            "from agent_center.main import main\nraise SystemExit(main())\n"
        ).encode()
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
    print(build(sys.argv[1], sys.argv[2] if len(sys.argv) > 2 else "agent",
                sys.argv[3] if len(sys.argv) > 3 else None)[0])
