#!/bin/sh
set -eu
exec /usr/bin/python3 -I /opt/agent-center-agent/updater.pyz rollback "$@"
