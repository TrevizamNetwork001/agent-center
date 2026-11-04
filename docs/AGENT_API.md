# Contrato vigente — RECOVERY-7

Enrollment agora é HTTP 202 com aprovação obrigatória; nenhum bearer antes de aprovação. `enrollment-status` recebe receipt privado via POST e emite credencial uma única vez após revisão. Código e receipt não são intercambiáveis. Ver AGENT_ONBOARDING.md. Runtime 0.5.3 implementa retomada; enrollment do cliente antigo 0.5.2 não deve ser usado para novos cadastros.

Heartbeat aceita versão semver e telemetria de schema fechado; versão desconhecida é evidência de inconsistência e não ONLINE. `operations/claim`/`operations/report` são restritos ao Agent autenticado e executam somente o protocolo estreito de manutenção descrito em AGENT_UPDATES.md. Capabilities de diagnóstico continuam na allowlist original; não se anunciam capacidades operacionais fictícias. Bearer, receipt e código nunca são query strings ou audit metadata.

Abaixo está a evolução histórica; o enrollment imediato RECOVERY-2 foi substituído.

# Agent API v1 — RECOVERY-2

Base `/api/v1/agent`; JSON em POST e Accept application/json. Não há HMAC/nonce/bridge. HTTPS é obrigatório fora de loopback. Enrollment code e Bearer nunca devem aparecer em URLs, logs, Git ou comandos de shell. Respostas com credenciais são no-store e não usam flash/session.

## Enrollment

Administrador de tenant ou platform_admin com tenant selecionado cria enrollment na página Agents. O nome é validado; o tenant vem do contexto autenticado, nunca do body. Código `UUID.secret-hex-256-bits`, expirando por padrão em 900 s (configuração validada 60–1800 s), guardado somente como SHA-256. UI o exibe somente na resposta de criação, sem persistência em sessão.

`POST enroll`: `code`, `hostname` (até 253, caracteres de hostname), `version="0.5.2"`, `capabilities` opcional (até 14, mas apenas seis nomes conhecidos são permitidos). Retorna 201 com agent_id, token (uma única vez), version e policy. Linha do enrollment é bloqueada e consumida na mesma transaction da criação do Agent/auditoria. Código incorreto: 401; já consumido: 409; expirado: 410; tenant desabilitado: 403; schema inválido: 422.

Perder a resposta depois de o servidor consumir o código não autoriza novo uso. O cliente não repete enrollment automaticamente. Operador deve revogar a identidade órfã e criar novo enrollment; não há endpoint que revele token já emitido.

## Agent autenticado

`Authorization: Bearer UUID.secret-hex-256-bits`. Central faz lookup restrito por UUID/hash, compara hash com hash_equals, resolve tenant e limpa o contexto em finally. Token incorreto/ausente: 401. Agent revogado ou tenant disabled: 403. Agent/tenant IDs no body são proibidos: identidade vem exclusivamente da autenticação.

| Endpoint | Body válido nesta fase | Resposta |
|---|---|---|
| heartbeat | version=0.5.2 | 200 status=ok e policy; atualiza last_heartbeat_at |
| capabilities | capabilities: array de nomes conhecidos únicos | 200 status=ok e capabilities; evento somente se conjunto anunciado mudar |
| claim | objeto vazio | 200 task=null e policy; atualiza last_poll_at |
| start | task_id UUID, execution_id UUID, lease_token 32–128 caracteres | 409 error=no_active_task, retryable=false |
| renew | mesmos campos de start | mesmo 409; não estende lease fictício |
| result | mesmos campos de start; result arbitrário não é aceito | mesmo 409; nenhum resultado operacional é reconhecido |
| reconcile | executions: array (inclusive vazio), máximo 100; cada item execution_id/task_id UUID e state permitido | 200 executions com execution_id/disposition=hold, tasks_enabled=false |

Estados de reconcile: claimed, started, executing, completed, uncertain, result_pending. Nenhuma orientação autoriza replay/execução. Não existem tabelas de tasks, criação de tarefas, leases reais, Diagnostics ou Task Engine nesta fase. start/renew/result são contratos mínimos definidos e testados, não implementação operacional disfarçada. A fase seguinte deverá especificar o payload completo antes de habilitar tasks.

Policy: schema_version=1, agent_id, device_operations_frozen=true, tasks_enabled=false, allowed_capabilities (os seis nomes documentados), heartbeat_interval=20, poll_interval=5. O runtime mantém intervalos locais validados e não aceita policy como autoridade arbitrária.

## Rate limits

- Origem: 1200 requests/min por IP, antes de autenticação; inclui requisições inválidas.
- Enrollment: 10/min por IP, separado de heartbeat/poll.
- API autenticada: 120/min por Agent; default heartbeat 3/min + poll 12/min deixa margem confortável.
- 429 inclui Retry-After. Cache database é compartilhado; respostas inválidas não revelam credenciais. Python usa backoff local limitado, sem retry de enrollment.

## Persistência e auditoria

Agents têm UUID, tenant, nome, hostname, status active/revoked, version, token_hash, datas, capabilities/metadata JSONB e revoked_at. Status active representa autorização, não comprovação de conectividade. IP é intencionalmente NULL por minimização de dados. Metadata do cliente é proibida; Central grava protocolo 1.

Agents e enrollments usam scope, Policy e FORCE RLS. Busca anônima de enrollment e lookup de Agent permitem somente o par exato ID/hash, e o contexto temporário é limpo. Runtime não possui tabelas nem pode alterar DDL/RLS. RLS ainda confia no código que estabelece GUCs; não protege contra SQL arbitrário executado por aplicação comprometida.

Eventos: agent.enrollment_created, agent.enrolled, agent.capabilities_updated, agent.revoked. UUIDs podem ser metadados validados; nenhum token/code/hash é registrado nos eventos. Heartbeat é timestamp/métrica, não um evento por request. Rotação de token não foi implementada; revogar e reenrolar gera identidade/token novos.

UI: todos os papéis autenticados podem ver Agents do próprio contexto; somente platform_admin/tenant_admin criam enrollment ou revogam. Seleção de tenant por platform_admin é explícita. Requests web usam Auth/Session/CSRF nativos; Agent API é stateless, separada das sessões humanas.

## RECOVERY-3 — tarefas negociadas

Os oito endpoints mantêm caminhos e autenticação. Capabilities aceita task_protocol:1; claim exige task_protocol:1 e capacity 0/1. Clients sem opt-in continuam com task:null e tasks_enabled:false. Policy schema_version=1 acrescenta task_protocol=1 e habilita execução apenas no cliente opt-in.

Claim retorna task_id, execution_id, generation, lease_token (uma vez), lease_seconds=45, renew_interval=10, timeout, capability e input. Poll inclui cancel_execution_ids. Start/renew/result usam os quatro campos de identidade/lease; result adiciona result estruturado. HTTP 409 rejeita attempt desconhecido/stale/expirado ou conflito. Status ok é confirmação; cancel_requested/cancel:true exige parar. Resultado equivalente já confirmado retorna duplicate:true. Reconcile retorna hold, cancel, expired, acknowledged ou resend_result; nunca libera reexecução. 422 indica payload fora do schema. Limites origin/enrollment/Agent permanecem 1200/10/120 por minuto.
