# MTR secure executor — Debian 13

## Privilégios e arquitetura

O serviço principal continua como `agent-center-agent`, com `NoNewPrivileges=yes`, `PrivateDevices=yes`, `CapabilityBoundingSet=` e `AmbientCapabilities=` vazios. Nenhum drop-in ou alteração desse serviço é necessário.

`CAP_NET_RAW` é necessária para os sockets raw de ICMP e para as sondas TCP/recepção de ICMP do MTR. A capability de arquivo já presente em `/usr/bin/mtr-packet` não pode ampliar o bounding set vazio do Agent sob NoNewPrivileges. Não é necessário conceder NET_ADMIN, SETUID, SETGID, SYS_ADMIN ou acesso a dispositivos ao executor.

O Agent valida a tarefa e a política local e conecta a `/run/agent-center-mtr.sock`. O systemd inicia `agent-center-mtr@.service` sob o usuário dedicado `agent-center-mtr`, sem login, com **somente CAP_NET_RAW** no bounding set e no ambient set. O helper não roda como root. O processo principal não recebe a capability por conectar ao socket.

O socket é 0600, propriedade de agent-center-agent, em /run controlado por root. O helper confere SO_PEERCRED e recusa outros UIDs, inclusive root no protocolo. O PID 1 carrega, via LoadCredential, um snapshot atualizado da configuração local e o teto root-only `/etc/agent-center-mtr/policy.json` para cada conexão. Nenhuma identidade/token é carregada. O helper exige autorização nas duas políticas, valida DNS novamente e executa somente o IP numérico autorizado. allowed_dns_names/allowed_networks continuam obrigatórios; auto_public_targets não basta. Alvos privados exigem CIDR explícito em ambas. O instalador também exige que o teto revisado seja subconjunto da política local.

O helper usa a allowlist-base de config.json, além do teto administrativo. Autorizações existentes somente em managed-settings.json não são importadas nesta versão: esses destinos são recusados pelo helper. Não altere a política-base para contornar uma recusa; revise o escopo e a autorização antes de qualquer ajuste administrativo.

## Protocolo e limites

Somente `ready` e `collect`, JSON fechado, sem caminhos, comandos, portas livres ou flags. Os únicos modos de coleta são ICMP e TCP/443. Pedido até 4096 bytes; resposta até 65536; stdout/stderr do MTR até 49152. Ambiente mínimo remove MTR_PACKET/MTR_OPTIONS e demais variáveis herdadas do processo MTR. O helper verifica versão e suporte às opções antes de anunciar disponibilidade.

Até dois canais locais podem existir para acomodar a verificação de disponibilidade e uma coleta. **Apenas uma coleta é permitida**, por flock no diretório privado do helper; há cooldown de 10 segundos, também aplicado pelo Agent/Central. A verificação de disponibilidade é cacheada por um segundo no processo cliente, sem substituir as verificações de cada coleta. Não há retry de coleta após resultado de transporte incerto.

5–10 ciclos, até 16 hops, intervalo de 1 segundo, timeout interno de 18 segundos e alarme de 19 segundos. O serviço limita a execução a 20 segundos, 64 MiB, CPU a 25%, oito tarefas e 64 descritores. O Task Engine conserva seu watchdog de 20 segundos. Fechar o socket cancela a coleta; KillMode=control-group e TimeoutStopSec=1 encerram os filhos remanescentes. Ausência de resposta ou erro do executável não é convertido em falha de encaminhamento. O parser/classificador existentes continuam responsáveis pela interpretação.

O helper não tem AF_PACKET, não lê a identidade privada do Agent, não grava seu próprio código e não consegue trocar para UID 0. tcpdump, Nmap e device actions não têm handlers nesse protocolo.

## Pré-condições de implantação

Este é um kit offline preparado para execução administrativa **manual** na VM. Nenhuma instalação foi executada na VM teste. O runtime candidato é 0.6.2. O runtime 0.6.1 não conhece o helper.

Antes de atualizar o runtime, a Central precisa estar implantada com o contrato e as migrations MTR da branch de integração: capability mtr_path_analysis:v1, versão 0.6.2, Normalizer, Planner e classificação. O kit não publica a Central. Não atualizar o runtime contra uma Central que ainda rejeita essa capability. Promover a branch pela rotina de imagem/migration do projeto; não fazer checkout forçado sobre alterações locais.

Verifique Debian 13, `/usr/bin/python3` 3.13, systemd e o cadastro existente. O instalador recusa outro SO/Python, hardening principal diferente, artefato adulterado, teto fora da política, caminhos inseguros e instalação anterior. Ele prepara os arquivos e o usuário isolado, **sem iniciar serviço, atualizar runtime, modificar config, identidade ou ACL**. Não é um instalador genérico de pacotes nem um mecanismo remoto de root.

Conforme a solicitação de preparar também os utilitários para diagnósticos futuros, o operador pode instalar os pacotes dos repositórios oficiais Debian:

```bash
apt-get update
apt-get install --no-install-recommends mtr-tiny tcpdump nmap
mtr --version
getcap /usr/bin/mtr-packet
```

Isso somente instala os utilitários. Captura e varredura remota continuam desabilitadas. O helper MTR não executa tcpdump/Nmap. A instalação desses pacotes não autoriza alvos nem amplia o serviço principal.

## Instalação manual do helper

Transfira o tar.gz e seu SHA-256 por um canal administrativo confiável. Confira o SHA externo **antes** de executar qualquer script. O manifesto interno verifica integridade dos arquivos, mas não substitui a confiança no digest externo.

```bash
cd /root
sha256sum -c mtr-secure-debian13.tar.gz.sha256
tar -xzf mtr-secure-debian13.tar.gz
cd /root/mtr-secure-debian13
```

Crie `/root/mtr-policy.json`, root:root 0600, com o subconjunto de destinos já autorizado no config local. Exemplo, somente se google.com já constar em allowed_dns_names:

```json
{"schema_version":1,"allowed_dns_names":["google.com"],"allowed_networks":[]}
```

Não copie redes amplas nem altere config.json para fazer o teste passar. O arquivo de exemplo vazio do kit não habilita destino algum.

```bash
chmod 600 /root/mtr-policy.json
/usr/bin/python3 -I install-helper.py --policy /root/mtr-policy.json
systemctl enable --now agent-center-mtr.socket
runuser -u agent-center-agent -- /usr/bin/python3 -I /opt/agent-center-mtr/check-helper.py
systemctl show agent-center-agent.service -p User -p NoNewPrivileges -p PrivateDevices -p CapabilityBoundingSet -p AmbientCapabilities
systemctl cat agent-center-mtr@.service
```

A verificação do helper deve retornar mtr_helper_ready=true, sem enviar probes. O serviço principal deve manter os bounding/ambient sets vazios. O instalador informa o diretório root-only de backup em `/var/lib/agent-center-updater/mtr-preparations/`, com hash da configuração e unit principal e os caminhos criados. Identidade, cadastro e configuração não são reinstalados.

## Atualização manual do runtime

Execute somente após a Central suportar o contrato MTR e a verificação do helper passar. Use o atualizador existente, preservando seu locking, validação SHA, backup e rollback. Não substitua arquivos do runtime à mão.

```bash
cd /root/mtr-secure-debian13
mtr_runtime_sha=$(/usr/bin/python3 -c 'import json;print(json.load(open("manifest.json"))["agent.pyz"])')
/usr/bin/python3 -I /opt/agent-center-agent/updater.pyz update --artifact "$PWD/agent.pyz" --sha256 "$mtr_runtime_sha" --dry-run
/usr/bin/python3 -I /opt/agent-center-agent/updater.pyz update --artifact "$PWD/agent.pyz" --sha256 "$mtr_runtime_sha" --apply
systemctl is-active agent-center-agent.service
systemctl show agent-center-agent.service -p NoNewPrivileges -p PrivateDevices -p CapabilityBoundingSet -p AmbientCapabilities
```

A self-check permanece compatível com o companion antigo: anuncia o conjunto legado nessa saída específica. A capability MTR é negociada pelo protocolo normal, após confirmar o helper. No painel, confirme versão 0.6.2, heartbeat e capability mtr_path_analysis:v1; execute MTR ICMP e TCP/443 em alvo já permitido. Examine resultado estruturado, duração, estado e Operations Health. Não habilite outras capabilities.

## Rollback

Interrompa novas conexões e encerre as instâncias em andamento:

```bash
systemctl disable --now agent-center-mtr.socket
systemctl stop 'agent-center-mtr@*.service'
```

Se e somente se o runtime deste kit foi aplicado, confira que o runtime anterior é o esperado antes de reverter:

```bash
/usr/bin/python3 -I /opt/agent-center-agent/.previous/agent.pyz --self-check
/usr/bin/python3 -I /opt/agent-center-agent/updater.pyz rollback --dry-run
/usr/bin/python3 -I /opt/agent-center-agent/updater.pyz rollback --apply
```

O anterior deve ser o 0.6.1 desta atualização, não um backup arbitrário de outra operação. Se a atualização não foi aplicada, não execute rollback de runtime. Se optar por manter 0.6.2 sem helper, reinicie o serviço principal para atualizar o anúncio de capabilities, sem editar sua unit.

Depois de confirmar o runtime/heartbeat e a ausência de instâncias MTR, remova somente as units do helper:

```bash
rm -- /etc/systemd/system/agent-center-mtr.socket /etc/systemd/system/agent-center-mtr@.service
systemctl daemon-reload
systemctl is-active agent-center-agent.service
systemctl show agent-center-agent.service -p NoNewPrivileges -p PrivateDevices -p CapabilityBoundingSet -p AmbientCapabilities
```

Retenha o usuário bloqueado, o teto, os arquivos em /opt/agent-center-mtr e o estado/backup para auditoria; não existe file capability no helper.pyz ou no Python. Com as units desabilitadas/removidas eles não recebem privilégios. Não remova identidade, journal, config.json ou dados de cadastro. A remoção administrativa posterior dos arquivos do helper deve usar somente os caminhos registrados pelo instalador.

## Referências e limites

- https://www.freedesktop.org/software/systemd/man/257/systemd.exec.html
- https://manpages.debian.org/trixie/mtr-tiny/mtr.8.en.html
- https://github.com/traviscross/mtr/blob/master/SECURITY

CAP_NET_RAW continua sensível dentro do helper: uma falha no seu código/bibliotecas pode expor sockets raw naquela conta. Por isso há código root-owned, protocolo fechado, double-check da política, limites e isolamento. Controle administrativo de root ou de arquivos de unit/artefatos é uma fronteira de confiança. O snapshot de política vale para a coleta iniciada; mudanças de política não revogam retroativamente uma coleta já em andamento, que permanece limitada a 20 segundos e pode ser cancelada.

Instalação real e diagnóstico a partir da VM teste ficam pendentes da execução manual autorizada. Isso não impede a entrega do executor pronto, dos artefatos e dos testes de laboratório; não é uma alegação de habilitação real.
