import importlib.util
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location("installer", Path("packaging/install-agent.py"))
INSTALLER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(INSTALLER)


class InstallerTests(unittest.TestCase):
    def setUp(self):
        self.previous_umask = os.umask(0o022)

    def tearDown(self):
        os.umask(self.previous_umask)

    def test_corrupt_kit_refused_before_any_directory_is_created(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            kit = root / "kit"
            kit.mkdir()
            (kit / "manifest.json").write_text("{}")
            install = root / "install"
            install.mkdir()
            argv = [
                "installer",
                "--kit",
                str(kit),
                "--central-url",
                "https://central.example.invalid",
                "--manifest-sha256",
                "0" * 64,
                "--lab-root",
                str(install),
            ]
            with patch("sys.argv", argv):
                self.assertEqual(INSTALLER.main(), 2)
            self.assertEqual(list(install.iterdir()), [])

    def test_layout_is_readable_by_service_and_existing_install_is_preserved(self):
        import hashlib
        import json
        import stat

        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            kit = root / "kit"
            kit.mkdir()
            target = root / "install"
            target.mkdir()
            names = [
                "agent.pyz",
                "updater.pyz",
                "remote-updater.pyz",
                "agent-center-agent.service",
                "agent-center-updater.service",
                "agent-center-updater.timer",
                "logrotate.conf",
                "update-agent.sh",
                "rollback-agent.sh",
                "complete-enrollment.sh",
            ]
            for name in names:
                (kit / name).write_bytes(b"fixture")
            sha = hashlib.sha256(b"fixture").hexdigest()
            (kit / "catalog.json").write_text(
                json.dumps([dict(sha256=sha, device_operations_frozen=True)])
            )
            manifest = {f.name: hashlib.sha256(f.read_bytes()).hexdigest() for f in kit.iterdir()}
            (kit / "manifest.json").write_text(json.dumps(manifest))
            argv = [
                "installer",
                "--kit",
                str(kit),
                "--central-url",
                "https://central.example.invalid",
                "--manifest-sha256",
                hashlib.sha256((kit / "manifest.json").read_bytes()).hexdigest(),
                "--lab-root",
                str(target),
            ]
            with patch("sys.argv", argv):
                self.assertEqual(INSTALLER.main(), 0)
            base = target / "opt/agent-center-agent"
            for path in [target / "opt", base, base / "runtime", base / "releases"]:
                self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o755)
            self.assertEqual(
                stat.S_IMODE((target / "var/lib/agent-center-agent").stat().st_mode), 0o700
            )
            with patch("sys.argv", argv):
                self.assertEqual(INSTALLER.main(), 2)
            self.assertEqual((base / "runtime/agent.pyz").read_bytes(), b"fixture")

    def test_non_https_origin_and_url_credentials_refused(self):
        for url in [
            "http://central.example.invalid",
            "https://secret@example.invalid",
            "https://central.example.invalid/?token=secret",
        ]:
            with patch(
                "sys.argv",
                [
                    "installer",
                    "--kit",
                    "/missing",
                    "--central-url",
                    url,
                    "--manifest-sha256",
                    "0" * 64,
                    "--check",
                ],
            ):
                self.assertEqual(INSTALLER.main(), 2)
