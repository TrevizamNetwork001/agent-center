"""Build a declarative MTR release using the existing runtime and signature format.

Signing occurs only on the release administrator's host. No installer/script is
received from Central; fixed files and operations come from the local catalog.
"""

import argparse
import base64
import gzip
import hashlib
import importlib.util
import io
import json
import subprocess
import sys
import tarfile
from pathlib import Path


def main():
    root = Path(__file__).resolve().parents[1]
    sys.path.insert(0, str(root / "agent/src"))
    from agent_center import __version__
    from agent_center.lifecycle.components import helper_from_runtime, specification
    from agent_center.lifecycle.mtr_catalog import FILES

    parser = argparse.ArgumentParser()
    parser.add_argument("--component-version", default=__version__)
    parser.add_argument("--sequence", type=int, required=True)
    parser.add_argument("--private-key", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    if not 1 <= args.sequence <= 2147483647:
        parser.error("invalid sequence")
    args.output.mkdir(mode=0o700, parents=True, exist_ok=False)
    spec = importlib.util.spec_from_file_location(
        "agent_builder", root / "agent/packaging/build.py"
    )
    builder = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(builder)
    sha, source_hash = builder.build(args.output / "agent.pyz")
    artifact = (args.output / "agent.pyz").read_bytes()
    helper = helper_from_runtime(artifact)
    manifest = {
        "schema_version": 1,
        "sequence": args.sequence,
        "version": __version__,
        "platform": "debian13",
        "sha256": sha,
        "size": len(artifact),
        "components": [
            {
                "id": "mtr",
                "version": args.component_version,
                "profile": "network-raw-helper",
                "dependencies": ["python3.13", "mtr0.95"],
                "helper_sha256": hashlib.sha256(helper).hexdigest(),
            }
        ],
    }

    specification(manifest["components"], __version__)
    (args.output / "manifest.json").write_text(
        json.dumps(manifest, sort_keys=True, separators=(",", ":"))
    )
    signature = subprocess.run(
        [
            "/usr/bin/openssl",
            "pkeyutl",
            "-sign",
            "-rawin",
            "-inkey",
            str(args.private_key),
            "-in",
            str(args.output / "manifest.json"),
        ],
        check=True,
        capture_output=True,
        timeout=10,
    ).stdout
    (args.output / "signature.base64").write_text(
        base64.b64encode(signature).decode() + "\n"
    )
    for filename, mode in [
        ("updater.pyz", "updater"),
        ("remote-updater.pyz", "remote-updater"),
    ]:
        builder.build(args.output / filename, mode)
    (args.output / "helper.pyz").write_bytes(helper)
    for source, destination in [
        (
            "agent/lifecycle-debian13/policy.example.json",
            "lifecycle-policy.example.json",
        ),
        (
            "agent/systemd/agent-center-updater.service.d/30-mtr-components.conf",
            "30-mtr-components.conf",
        ),
        ("agent/mtr-debian13/sysusers.conf", "sysusers.conf"),
    ]:
        (args.output / destination).write_bytes((root / source).read_bytes())
    for name, data in FILES.items():
        (args.output / name).write_bytes(data)
    public = subprocess.run(
        ["/usr/bin/openssl", "pkey", "-in", str(args.private_key), "-pubout"],
        check=True,
        capture_output=True,
        timeout=10,
    ).stdout
    (args.output / "release-public.pem").write_bytes(public)
    from agent_center.lifecycle.releases import verify

    verify(
        (args.output / "manifest.json").read_bytes(),
        base64.b64encode(signature).decode(),
        public,
        artifact,
        minimum_sequence=args.sequence - 1,
        platform="debian13",
    )
    (args.output / "SHA256.json").write_text(
        json.dumps(
            {
                p.name: hashlib.sha256(p.read_bytes()).hexdigest()
                for p in sorted(args.output.iterdir())
            },
            indent=2,
        )
        + "\n"
    )
    (args.output / "SHA256SUMS").write_text(
        "".join(
            hashlib.sha256(p.read_bytes()).hexdigest() + "  " + p.name + "\n"
            for p in sorted(args.output.iterdir())
            if p.is_file()
        )
    )
    bundle = args.output.parent / (args.output.name + ".tar.gz")
    with (
        bundle.open("xb") as out,
        gzip.GzipFile(fileobj=out, mode="wb", mtime=0) as compressed,
        tarfile.open(fileobj=compressed, mode="w") as archive,
    ):
        for path in sorted(args.output.iterdir()):
            data = path.read_bytes()
            info = tarfile.TarInfo("lifecycle-bootstrap/" + path.name)
            info.size = len(data)
            info.mode = 0o644
            info.uid = info.gid = info.mtime = 0
            archive.addfile(info, io.BytesIO(data))
    print(
        json.dumps(
            {
                "bundle": str(bundle),
                "bundle_sha256": hashlib.sha256(bundle.read_bytes()).hexdigest(),
                "systemd_homologated": False,
            }
        )
    )
    print(
        json.dumps(
            {
                "version": __version__,
                "runtime_sha256": sha,
                "source_sha256": source_hash,
                "output": str(args.output),
                "systemd_homologated": False,
            }
        )
    )


if __name__ == "__main__":
    main()
