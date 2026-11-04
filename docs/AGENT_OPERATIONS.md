# Agent Operations — RECOVERY-7

A administração integra as páginas existentes; nenhuma Central Python/FastAPI. O Agent é o executor técnico. Runtime novo 0.5.3, baseline reconstruído 0.5.2 preservado como fonte Git.

## Administração

Em Agents: cadastrar no tenant selecionado, acompanhar estado de onboarding, revisar identidade, confirmar vínculo, ver versão/capabilities/última comunicação, inventário coletado, eventos, intenções de update/rollback e revogar credencial. Tokens existentes nunca são exibidos. Dados ausentes aparecem Indisponível; status active representa autorização e não presença.

ONLINE exige heartbeat <60s, poll <30s, versão reconhecida e relato de freeze true. Heartbeat vencido é OFFLINE; processo com heartbeat mas poll travado, freeze ausente ou cadastro pendente é ATENÇÃO. Relato de processo não comprova saúde da unit systemd. Identidade nunca é compartilhada entre máquinas restauradas.

## Instalador offline

O kit contém runtime/updater/companion, units, wrappers, logrotate, catálogo e manifesto. Não baixa scripts. Dependências de runtime: nenhuma (stdlib); CPython 3.13 e ABI são validados. Debian 12/13 com Python 3.13 são aceitos, Debian 13 é prioritário. Não instala PHP, FastAPI, SSH, Huawei ou ferramentas de rede opcionais.

```sh
python3 agent/packaging/install-agent.py --kit /caminho/kit-confiavel --central-url https://central-autorizada.example --manifest-sha256 SHA256_REAL_DO_MANIFESTO --check
```

Depois de revisar artefatos/hash de fonte confiável, executar o mesmo comando como root sem `--check`. Endereço é exemplo, não destino provisionado. `--ca-file` exige `--ca-sha256` e instala somente CA revisada no trust store Debian; verificação TLS nunca é desativada. Cria usuário dedicado sem login, diretórios, runtime root-owned, config/identity privados, unit hardened, logs e logrotate. Recusa instalação preexistente; utilizar procedimento explícito de atualização. Não habilita companion remoto por padrão.

Instalação termina em aprovação pendente (exit 4) antes de iniciar serviço. Depois da revisão, executar `/opt/agent-center-agent/complete-enrollment.sh`, que retoma receipt e valida presença funcional. `--lab-root` somente materializa layout num diretório vazio: não cria conta nem inicia systemd e declara isso explicitamente. Não confundir esse teste com instalação real completa.

## Operação e segurança

Config e identity permanecem privados. Root é recusado pelo runtime. Bearer é hash na Central; runtime mantém cópia 0600. Logs usam eventos fechados e não incluem request/response/token. Telemetria é schema fechado: OS/kernel/Python/CPU count, memória total/RSS, disco livre, PID/threads/uptime, processo, freeze, dry-run, leitura de journal e pendências; service_status fica indisponível quando não medido. Cada amostra tem horário de recebimento.

Device freeze é obrigatório em Central, dispatcher e config do Agent. Futuras device_* desconhecidas são recusadas. Updater valida freeze no self-check e não troca config/policies/state; rollback não autoriza device/SSH. Nenhum shell arbitrário é executado pelo navegador ou LLM.

Ver [Onboarding](AGENT_ONBOARDING.md), [Updates](AGENT_UPDATES.md), [Health](OPERATIONS_HEALTH.md) e [Validação](RECOVERY_7_VALIDATION.md).

## Procedimentos anteriores reutilizados

Os detalhes abaixo são o baseline RECOVERY-2/3. Trechos sobre emissão imediata ou ausência de controle remoto estão superados pelos documentos RECOVERY-7 acima.

# Operação do Agent 0.5.2

## Artefatos e instalação local autorizada

Build stdlib-only (Python >=3.13):

```sh
mkdir -p agent/dist
python3 agent/packaging/build.py agent/dist/agent-0.5.2.pyz
python3 agent/packaging/build.py agent/dist/updater.pyz updater
(cd agent/dist && sha256sum *.pyz > SHA256SUMS)
```

Runtime e updater zipapp propagam exit codes. Self-check não precisa de config/token: `python3 agent/dist/agent-0.5.2.pyz --self-check`. Não há dependência Python de runtime; Ruff/setuptools são ferramentas isoladas de teste/build. SHA verifica integridade, não autenticidade do publicador: obter artefato/hash por canal de operador confiável. Não baixar/instalar update indicado por LLM ou task.

Unit em `agent/systemd/agent-center-agent.service`, verificada por systemd-analyze. Não foi habilitada globalmente nesta VPS: falta definir identidade de produção/URL HTTPS. O ensaio usa processo dedicado UID/GID 65532 em container lab, sem root e sem capabilities.

Para instalar após configurar destino real, operador cria usuário system sem login `agent-center-agent`, diretórios root-owned `/opt/agent-center-agent` (0755) e runtime/ com agent.pyz root:root 0644. Updater.pyz fica fora de runtime/, em `/opt/agent-center-agent/updater.pyz`, root:root 0644, junto dos wrappers root-owned update-agent.sh/rollback-agent.sh. O usuário do serviço não pode escrever no código/updater.

Criar `/etc/agent-center-agent/config.json` a partir de config.example.json, preenchendo URL real, arquivo pertencente ao usuário dedicado 0600 em diretório controlado pelo root. State/logs pertencem ao usuário dedicado em 0700. Systemd StateDirectory/LogsDirectory/RuntimeDirectory prepara diretórios com as permissões definidas. Nunca usar /root como HOME/state. Execução normal como root é recusada.

Enrollment manual com serviço parado, como usuário dedicado: `python3 /opt/agent-center-agent/runtime/agent.pyz --config /etc/agent-center-agent/config.json --enroll`. Código vem da UI Laravel e é solicitado em prompt oculto, sem argumento/env/arquivo de transporte. Token fica somente no identity_file 0600. Não redirecionar entrada interativa para logs. Só depois instalar a unit, daemon-reload e habilitar/iniciar a unidade específica do Agent. Não reiniciar serviços globais não relacionados.

## Config e segurança

URL HTTPS validada; HTTP só para loopback com allow_http_loopback=true em lab. Central_url não aceita credenciais/query/fragmentos. Não desabilitar certificados. Identidade inválida/config inválida: erro genérico e exit 2; autenticação revogada/tenant desabilitado: QUARANTINED/exit 3. Systemd não reinicia automaticamente esses códigos. Rede temporariamente indisponível mantém loop degradado com backoff e heartbeat separado.

Config e token não entram em logs. Saída padrão usa eventos JSON fixos via fila não bloqueante; journald é o destino padrão. Logrotate preparado para coletores opcionais em /var/log/agent-center-agent, rotação diária por sete dias, arquivos 0600. Não há log de Authorization, code, response body ou credenciais de device.

Device freeze não é opção operacional para liberar SSH: continua true por config/policy/código; qualquer device_* desconhecido é negado. CIDRs autorizados para probes começam vazios. Não instalar ping/traceroute ou ampliar targets sem autorização e teste local específicos. Nenhuma ação real de device faz parte desta entrega.

## Update e rollback

```sh
/opt/agent-center-agent/update-agent.sh --artifact /caminho/confiavel/agent.pyz --sha256 <SHA256_REAL> --dry-run
/opt/agent-center-agent/update-agent.sh --artifact /caminho/confiavel/agent.pyz --sha256 <SHA256_REAL> --apply
/opt/agent-center-agent/rollback-agent.sh --dry-run
/opt/agent-center-agent/rollback-agent.sh --apply
```

Apply requer root e paths de produção fixos. Sempre executa validação/dry-run interno: tamanho/estrutura zip, SHA-256 e self-check isolado do candidato. Não aceita path traversal/symlinks no artefato. Self-check lançado por root reduz UID/GID antes de executar o candidato. Código candidato precisa ser confiável; hash não substitui revisão/autenticidade.

Serviço é parado antes de swap; se continuar ativo ou instance.lock estiver em uso, runtime não é trocado. Código antigo vai para .previous; falha nova vai para .failed. Históricos anteriores recebem sufixo, sem apagar journal/config. Self-check é feito antes da partida final. Serviço que estava parado continua parado. Falha de novo runtime restaura o anterior e reinicia somente se era ativo. Interrupção entre renames pode ser recuperada por nova operação, preservando .previous. Se o processo principal ficou ausente após crash durante swap, operador executa rollback; nunca inicia código incompleto nem restaura state antigo indiscriminadamente.

Arquivo update.json é fsync/rename; renames de diretórios sincronizam o parent. Config/state/journal são caminhos separados e não copiados pelo updater. Estado de execução uncertain permanece uncertain depois de rollback. Não há self-update remoto no painel/API.

## Backups e disaster recovery

Central: pg_dump administrativo inclui agents, agent_enrollments, audit, constraints/RLS/grants de referência e migrations; restore isolado aplica ownership migrator, grants runtime e verifica FORCE RLS/trigger. Secrets e código/bundle seguem o plano existente, com réplica externa ainda pendente.

Agent: backup confidencial de `/etc/agent-center-agent`, `/var/lib/agent-center-agent` inteiro e runtime/updater/previous. Preferir parada limpa antes de copiar SQLite; se online, usar sqlite3 backup API para snapshot consistente e preservar WAL. Criptografar externamente; identidade contém token. Nunca guardar secrets no Git. Não iniciar duas máquinas com a mesma identidade restaurada. Em perda/clone incerto, revogar e reenrolar.

Restaurar código e state do mesmo incidente não autoriza replay: executar recovery/reconcile, manter resultado incerto em hold. Rollback de código não deve substituir state por snapshot anterior. Testar restauração do Agent em diretório isolado antes de apontar para a Central real.

## Testes e lab

`./scripts/test.sh`: Laravel/PostgreSQL real com roles distintas. `agent/tests`: unittest Python 3.13; Ruff/compileall/pip check em container isolado. `scripts/lab/start.sh` cria database/container exclusivos, enrollment em lab e testa todos os endpoints com Client Python real. Segredos de lab ficam só em /tmp privado e não são impressos.

`scripts/lab/long-run.py` exige pelo menos 900 segundos; mede heartbeat/poll, RSS, CPU, FDs/sockets, threads/filhos e integridade do journal. Não acessa config/identity. A unit é apenas verificada; o lab não modifica systemd nem dispositivos. Ao terminar, parar/remover somente os containers lab, apagar seus secrets temporários e dropar apenas agent_center_lab; preservar relatório sanitizado. Não usar docker prune ou comandos globais.
