#!/bin/bash
# Agent Center: kit verificado, cadastro com aprovacao no painel.
set -euo pipefail
umask 077
agent_install_mode=install
if [ "$#" -eq 1 ] && [ "$1" = '--reparar-atualizador' ]; then
    agent_install_mode=repair
elif [ "$#" -ne 0 ]; then
    echo 'Uso: bash instalar-agent.sh [--reparar-atualizador]'
    exit 2
fi
if [ "$EUID" -ne 0 ]; then
    if command -v sudo >/dev/null; then exec sudo /bin/bash "$0" "$@"; fi
    echo 'Esta conta precisa de acesso administrativo. Entre como root com su - e execute bash instalar-agent.sh.'
    exit 2
fi
if [ "$agent_install_mode" = install ] && { [ -e /var/lib/agent-center-agent/identity.json ] || [ -L /var/lib/agent-center-agent/identity.json ]; }; then
    agent_install_mode=repair
fi
for tool in curl tar sha256sum python3; do
    command -v "$tool" >/dev/null || { echo "Falta instalar: $tool"; exit 2; }
done
python3 -c 'import sys,platform; assert sys.implementation.name == "cpython" and sys.version_info[:2] == (3,13) and platform.system() == "Linux" and platform.freedesktop_os_release().get("ID") == "debian" and platform.freedesktop_os_release().get("VERSION_ID") in ("12","13"), "Requer Debian 12/13 com /usr/bin/python3 na versao 3.13"'
if [ ! -t 0 ]; then echo 'Execute o instalador em um terminal interativo.'; exit 2; fi
if [ "$agent_install_mode" = install ] && ! command -v sudo >/dev/null; then
    if ! /usr/bin/apt-get update >/dev/null 2>&1 || ! DEBIAN_FRONTEND=noninteractive /usr/bin/apt-get install --yes --no-install-recommends sudo >/dev/null 2>&1; then
        echo 'Não foi possível preparar a instalação. Solicite suporte.'
        exit 2
    fi
fi
agent_workdir=$(mktemp -d /tmp/agent-center-install.XXXXXXXX)
trap 'rm -rf -- "$agent_workdir"' EXIT
bundle="$agent_workdir/agent-center-agent-0.6.1.tar.gz"
curl --fail --show-error --silent --proto '=https' --tlsv1.2 --connect-timeout 15 --max-time 120 'https://agente.trevizamnetwork.com.br/downloads/agent/0.6.1/agent-center-agent-0.6.1.tar.gz' -o "$bundle"
printf '%s  %s\n' 'f9746b9c719de47f154de5b1ca682081691bdf6af7dea627faae261fe53efc4e' "$bundle" | sha256sum --check --status
tar --extract --gzip --file "$bundle" --directory "$agent_workdir" --no-same-owner --no-same-permissions
curl --fail --show-error --silent --proto '=https' --tlsv1.2 --connect-timeout 15 --max-time 120 'https://agente.trevizamnetwork.com.br/instalar-agent-auto.py' -o "$agent_workdir/instalar-agent-auto.py"
printf '%s  %s\n' '812099ffbf22aee90bca8c35a617d9cbfe1752c7f352d1d15df996834aa1d909' "$agent_workdir/instalar-agent-auto.py" | sha256sum --check --status
if [ "$agent_install_mode" = repair ]; then
    /usr/bin/python3 "$agent_workdir/instalar-agent-auto.py" --kit "$agent_workdir/agentkit" --repair-updater
else
    /usr/bin/python3 "$agent_workdir/instalar-agent-auto.py" --kit "$agent_workdir/agentkit"
fi
