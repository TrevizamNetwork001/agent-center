"""Build a stdlib-only zipapp with correct exit status propagation."""

import hashlib
import pathlib
import shutil
import sys
import tempfile
import zipfile

root = pathlib.Path(__file__).resolve().parents[1]
out = pathlib.Path(sys.argv[1]).resolve()
module = (
    "agent_center.update.cli"
    if len(sys.argv) > 2 and sys.argv[2] == "updater"
    else "agent_center.update.remote"
    if len(sys.argv) > 2 and sys.argv[2] == "remote-updater"
    else "agent_center.main"
)
with tempfile.TemporaryDirectory() as tmp:
    stage = pathlib.Path(tmp)
    shutil.copytree(
        root / "src" / "agent_center",
        stage / "agent_center",
        ignore=shutil.ignore_patterns("__pycache__", "*.pyc"),
    )
    (stage / "__main__.py").write_text(
        "from " + module + " import main\nraise SystemExit(main())\n"
    )
    with out.open("wb") as stream:
        stream.write(b"#!/usr/bin/env python3\n")
        with zipfile.ZipFile(stream, "w", compression=zipfile.ZIP_DEFLATED) as archive:
            for source in sorted(stage.rglob("*.py")):
                entry = zipfile.ZipInfo(
                    source.relative_to(stage).as_posix(), date_time=(2026, 1, 1, 0, 0, 0)
                )
                entry.compress_type = zipfile.ZIP_DEFLATED
                entry.external_attr = 0o100644 << 16
                archive.writestr(entry, source.read_bytes())
print(hashlib.sha256(out.read_bytes()).hexdigest())
