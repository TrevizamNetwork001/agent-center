# MikroTik Read-Only Integration V1 — implementação de laboratório

Data: 2026-11-14. Base documental anterior: `3955be0`. Esta entrega implementa código; a avaliação anterior permanece como histórico.

**MIKROTIK READ-ONLY INTEGRATION LAB PASS.** Código: `7f2dd4b`. Resultados consolidados em `docs/evidence/mikrotik-integration-v1/implemented/validation.json`. Nenhuma homologação de equipamento físico é declarada.

## Fluxo implementado

Conversation Engine Web/Telegram → Intent Planning e seleção exata no Inventário NOC → uma das seis operações → `Diagnostics\Preflight::device` → confirmação vinculada, válida por 60 segundos → plano/step nativos → `Diagnostics\Engine::tick` → `AgentTask`/attempt/lease → Agent API autenticada → Runtime/Executor/action_worker Python existentes → adaptador RouterOS 7 → evidências estruturadas → normalização e conclusão nativas → painel e conversa/outbox.

Não existe outro protocolo de tarefas, backend ou executor. A materialização LAB usa as tabelas existentes de profiles, diagnostics, plans e steps; o Engine existente cria a tarefa, faz claim/START/renew/result/reconcile e conclui o diagnóstico. Nenhuma migration foi necessária. Não há execução SSH pelo Laravel nesse fluxo. O transporte PHP anterior é recusado no namespace da integração, inclusive para consultas RouterOS legadas; seu harness histórico permanece apenas na regressão isolada anterior, sem autorização operacional.

O adaptador Python é `agent/src/agent_center/routeros7_lab.py`, conectado a `capabilities.execute`. O Runtime, Executor, journal, leases, fencing e Lifecycle não foram alterados por esta implementação. A capability de transporte `routeros7_lab:v1` contém exatamente uma operação tipada do enum homologado, nunca uma linha de CLI. Não registra uma Skill operacional nem amplia a allowlist publicada em produção.

## Isolamento e autorização

A extensão não possui flag de aplicação capaz de liberar a VM operacional. A Central exige `APP_ENV=testing`, montagem específica do runner e tabela de rotas exatamente na sub-rede descartável `198.18.77.0/28`, sem gateway/default route ou rota IPv6 fora de loopback. O Agent exige a mesma montagem/rede, UID não-root e a biblioteca de laboratório. A rede Docker é `internal`, sem portas publicadas. Destino SSH é compilado em `127.0.0.1:2223`; o endereço de gerenciamento do inventário participa do vínculo, mas nunca é usado como destino. Não houve sondagem de redes externas.

`Approval` continua sendo a autoridade de atestação LAB, com Policies/Gates nativos e SecretVault existentes. `Preflight` reutiliza essa aprovação e retorna ALLOWED, UNKNOWN, STALE ou DENIED. Somente ALLOWED pode gerar confirmação/tarefa. A nova aprovação tem nonce e executor `PYTHON_LOOPBACK_SIMULATOR_ONLY`; a prova antiga `CENTRAL_LOOPBACK_SIMULATOR_ONLY` não autoriza tarefas de Agent.

O vínculo canônico inclui tenant, ator, equipamento/revisão, Agent, operação, digest dos parâmetros, endereço de gerenciamento, destino/porta fixos, fingerprint, modelo/versão, revisão de credencial/acesso, política/revisão/digest, aprovação/expiração e freeze=true. Revalidação ocorre na confirmação, claim, START, renew e aceite do resultado. Os Gates atuais do ator são novamente avaliados no mesmo tenant, com restauração do contexto da Agent API em finally. Mudar equipamento, destino, parâmetros, chave, executor, política ou validade invalida a prova.

Uma aprovação materializa no máximo um diagnóstico: advisory lock por tenant/digest e reuso do registro existente evitam replay, inclusive após conclusão. A conversa também mantém consumo transacional/idempotência da confirmação. A ordem Agent → equipamento/acesso preserva a coordenação com claim. UNKNOWN, STALE e DENIED não despacham consultas; uma tarefa adulterada diretamente em banco é bloqueada antes do claim.

As credenciais do simulador vêm do SecretVault de laboratório. O harness provisiona uma cópia privada, ligada ao mesmo digest, em volume efêmero acessível ao UID 65534. Esse envelope local não concede uma segunda autorização: apenas vincula a credencial à aprovação emitida pela Central; a execução ainda exige START/lease nativos. Chaves não entram em task input, journal, respostas, auditoria, screenshots ou Git. Não foi implementada distribuição de credenciais operacionais.

## Consultas e limites

Reutilizados `app/resources/routeros7-protocol-contracts.json`, enum `RouterOs7\Operation`, validação de parâmetros e schemas Device Analysis. Alias preservado: DEVICE_IDENTITY_READ corresponde a DEVICE_IDENTIFICATION_READ no inventário. O normalizador rejeita propriedades extras, modo real, complete=true, identidade inválida e digest/contrato diferentes do input.

| Operação exercitada por conversa | Resultado da fixture validado na Central |
| --- | --- |
| DEVICE_IDENTITY_READ | CCR-LAB, RouterOS 7.16.2, modelo no envelope de identidade |
| DEVICE_BGP_PEERS_READ | Dois peers observados, um estabelecido; famílias negociadas continuam nulas |
| DEVICE_ROUTE_LOOKUP_READ | Consulta IPv4 específica, prefixo ativo observado na RIB; sem alegação de FIB/encaminhamento |
| DEVICE_INTERFACES_READ | Duas interfaces, estados disponíveis e velocidade indisponível |
| DEVICE_INTERFACE_COUNTERS_READ | Duas interfaces, contadores acumulados; sem taxa/diagnóstico de erro atual |
| DEVICE_RESOURCES_READ | CPU 12%, memória 25% no cenário fictício; sem classificação inventada de saturação |

Versão aceita: exatamente 7.16.2 stable. Cada sessão lê recursos para verificar modelo/versão antes da consulta. Comandos fixos de identidade, sessões BGP/count-only, interfaces, contadores e recursos; rota específica usa no máximo 33 prefixos ancestrais, sem dump da tabela inteira. IPv6 de rota permanece não homologado.

Prazo compartilhado do adaptador: 5 segundos; watchdog da tarefa: 8 segundos; limite agregado de console: 65.536 bytes; até 128 registros; uma execução por Agent, mais flock local. Sem shell, PTY, scripts, subsistemas, forwarding, TOFU, comandos de configuração ou CLI produzida pelo usuário/LLM. Pinning da chave precede autenticação public-key. Erros são códigos fechados; console bruto não é persistido.

Cancelamento usa o protocolo existente e encerra o grupo de processos. Um resultado que chega depois de mudança de política é descartado como evidência e registrado como policy_denied. O digest da resposta validada preserva ACK idempotente de retransmissões, sem autorizar nova execução. Outcome incerto não permite replay pelo journal.

## Evidência efetiva

Rodada de aceitação: **2 testes, 261 asserções PASS**, com PostgreSQL descartável/TLS/runtime role, HTTP real na Agent API, Runtime candidato não-root e SSH real contra simulador. Os testes não mockam o adaptador, o Executor, o Task Engine ou os callbacks da Agent API.

Foram criadas 32 tarefas/conclusões; oito adulterações foram bloqueadas antes de despacho. As outras 24 tentativas percorreram o Agent, incluindo sucessos, falhas esperadas e cancelamento. O journal terminou com 24 ciclos reconhecidos, nenhuma reserva duplicada aceita e zero replay de START. Isso não significa 24 consultas bem-sucedidas.

A solicitação “Verifique os peers BGP do MikroTik de borda.” selecionou exclusivamente um registro fixture LAB criado na base descartável, pediu confirmação de DEVICE_BGP_PEERS_READ, gerou uma única tarefa e apresentou o resumo de dois peers/um estabelecido. O painel foi renderizado por GET autenticado. O Telegram usou Ingress, Runner, Accounts, Sweep e Outbound/Deliver nativos; somente o HTTP externo para Telegram foi fake, sem enviar mensagens a pessoas. A negação de política deixou task count e trace de conexões inalterados, sem teste substituto.

Cobertura negativa: outro tenant inclusive sem global scopes/FORCE RLS; viewer sem permissão; equipamento sem aprovação e equipamento marcado como produção; executor diferente; fingerprint incorreto; credencial inválida; operação arbitrária; parâmetro malformado; equipamento/Agent/tenant/destino/chave/expiração adulterados; política expirada; mudança de política durante SSH; freeze; timeout; saída inválida, truncada, excessiva ou sensível; cancelamento; confirmação/atestado duplicado; duas tarefas para equipamentos distintos serializadas no mesmo Agent; journal sem replay; bloqueio do caminho PHP alternativo no laboratório integrado.

Evidências sanitizadas em `docs/evidence/mikrotik-integration-v1/implemented/`:

- `routeros-task-results.json`: seis solicitações conversacionais, respostas estruturadas e falhas esperadas.
- `routeros-task-engine.json` e `routeros-task-journal.json`: persistência, tentativas, UID, fencing/idempotência e freeze.
- `routeros-task-web.json` e `routeros-task-telegram.json`: respostas dos dois canais.
- `ssh-simulator-trace.json`, `network-isolation.json` e `protocol-boundaries.json`: consultas fixas observadas e isolamento.
- `browser.json` e seis PNGs: 390/768/1440 px, claro/escuro, zero overflow e zero erro JS. Revisão visual de mobile escuro e desktop claro realizada.
- Logs de Laravel, Python, Pint, Ruff e build; `validation.json` consolida os resultados finais.

## Regressões e ambiente

Python: **190 testes PASS** no tooling Python 3.13, UID/GID 65534, rede none e fonte read-only. O erro de self-check observado anteriormente no host root não se reproduz no ambiente compatível não-root. Pint: **380 arquivos PASS**. Ruff do Agent e do tooling novo: PASS. Build limpo Node 24/Linux amd64/glibc: PASS, com npm ci usando o lockfile existente e dependências opcionais corretas; npm informou zero vulnerabilidades nessa instalação.

A causa do erro anterior do Rolldown era reutilização de node_modules sem o binding nativo correto. `scripts/lab/routeros-tasks/build.sh` usa diretório vazio, imagem/arquitetura explícitas, `npm ci --include=optional --ignore-scripts` e build completo. Não houve remoção de testes, desativação do build ou mudança de lockfile.

As cinco correções de formatação identificadas na base são mínimas: imports em heartbeat/update.remote e estilo em AuditPresentation, AgentManagedSettingsTest e LayoutAuditSnapshotTest. As alterações funcionais locais anteriores desses arquivos não entram no commit da integração; `baseline-formatting.patch` registra somente as correções aplicadas ao workspace. Nenhuma mudança funcional em Lifecycle/updater foi feita nesta missão.

A suíte Laravel completa passou com **587 testes, 5.437 asserções e um skip de ambiente**, separadamente, no runner anterior sem o marcador da integração. O teste de Runtime integrado fica explicitamente reservado ao runner específico, onde foi executado com SSH real. Resultado integral e skips de ambiente ficam no log/validation.json; não se reutilizaram os números históricos de homologação como evidência nova. O aviso Composer de advisories indisponíveis na rede isolada permanece explícito: dependências presentes foram usadas, sem afirmar auditoria Composer atualizada.

## Reprodução isolada

Usar caminhos temporários novos; os runners recusam recursos preexistentes e removem apenas seus containers/rede/volume privados. Não usar envs, credenciais ou serviços de produção.

```bash
ROUTEROS_TASK_LAB=1 DEVICE_READONLY_STATE=/tmp/routeros-integration-new ./scripts/lab/device-readonly/run.sh --filter 'RouterOsTaskIntegrationTest|RouterOsAgentEvidenceTest'
DEVICE_READONLY_STATE=/tmp/routeros-regression-new ./scripts/lab/device-readonly/run.sh
ROUTEROS_BUILD_STATE=/tmp/routeros-build-new ./scripts/lab/routeros-tasks/build.sh
```

O teste integrado mantém fixtures committed para permitir callbacks HTTP reais. Por isso reutiliza os guards existentes de rollback com tabelas preenchidas: ambos recusam perda de aprovação/credenciais e confirmam preservação de dados. O banco descartável é removido pelo runner; não se aplica esse procedimento a banco operacional.

## Implantação futura, limites e rollback

Nenhum deploy/publicação foi realizado. Agent operacional 0.6.5, Lifecycle, R2, ACLs e MikroTik físico de borda permanecem intocados. O cadastro real não é CHR e não foi reclassificado como LAB. O plano CHR anterior é apenas referência de homologação isolada.

Este candidato só executa o simulador. Não há flag a ativar para conectar ao equipamento físico. Uma evolução operacional futura exige escopo separado, revisão, candidata assinada e compatível pelo Lifecycle existente, backup/rollback, ensaio isolado e autorização específica por equipamento. Não alterar o Lifecycle nesta entrega nem distribuir esta imagem de tooling como release operacional.

A primeira consulta física proposta permanece **DEVICE_IDENTITY_READ**, uma consulta lógica/uma sessão, sem retry automático, timeout total proposto de 5 s e somente os dois templates fixos de recursos/identidade. A autorização deverá explicitar equipamento/revisão, tenant, ator/RBAC, Agent/origem, destino literal/porta, usuário de leitura previamente autorizado, referência/revisão da credencial, chave/fingerprint aprovado por canal independente, operação/parâmetros, política/digest, janela UTC, limite de consultas e critérios de interrupção. Nenhum desses valores reais foi descoberto nesta missão.

Interromper por divergência de chave/modelo/versão, perda de autorização, cancelamento, falha de autenticação, timeout, saída sensível/inválida/truncada/excessiva ou indício de impacto. Não consultar inicialmente BGP/rotas completos, configuração, firewall, PPPoE ou conexões; não criar usuário nem modificar direitos/ACL sem autorização separada. Freeze global continua obrigatório.

Riscos residuais: simulador não comprova compatibilidade com hardware, outro release RouterOS, carga de borda, políticas/VRFs/FIB, snapshot atômico ou taxas de contadores. Credencial operacional/distribuição de secrets, assinatura/publicação de release e execução real não foram homologadas. Ator de execução LAB foi tenant admin, sem ampliação de privilégios ou nova delegação. O tempo de transporte não é publicado como métrica operacional. A apresentação Telegram foi validada até outbox/transporte fake, sem entrega externa.

Rollback de desenvolvimento: reverter somente os commits desta integração, preservar alterações locais anteriores, banco, planos, evidências e journal; não usar reset/clean nem migrate:rollback em produção. Sem migration nova a desfazer. Em futura implantação autorizada, restaurar as imagens assinadas anteriores e suspender novos despachos, preservando gerações/leases/outcomes incertos; nunca reexecutar automaticamente uma tentativa com START conhecido ou incerto. A remoção do laboratório não exige nenhuma alteração em equipamentos.

## Arquivos de código alterados

- `agent/src/agent_center/capabilities.py`
- `agent/src/agent_center/routeros7_lab.py`
- `agent/tests/test_routeros7_lab.py`
- `app/app/Agent/Protocol.php`
- `app/app/Conversation/DiagnosticExperience.php`
- `app/app/Conversation/Engine.php`
- `app/app/Diagnostics/Classifier.php`
- `app/app/Diagnostics/Engine.php`
- `app/app/Diagnostics/Normalizer.php`
- `app/app/Diagnostics/Preflight.php`
- `app/app/Http/Requests/Agent/ProtocolRequest.php`
- `app/app/Noc/ReadOnlyLab/RouterOs7/Approval.php`
- `app/app/Noc/ReadOnlyLab/RouterOs7/Integration.php`
- `app/app/Noc/ReadOnlyLab/Ssh.php`
- `app/app/Services/Audit.php`
- `app/app/Telegram/Deliver.php`
- `app/resources/views/diagnostics/evidence.blade.php`
- `app/tests/Feature/RouterOsTaskIntegrationTest.php`
- `app/tests/Unit/RouterOsAgentEvidenceTest.php`
- `scripts/lab/device-readonly/run.sh`
- `scripts/lab/device-readonly/server.py`
- `scripts/lab/routeros-tasks/Dockerfile`
- `scripts/lab/routeros-tasks/browser.cjs`
- `scripts/lab/routeros-tasks/build.sh`
- `scripts/lab/routeros-tasks/central.php`
- `scripts/lab/routeros-tasks/journal-proof.py`
- `scripts/lab/routeros-tasks/launch.py`
- `scripts/test.sh`
