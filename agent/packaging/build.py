"""Build a stdlib-only zipapp with correct exit status propagation."""

import hashlib
import pathlib
import shutil
import sys
import tempfile
import zipapp

root = pathlib.Path(__file__).resolve().parents[1]
out = pathlib.Path(sys.argv[1]).resolve()
module = (
    "agent_center.update.cli"
    if len(sys.argv) > 2 and sys.argv[2] == "updater"
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
    zipapp.create_archive(stage, target=out, interpreter="/usr/bin/env python3", compressed=True)
print(hashlib.sha256(out.read_bytes()).hexdigest())
