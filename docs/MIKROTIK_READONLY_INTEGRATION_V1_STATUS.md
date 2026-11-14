# MikroTik Read-Only Integration V1 — avaliação de prontidão

Registro histórico da avaliação documental `3955be0`. A implementação posterior e suas evidências estão em [MIKROTIK_READONLY_INTEGRATION_V1_IMPLEMENTED.md](MIKROTIK_READONLY_INTEGRATION_V1_IMPLEMENTED.md).

Data: 2026-11-14. Base Git: `487ed69`, após `25ff80a`.

**BLOCKED: o fluxo RouterOS existente não passa pelo Task Engine nem pelo Python Agent. A integração solicitada não foi implementada nesta rodada.** Este documento não é evidência de conclusão e não autoriza execução. Não existe candidata de Agent produzida por esta rodada.

## Contexto corrigido

O MikroTik já cadastrado é um equipamento físico de borda que transporta tráfego de produção. Não é CHR nem laboratório. Não alterar seu ambiente no inventário para satisfazer gates de laboratório. O documento `MIKROTIK_CHR_REAL_HOMOLOGATION_PLAN_V1.md` é somente referência de homologação isolada, sem autorização sobre esse equipamento.

Nenhuma conexão, descoberta de identidade, consulta, cadastro de usuário, alteração de ACL ou configuração foi efetuada no equipamento. Device freeze permanece obrigatório. Nenhuma mudança de produção, Agent operacional 0.6.5, Lifecycle ou R2 integra esta entrega documental.

## Bloqueios técnicos confirmados no código

| Trecho | Comportamento existente | Lacuna para o aceite solicitado |
| --- | --- | --- |
| `app/app/Noc/Planning.php` | Intenções de device retornam indisponibilidade; não planejam tarefas | Planejamento tipado LAB, seleção de equipamento e confirmação vinculada |
| `app/app/Noc/ReadOnlyLab/RouterOs7/Query.php` | Decripta chave via SecretVault, chama transporte PHP, grava resultado SIMULATED com `task_eligible=false` | Transporte e execução devem pertencer ao Python Agent |
| `app/app/Noc/ReadOnlyLab/Ssh.php` | Laravel abre SSH em `127.0.0.1`; valida identidade e executa templates fixos | Não atende à proibição de SSH a partir do Laravel no novo fluxo |
| `app/app/Noc/ReadOnlyLab/RouterOs7/Approval.php` | Aprovação identifica `CENTRAL_LOOPBACK_SIMULATOR_ONLY`; Agent é vínculo administrativo | Não autoriza START de AgentTask nem prova autorização efetiva no Agent |
| `app/app/Diagnostics/Preflight.php` | Contrato de permission/exchange específico para MTR/0.6.5 | Falta decisão vinculada a tenant, usuário, equipamento, executor, consulta e identidade SSH |
| `app/app/Agent/Protocol.php` | Allowlist de execução não inclui as seis operações de device | Negociação explícita e exclusivamente LAB sem ampliar allowlist operacional |
| `agent/src/agent_center/policy.py` | Rejeita capabilities `device_` | Falta política de laboratório isolada; não remover a proteção global |
| `agent/src/agent_center/execution.py` | Rejeita tarefas `device_`, preserva journal/lease/cancelamento | Falta integração controlada ao executor existente |
| `agent/src/agent_center/` | Não contém transporte/parser RouterOS | Portar e testar o adaptador, sem executor paralelo, CLI arbitrária ou privilégios adicionais |
| `app/tests/Feature/MikrotikProtocolLabTest.php` | Seis operações simuladas; teste exige `AgentTask::count() === 0` | Esse PASS não comprova o fluxo pelo Task Engine |

Inventário, contratos Device Analysis, SecretVault, RBAC, FORCE RLS, auditoria, Agent API e journal existentes devem ser reutilizados. O transporte PHP anterior continua no código; nenhuma alegação de remoção ou migração é feita aqui. O Lifecycle existente verifica assinatura/compatibilidade e fica fora de alterações desta missão.

## Condições para considerar a integração concluída

É necessário implementar e comprovar, sem usar registros ou credenciais operacionais:

1. Conversation Engine e Intent Planning dos dois canais selecionam um equipamento LAB do tenant e exatamente uma das seis operações homologadas. Não substituem por ping, HTTP ou outra operação.
2. Preflight efetivo distingue ALLOWED, UNKNOWN, DENIED e STALE; autorização expira e vincula tenant, ator/RBAC, equipment_id/revisão, Agent, capability, digest canônico de parâmetros, destino literal/porta, identidade SSH, referência/revisão de credencial, política/revisão, direitos e janela. Um resultado de simulador não libera device freeze operacional.
3. Confirmação operacional é explícita e vinculada ao mesmo conteúdo. Alterar qualquer vínculo invalida a confirmação. Revalidar no claim, START e imediatamente antes de abrir a conexão; tratar revogação e concorrência entre essas fases.
4. Task Engine existente materializa plano/step/task/attempt e entrega somente ao Agent LAB correto. Uma chave privada nunca entra em input persistido, journal, resultado, auditoria ou conversa. O acesso ao cofre deve ter contexto de tenant e credencial e não permitir leitura cruzada.
5. Python Agent não-root executa somente templates fixos, parâmetros/schema fechados e pinning antes da autenticação; sem shell, scripts, PTY, forwarding, TOFU ou comandos de LLM. Preserva exclusão, deadline, limites de saída, cancelamento e journal sem replay após START incerto.
6. Rede descartável sem uplink, porta publicada, rota para gerenciamento ou credencial operacional. Destino compilado/validado em loopback, identidade controlada e fixtures explícitas. Isolamento verificado sem sondar redes externas.
7. Agent devolve evidência estruturada validada e vinculada à solicitação. Nada simulado pode ser real/operacional ou prova de compatibilidade com hardware.
8. Testes negativos e positivos das seis operações pelo fluxo completo: autenticação, pinning divergente, truncamento, timeout, parâmetros inválidos, mudança de inventário/política/ator/destino, freeze, UNKNOWN/DENIED/STALE, RBAC, dois tenants/FORCE RLS, duplicidade, concorrência e cancelamento. Cobrir Web e Telegram sem enviar mensagens externas.

Nenhum destes itens está marcado como entregue por esta avaliação. O resultado completo Conversation → Task Engine → Python Agent → SSH simulado → resultado Web/Telegram continua **NÃO COMPROVADO**.

## Primeira consulta futura ao equipamento físico

Somente após integração concluída, revisão da candidata, homologação isolada, backup/rollback e autorização operacional específica, preparar **uma consulta `DEVICE_IDENTITY_READ`**. A aprovação do laboratório não autoriza essa consulta. Não atualizar o Agent operacional nem desabilitar globalmente o freeze para executá-la.

Documento de autorização futuro, com identidade do aprovador, ID e timestamp, deve conter todos os campos abaixo; valores continuam pendentes e não devem ser inferidos do cadastro:

| Campo | Requisito |
| --- | --- |
| Equipamento | equipment_id, revisão e confirmação de que é a borda física de produção |
| Tenant e ator | tenant_id, usuário solicitante/aprovador e RBAC efetivo |
| Origem | Agent ID, versão assinada/compatível e origem/interface autorizada |
| Destino | IP literal de gerenciamento e porta SSH exatos |
| Credencial | Usuário de leitura previamente autorizado, referência/revisão no cofre e direitos efetivos atestados; nunca secret no documento |
| Identidade SSH | Chave pública/fingerprint aprovados por canal independente, origem e data da verificação; sem TOFU |
| Escopo | Somente `DEVICE_IDENTITY_READ`, parâmetros vazios e templates fixos de identidade/recursos homologados |
| Janela | Início/fim UTC e validade da confirmação; sem renovação automática |
| Orçamento proposto | Uma consulta lógica, uma sessão, sem retry automático; no máximo duas leituras fixas e timeout total de 5 s; limites sujeitos à aprovação explícita |
| Política | Revisão e digest do plano, Preflight ALLOWED recente e autorização específica compatível com freeze |
| Interrupção | Qualquer divergência de chave/modelo/versão, mudança de vínculo/política, falha de autenticação, timeout, saída inválida/truncada/excessiva, cancelamento ou indício de impacto encerra a tentativa |

Objetivo: nome autorizado, modelo, versão RouterOS e informações básicas do contrato. O parser atual aceita exatamente RouterOS `7.16.2 (stable)`; versão do equipamento real não foi consultada nem confirmada nesta rodada. Divergência exige revisão offline do contrato, nunca alteração do equipamento para satisfazer o parser.

Não incluir BGP completo, tabela de rotas completa, configuração, firewall, PPPoE ou conexões. Não criar usuário nem alterar direitos no MikroTik: isso exige autorização separada. Erro de leitura não permite escalada automática de privilégios ou método alternativo.

Guardar somente evidências estruturadas sanitizadas, vínculos/digests, timestamps e resultado da tentativa. Informação não autorizada/inesperada deve rejeitar a resposta sem preservar console bruto. Comunicar bloqueio em linguagem simples, preservando o tom da conversa, sem inventar dados nem sugerir que houve conexão.

## Riscos e rollback

Simulador não certifica hardware, formato de outro RouterOS, desempenho sob tráfego, direitos efetivos no equipamento ou compatibilidade com a borda real. Um pin correto autentica a chave, não concede autorização. Consultas de leitura também consomem recursos. O orçamento deve ser validado pelo responsável pelo equipamento.

Esta rodada não altera runtime, schema, catálogo, flags, políticas ou dados operacionais. Rollback documental: reverter somente o commit desta avaliação; não usar reset/clean, não tocar alterações locais anteriores e não executar migrate:rollback em produção. Recursos descartáveis do runner são removidos pelo próprio runner; chaves efêmeras em `/tmp` ficam fora do Git. Uma futura candidata deve ter rollback próprio preservando banco, journal e evidências, sem replay de operações incertas e sem publicação nesta missão.

## Verificações executadas nesta rodada

Os resultados abaixo são verificações de base, anteriores a qualquer mudança de runtime. Não representam testes da integração ausente.

| Verificação | Resultado observado |
| --- | --- |
| Runner `scripts/lab/device-readonly/run.sh --filter 'MikrotikProtocol\|DeviceReadOnly\|PreflightRealFlow'`, estado exclusivo em `/tmp/agent-center-mikrotik-integration-baseline-20261114` | PASS: 39 testes, 514 asserções, migrations e rollback no PostgreSQL descartável |
| Isolamento e limites SSH do mesmo runner | PASS: rede Docker internal, sem rota default, transporte loopback; rejeição de PTY, shell e forwarding nos endpoints simulados |
| Python Agent, `python -m unittest discover -s tests -q`, imagem `agent-center-python-tools:0.5.2`, rede none, UID/GID 65534, fonte somente leitura | PASS: 185 testes; não atualiza Agent operacional |
| Mesma suíte Python no host como root | 185 testes, um erro `self_check_permission_denied` em `test_real_packaged_dry_run_and_exit_codes`; não reproduzido no tooling não-root |
| Tentativa em imagem LAB com Python 3.11 | 185 testes, um erro no self-check empacotado; ambiente incompatível com requisito Python >=3.13, não usado como aceite |
| Pint, imagem Laravel de tooling, `php vendor/bin/pint --test` | FAIL preexistente: 377 arquivos, três problemas em `app/Web/AuditPresentation.php`, `tests/Feature/AgentManagedSettingsTest.php`, `tests/Support/LayoutAuditSnapshotTest.php` |
| Ruff, imagem Python de tooling, `ruff check --no-cache .` sobre Agent | FAIL preexistente: I001 em `src/agent_center/heartbeat.py` e `src/agent_center/update/remote.py` |
| Build, imagem `node:24-bookworm-slim`, rede none, `npm run build` | FAIL preexistente: dependência nativa `@rolldown/binding-linux-x64-gnu` indisponível em node_modules; também ausente fallback WASI. Lockfile e dependências não alterados |
| `git diff --check` inicial | PASS |
| Fluxo de integração novo, regressão Laravel completa e QA de interface | NÃO EXECUTADOS; não há implementação nova para homologar |

Composer do laboratório informou que não conseguiu consultar `repo.packagist.org` na rede isolada; usou dependências já presentes e concluiu instalação/autoload. Isso não equivale a uma auditoria atualizada de advisories.

Somente este documento e o resumo de evidências desta rodada são candidatos a commit. As alterações locais anteriores no Agent, Lifecycle, interface, deploy e demais documentos permanecem fora do commit. Os números históricos de 54 testes/1.281 asserções não foram reutilizados como resultado desta rodada.
