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
