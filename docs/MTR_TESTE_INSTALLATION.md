# VM teste — procedimento único de instalação controlada

**Não executar sem autorização operacional específica. Esta tarefa publica somente a Central.**

Pré-condições: Debian 13, Python 3.13 em /usr/bin/python3, Agent 0.6.1 online, MTR 0.95, Central com contrato MTR 2026.11.06.1. A Central publicada não disponibiliza 0.6.2 no atualizador automático; a promoção aqui é administrativa e manual. Não executar tcpdump, Nmap ou ações de devices.

## 1. Verificar pacote e estado

Transfira o kit e seu .sha256 por canal confiável para /root da VM. Confira também o digest externo no relatório da entrega. Não use curl|bash.

```bash
cd /root
sha256sum -c mtr-secure-debian13.tar.gz.sha256
tar -xzf mtr-secure-debian13.tar.gz
cd /root/mtr-secure-debian13
/usr/bin/python3 --version
mtr --version
getcap /usr/bin/mtr-packet
systemctl is-active agent-center-agent.service
systemctl show agent-center-agent.service -p User -p NoNewPrivileges -p PrivateDevices -p CapabilityBoundingSet -p AmbientCapabilities
/usr/bin/python3 -c 'import json; c=json.load(open("/etc/agent-center-agent/config.json")); print({k:c.get(k,[]) for k in ("allowed_dns_names","allowed_networks")})'
```

Exigir User=agent-center-agent, NoNewPrivileges=yes, PrivateDevices=yes, bounding/ambient vazios. Não mudar a unit principal. Só prossiga com google.com se estiver autorizado explicitamente na ACL-base. managed-settings.json não substitui essa autorização. Não imprimir identidade/token.

Configuração mínima para o teste: ACL-base já existente com allowed_dns_names contendo google.com; teto root-only com schema_version=1, allowed_dns_names=[google.com], allowed_networks=[]. Outras autorizações da configuração-base são preservadas; não ampliar nenhuma rede/domínio.

## 2. Backup privado e teto revisado

```bash
umask 077
mtr_backup_dir=$(mktemp -d /root/agent-mtr-before.XXXXXX)
cp --preserve=mode,ownership /etc/agent-center-agent/config.json "$mtr_backup_dir/config.json"
cp --preserve=mode,ownership /var/lib/agent-center-agent/identity.json "$mtr_backup_dir/identity.json"
/usr/bin/python3 -c 'import sqlite3,sys; source=sqlite3.connect("file:/var/lib/agent-center-agent/journal.sqlite3?mode=ro",uri=True); target=sqlite3.connect(sys.argv[1]); source.backup(target); target.close(); source.close()' "$mtr_backup_dir/journal.sqlite3"
/usr/bin/python3 -c 'import json; c=json.load(open("/etc/agent-center-agent/config.json")); assert "google.com" in c.get("allowed_dns_names",[]), "Destino ausente da ACL-base; interrompa"; f=open("/root/mtr-policy.json","x"); json.dump({"schema_version":1,"allowed_dns_names":["google.com"],"allowed_networks":[]},f); f.close()'
chmod 600 /root/mtr-policy.json
```

O backup do journal usa a API SQLite para consistência durante operação. Não copiar uma base SQLite viva de forma incompleta nem restaurar um journal antigo no rollback comum. O teto é criado com exclusão (não sobrescreve um teto já revisado). Se /root/mtr-policy.json existir, interrompa e confira o arquivo existente. O instalador recusa teto fora da base e caminhos inseguros.

## 3. Instalar e verificar helper

```bash
cd /root/mtr-secure-debian13
/usr/bin/python3 -I install-helper.py --policy /root/mtr-policy.json
systemctl enable --now agent-center-mtr.socket
runuser -u agent-center-agent -- /usr/bin/python3 -I /opt/agent-center-mtr/check-helper.py
systemctl cat agent-center-mtr@.service
systemctl show agent-center-agent.service -p NoNewPrivileges -p PrivateDevices -p CapabilityBoundingSet -p AmbientCapabilities
getcap /opt/agent-center-mtr/helper.pyz /usr/bin/python3
```

Exigir mtr_helper_ready=true. A unit isolada deve usar agent-center-mtr, NoNewPrivileges=yes, bounding/ambient somente CAP_NET_RAW, KillMode=control-group, prazo 20 s. Python e helper.pyz não recebem file capabilities. O principal deve continuar com sets vazios. O check usa o UID do Agent e confirma o helper real, sem probes. O instalador só prepara arquivos/usuário e reload; não inicia serviços nem atualiza runtime sozinho.

## 4. Atualizar pelo atualizador existente

Antes de aplicar, confirmar na Central a versão do contrato e health. Não substituir o runtime à mão.

```bash
cd /root/mtr-secure-debian13
mtr_runtime_sha=$(/usr/bin/python3 -c 'import json;print(json.load(open("manifest.json"))["agent.pyz"])')
/usr/bin/python3 -I /opt/agent-center-agent/updater.pyz update --artifact "$PWD/agent.pyz" --sha256 "$mtr_runtime_sha" --dry-run
/usr/bin/python3 -I /opt/agent-center-agent/updater.pyz update --artifact "$PWD/agent.pyz" --sha256 "$mtr_runtime_sha" --apply
systemctl is-active agent-center-agent.service
systemctl show agent-center-agent.service -p NoNewPrivileges -p PrivateDevices -p CapabilityBoundingSet -p AmbientCapabilities
```

Identidade, configuração, journal e state/journal schema 1 são preservados. Não trocar updater/companion nem reinstalar cadastro. A self-check de manutenção omite a extensão MTR por compatibilidade com o companion antigo; a confirmação verdadeira ocorre pela API de capabilities autenticada, após o helper passar no teste de disponibilidade.

## 5. Validar pelo painel

Conferir Agent 0.6.2 ONLINE e “capability MTR confirmada pelo Agent”. Na ausência de confirmação, interromper: não modificar manualmente metadados/capabilities no banco.

Executar, nessa ordem, Profiles Caminho MTR ICMP e Caminho MTR TCP/443 para google.com, autorizado explicitamente. Aguardar pelo menos 10 segundos entre coletas. Não usar flags livres. Conferir resultado estruturado, tempo, hops, protocolo, interpretação e Operations Health. Se o destino filtrar sondas, INCONCLUSIVO é válido; não tratar como falha de encaminhamento sem evidência.

Durante a coleta, em outro terminal administrativo, conferir a instância real e suas propriedades:

```bash
systemctl list-units 'agent-center-mtr@*.service'
systemctl show 'agent-center-mtr@*.service' -p User -p NoNewPrivileges -p CapabilityBoundingSet -p AmbientCapabilities -p PrivateDevices
```

Ao cancelar um diagnóstico, o worker fecha o canal e o helper encerra a coleta; conferir ausência de instância executando após a janela de encerramento. Não confirmar sucesso apenas por haver processo MTR ou pacote instalado.

## 6. Rollback para 0.6.1

Somente se este kit foi aplicado, conferir primeiro que .previous contém o runtime esperado 0.6.1:

```bash
systemctl disable --now agent-center-mtr.socket
systemctl stop 'agent-center-mtr@*.service'
/usr/bin/python3 -I /opt/agent-center-agent/.previous/agent.pyz --self-check
/usr/bin/python3 -I /opt/agent-center-agent/updater.pyz rollback --dry-run
/usr/bin/python3 -I /opt/agent-center-agent/updater.pyz rollback --apply
systemctl is-active agent-center-agent.service
systemctl show agent-center-agent.service -p NoNewPrivileges -p PrivateDevices -p CapabilityBoundingSet -p AmbientCapabilities
```

Não reverter uma operação diferente nem restaurar identidade/journal antigos sobre o estado atual. Se 0.6.2 não foi aplicado, não executar rollback do runtime. Manter contas bloqueadas, teto e backups para auditoria; remover units do helper apenas depois de confirmar ausência de instâncias e o heartbeat do 0.6.1. Para retirada das units:

```bash
rm -- /etc/systemd/system/agent-center-mtr.socket /etc/systemd/system/agent-center-mtr@.service
systemctl daemon-reload
```

A Central aceita 0.6.1 e 0.6.2. Após rollback, o anúncio autenticado do 0.6.1 remove a capability e MTR volta a indisponível; os diagnósticos anteriores permanecem acessíveis. Não ampliar ACL, reduzir hardening ou liberar ferramentas para contornar falhas.
