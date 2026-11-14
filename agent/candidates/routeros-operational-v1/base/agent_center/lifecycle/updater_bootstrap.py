"""One-time signed Lifecycle transaction to upgrade a legacy companion.

Invoked only by the operational administrator, never by LLM, task or user input.
Trust anchor, destination files, units, policy and control paths are fixed locally.
"""

import argparse
import json
import os
import platform
import subprocess
from pathlib import Path

from ..state import InstanceLock, atomic_write, read_private
from ..update.remote import BASE, CONFIG, CONTROL, trusted
from .manager import Manager
from .releases import ReleaseError, verify
from .updaters import Updaters


class BootstrapAdapter:
    def __init__(self, updaters, control):
        self.updaters, self.control = updaters, Path(control)
        self.backup = None

    def precheck(self, release, artifact):
        if tuple(map(int, release["version"].split("."))) < (0, 6, 4):
            raise ReleaseError("incompatible_updater")
        self.updaters.precheck(artifact)
        return release

    def snapshot(self, operation_id, plan):
        self.backup = self.control / "backups" / operation_id
        self.backup.mkdir(mode=0o700, parents=True, exist_ok=False)
        self.updaters.snapshot(self.backup)
        return operation_id

    def install(self, plan, artifact):
        self.updaters.install()

    def health(self, plan):
        return all((self.updaters.base / name).read_bytes() == data
                   for name, data in self.updaters.plan.items())

    def rollback(self, backup):
        self.updaters.rollback(self.control / "backups" / backup)

    def rollback_health(self):
        return self.updaters.rollback_health(self.backup)


def unit(action, name):
    return subprocess.run(["/usr/bin/systemctl", action, name],
                          capture_output=True, timeout=25, check=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--directory", type=Path, required=True)
    parser.add_argument("--operation-id", required=True)
    args = parser.parse_args()
    os.umask(0o077)
    if os.geteuid() != 0:
        return 2
    timer_stopped = False
    preserve_hold = False
    lock = None
    original = None
    policy_path = CONFIG.parent / "lifecycle-policy.json"
    try:
        system = platform.freedesktop_os_release()
        if system.get("ID") != "debian" or system.get("VERSION_ID") != "13":
            raise ReleaseError("incompatible_platform")
        trusted(CONTROL)
        trusted(args.directory)
        files = {}
        for name in ("agent.pyz", "manifest.json", "signature.base64"):
            path = trusted(args.directory / name)
            if not path.is_file() or path.stat().st_size > 8388608:
                raise ReleaseError("invalid_release")
            files[name] = path.read_bytes()
        key = trusted(CONFIG.parent / "lifecycle-public.pem").read_bytes()
        installed = read_private(CONTROL / "lifecycle/installed.json")
        verify(files["manifest.json"], files["signature.base64"].decode().strip(), key,
               files["agent.pyz"], minimum_sequence=installed["sequence"], platform="debian13")
        unit("stop", "agent-center-updater.timer")
        timer_stopped = True
        active = unit("show", "agent-center-updater.service").stdout.decode()
        if "ActiveState=inactive\n" not in active and "ActiveState=failed\n" not in active:
            raise ReleaseError("updater_busy")
        lock = InstanceLock(CONTROL / "helper.lock")
        policy_path = trusted(policy_path)
        original = read_private(policy_path)
        if original.get("device_operations_frozen") is not True:
            raise ReleaseError("invalid_component_policy")
        policy = {**original,
                  "components": list(dict.fromkeys([*original["components"], "lifecycle-updater"])),
                  "profiles": list(dict.fromkeys([*original["profiles"], "lifecycle-updater"]))}
        control = CONTROL / "companion-lifecycle"
        control.mkdir(mode=0o700, exist_ok=True)
        policy_backups = control / "policy-backups"
        policy_backups.mkdir(mode=0o700, exist_ok=True)
        saved_policy = policy_backups / "before.json"
        if not saved_policy.exists():
            atomic_write(saved_policy, original)
        atomic_write(policy_path, policy)
        adapter = BootstrapAdapter(Updaters(BASE, CONFIG.parent), control)
        result = Manager(control, adapter, key, "debian13").apply(
            args.operation_id, files["manifest.json"], files["signature.base64"].decode().strip(),
            files["agent.pyz"],
        )
        preserve_hold = result["state"] == "UNCERTAIN"
        if result["state"] != "COMPLETED" and not preserve_hold:
            atomic_write(policy_path, original)
        print(json.dumps({"bootstrap_state": result["state"], "operation_id": result["operation_id"],
                          "rollback": result.get("rollback"), "runtime_untouched": True,
                          "identity_journal_untouched": True}))
        return 0 if result["state"] == "COMPLETED" else 2
    except Exception:
        if original is not None and not preserve_hold:
            atomic_write(policy_path, original)
        print('{"bootstrap_state":"REFUSED"}')
        return 2
    finally:
        if lock:
            lock.close()
        if timer_stopped and not preserve_hold:
            unit("start", "agent-center-updater.timer")


if __name__ == "__main__":
    raise SystemExit(main())
