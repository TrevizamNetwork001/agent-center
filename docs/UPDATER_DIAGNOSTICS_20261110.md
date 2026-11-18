# Diagnóstico local do updater — 10/11/2026

Status: mitigação de configuração aplicada ao runtime 0.6.2 da VM `teste` e
observação de 900 segundos aprovada; patch do companion local e testado, ainda
não distribuído. A falha intermitente do companion não está encerrada.

## Constatação

O registro `AGENT_OBSERVABILITY_V1_PRODUCTION_BLOCKED.md` relata saídas 1 do
companion antes e durante a tentativa de publicação, sem causa específica no log.
A coleta SSH posterior foi autorizada pelo usuário, incluindo validação e
correções necessárias no Agent cadastrado. O destino passou pela allowlist,
validade do cadastro, Gate administrativo e precheck nativo. A fingerprint foi
conferida antes de cada autenticação; credenciais permaneceram na Central.

Em `agent/src/agent_center/update/remote.py`, `Remote.run()` retorna `False`
quando a consulta `operations/claim` ou um envio `operations/report` falha.
O entrypoint transforma esse retorno em exit 1, sem registrar o Outcome da API.
Outros caminhos também retornam False, incluindo resultados FAILED/UNCERTAIN;
portanto o exit 1 histórico, isoladamente, não identifica a causa.

## Evidência na VM e na Central

- Runtime e companion instalados: 0.6.2. Binários iguais aos hashes do rollback
  registrado em 07/11. Agent ONLINE, socket `agent-center-mtr.socket` listening,
  journal com 87 execuções concluídas e 348 eventos; zero operação/tarefa ativa.
- Treze exits 1 no recorte inicial das 4.000 linhas mais recentes do updater,
  entre 08:02 e 14:34 UTC de 10/11. Nova ocorrência às 14:42:26 UTC. Não é uma
  contagem completa das últimas 24 horas: o limite de linhas corta o período.
  Outra ocorrência foi registrada às 14:46:13 UTC, após a retomada do timer com
  o companion original; o ajuste de timeout do runtime não muda esse processo.
- Quatro ocorrências do recorte inicial coincidem com respostas HTTP próximas
  de cinco segundos. O log do vhost contém somente horário/status/duração, sem
  rota: essa correlação não identifica a requisição específica do updater.
- Nas sondagens sem credencial foi reproduzido timeout no handshake TLS após
  cerca de cinco segundos, antes do processamento Laravel. Isso comprova falha
  de transporte nas sondagens; não prova a causa de todos os exits históricos.
- Vinte sondagens autenticadas com versão deliberadamente inválida receberam
  422 esperado, sem claim de operação. Os timeouts de 5 e 10 segundos foram
  alternados; uma resposta do grupo de 10 segundos levou 5,34 s no total.
- Seis sondagens adicionais forçando IPv4/IPv6 receberam 422 esperado, sem
  `origin_not_allowed`. A allowlist já contém as duas famílias; não foi ampliada.
- Trinta conexões comparando MSS normal/1200 registraram latência nas duas
  condições. A amostra não justifica diagnóstico de MTU nem mudança de rota.
- Os tempos internos registrados de `agent.claim` no período consultado da
  Central foram inferiores a 2,13 s. Isso não cobre `operations/claim`, que não
  participa desse canal de medição, e não elimina picos fora do recorte.

O GET de readiness por IPv6 recebeu 403 da política de acesso do vhost. Isso
não foi tratado como readiness Laravel nem motivou liberação de acesso.
As verificações autenticadas da API foram feitas separadamente.

Evidências sanitizadas em `evidence/updater-diagnostics-20261110/`. Não contêm
token, senha, identidade privada, corpo de resposta nem dump do banco.

## Alteração preparada no companion

Todas as chamadas de claim/report do companion passam por um wrapper que preserva
o Outcome e registra falhas com evento `updater_api_failed`, operação fixa, motivo
permitido, status HTTP validado e indicador de possibilidade de nova tentativa.
Esse indicador descreve o Outcome; não acrescenta retry nem repete operações.
Erros desconhecidos são convertidos em `unavailable`. O transporte do entrypoint
passa a usar timeout fixo de 10 segundos com `retries=0`, preservando os códigos
de saída e a ausência de replay de claim/report. Reports FAILED/UNCERTAIN
entregues recebem evento com motivo permitido; recusa na verificação de
recuperação emite somente motivo fixo, sem exceção ou conteúdo remoto.

Não são registrados payload, corpo da resposta, token, URL, identidade ou texto
de exceção. Nenhum endpoint, autorização, isolamento por tenant, política,
privilégio ou critério de saúde foi alterado. A alteração de timeout está
explicitada acima; não há incremento de tentativas.

## Mitigação aplicada ao runtime

Às 14:45:59 UTC de 10/11, o pós-check confirmou ONLINE após ajustar somente
`timeout` de 5 para 10 em `/etc/agent-center-agent/config.json`, dentro do limite
aceito pelo validador nativo instalado. O timer foi pausado durante a manutenção,
o helper lock foi respeitado e somente o serviço do Agent foi reiniciado. O timer
foi retomado sem alterar sua habilitação. A configuração passou pela validação
do código instalado como usuário sem privilégios antes e depois da alteração.

Backup root-only na VM: `/var/backups/agent-center-timeout-20261110/`, contendo
configuração anterior, proprietário/modo/checksum para rollback e cópia SQLite
consistente. Identidade e binários foram conferidos e preservados. O journal
atual não foi restaurado nem substituído. A operação foi auditada na Central.

Essa configuração é lida pelo runtime. O companion instalado possui timeout
fixo próprio de cinco segundos e **não foi alterado** por essa mitigação.
A conferência intermediária às 14:56 UTC mostrou heartbeat/poll progredindo,
sem incremento do evento `poll_unavailable` após o reinício. O estado CLAIM
capturado nessa conferência é transitório normal do loop, não DEGRADED.

## Observação posterior à mitigação

Janela nativa: **14:49:06 a 15:04:06 UTC**, 900,029 segundos, 31 amostras.

- Estados amostrados ONLINE/CLAIM/IDLE; nenhuma amostra DEGRADED e nenhum
  `poll_unavailable`, startup_failed, timeout ou exit 1/2 registrado no período
  consultado dos serviços do runtime e companion.
- Heartbeat avançou 45 vezes, sem regressão e com progresso em cada intervalo
  de até duas amostras. Poll avançou 124 vezes, com progresso entre todas as
  amostras consecutivas. Maior idade observada do arquivo de status: 12,627 s.
- Heartbeat e poll da Central estavam dentro dos limites de 60/30 s ao final
  da coleta. Zero tarefa ativa ou operação pendente no tenant verificado.
- Journal manteve 87 execuções e 348 eventos. Identidade preservada; hashes
  do runtime e companion iguais aos anteriores. Configuração confirmou timeout 10.
- [Coleta integral sanitizada](evidence/updater-diagnostics-20261110/observation-900s.jsonl)
  e [avaliação dos critérios](evidence/updater-diagnostics-20261110/observation-evaluation.json).

É uma janela de observação aprovada, não prova de ausência permanente de falhas.
O companion continua com timeout próprio de cinco segundos; seu exit 1 das
14:46:13 UTC está fora desta janela e permanece registrado. Não se atribui a
janela limpa à instalação do patch, pois ele não foi distribuído.

## Validação local

- 155 testes Python do Agent aprovados como usuário `nobody`, incluindo seis
  testes novos (com subcasos de rede, 401, 403, 429, 503 e resposta inválida).
- Falha no envio mantém o marker durável e impede claim posterior.
- Resposta/erro/status sintéticos com conteúdo privado não aparecem no log.
- Consulta sem operação continua bem-sucedida, silenciosa e sem tocar o engine.
- O entrypoint mantém uma única chamada após falha de transporte, timeout de
  10 segundos e ausência de credencial no evento emitido.
- Um report FAILED/UNCERTAIN entregue explica o encerramento sem claim adicional;
  motivos fora da allowlist são substituídos por código fixo.
- `git diff --check` aprovado.

## Pendência e rollback

O transporte instável foi reproduzido. Sua causa de infraestrutura e a relação
com cada ocorrência histórica de DEGRADED continuam não comprovadas. A mitigação
de runtime não deve ser apresentada como correção do companion instalado.
Agent 0.6.2 e quarentena da 0.6.3 foram preservados. Nenhuma release nova foi
assinada, publicada ou instalada.

O Lifecycle atual instala runtime e componente MTR; seu RuntimeAdapter não
substitui `remote-updater.pyz`. O reparador legado também não aceita os hashes
do runtime/companion 0.6.2 instalado. Não foi usado para forçar uma substituição.
A Central publicada aceita versões até 0.6.2, diferente da fonte local que já
inclui 0.6.3. Uma futura publicação precisa validar essa compatibilidade real.

Distribuir o patch do companion exige evoluir o suporte a esse componente no
Lifecycle, com assinatura, compatibilidade, laboratório, isolamento por tenant,
backup e rollback próprios. Não basta publicar outro runtime: o companion
continuaria executando os bytes antigos. Não foi improvisado um caminho de
instalação por SSH fora do fluxo exigido no AGENTS.md.
Não reutilizar a sequência 4 nem retirar a quarentena para contornar anti-replay.

Rollback da mitigação: conferir o estado atual e os metadados de `rollback.json`
no backup privado; restaurar somente `config.before.json` no caminho original por
troca atômica com proprietário/grupo/modo preservados e reiniciar somente
`agent-center-agent.service`, verificando ONLINE, heartbeat e poll. Preservar
alterações posteriores; não restaurar identity, journal ou binários. O script
da manutenção já executaria esse rollback automaticamente se o pós-check falhasse.

Rollback local de código: reverter apenas o patch desta revisão em remote.py e
remover o teste novo, preservando alterações preexistentes. Nenhuma migration,
regra de rede, privilégio, equipamento ou serviço não relacionado foi alterado.
