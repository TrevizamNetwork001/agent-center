# Task Engine — RECOVERY-3

O Laravel cria planos e tarefas determinísticos em PostgreSQL. Não há FastAPI, LLM nem shell fornecido por usuário.

## Estados

- Diagnostic: PENDING → RUNNING → COMPLETED / FAILED / CANCELLED.
- Task: QUEUED → CLAIMED → RUNNING → COMPLETED / FAILED; CANCELLED e EXPIRED são terminais.
- Attempt: CREATED (reservado no contrato), CLAIMED → RUNNING → COMPLETED / FAILED / EXPIRED.
- Steps: PENDING, QUEUED, RUNNING, COMPLETED, FAILED, CANCELLED, EXPIRED, BLOCKED, SKIPPED. BLOCKED significa dependência ou capability indisponível; SKIPPED representa etapa opcional dispensada.

## Concorrência e leases

Agent fixo por diagnóstico. Claim trava o Agent, depois diagnóstico e task; somente uma task CLAIMED/RUNNING por Agent. Worker usa FOR UPDATE SKIP LOCKED no diagnóstico. Operações start/renew/result travam diagnóstico → task → attempt, nessa ordem. Unique(step_id), unique(task_id,generation) e índice único parcial de attempt ativo protegem materialização/retry concorrentes.

Cada claim emite execution_id UUID, generation crescente e lease_token aleatório retornado uma vez; banco armazena somente SHA-256. Lease 45 segundos, renew recomendado a cada 10 segundos; timeout de capability entre 1 e 20 segundos. O relógio autoritativo é clock_timestamp() do PostgreSQL.

Start exige attempt atual e lease válida; repetição no mesmo attempt é idempotente. Result antes de start, generation antiga, token incorreto ou primeiro resultado após expiração retorna 409. Resultado aceito tem digest canônico: repetição equivalente recebe confirmação, conteúdo diferente retorna conflito. Reconcile nunca autoriza execução.

CLAIMED expirado pode voltar para QUEUED, no máximo duas gerações, pois nenhum START foi autorizado. RUNNING expirado termina EXPIRED e **não é reexecutado automaticamente**. Isso privilegia prevenção de duplicidade diante de outcome desconhecido; não promete exactly-once de efeitos externos em rede. Journal local impede replay da ação, mesmo com perda de resposta de START. Repetição manual exige novo diagnóstico e nova autorização.

Prazo total: 300 segundos; Agent offline ou fila sem progresso termina sem inventar sucesso. Worker retry materializa uma única task por step e um único plano/conclusão por diagnóstico.

## Plano, execução e conclusão

Snapshot inclui versão de Profile/Skills, target normalizado, schemas, waves, condições, thresholds e SHA-256 de JSON canônico. Planos e conclusões são append-only: grants SELECT/INSERT, triggers PostgreSQL e bloqueio no Model. Evidências estruturadas são preservadas por etapa; classificações são regras Laravel.

Cancelamento grava intenção auditada; tasks ainda não iniciadas e steps pendentes são cancelados. Tarefas em execução recebem cancel via poll/renew/reconcile; Agent mata e aguarda o grupo de processos. Worker aguarda confirmação ou lease expirar antes da conclusão CANCELLED/INCONCLUSIVO. Resultados anteriores permanecem intactos.

## Operação e rollback

Serviço diagnostic-worker executa `php artisan agent-center:diagnostic-worker`, ciclo 2s por tenant ativo. Sem credenciais de migrator. Contexto de tenant é limpo em finally. Não usa commit de banco como sinal de saúde.

Antes de deploy: backup e imagem anterior. Migração é aditiva. Em rollback operacional, pare diagnostic-worker e restaure a imagem recovery2 de web/worker; mantenha tabelas e dados novos para análise. O Agent anterior não solicita task_protocol:1 e recebe task:null. Não execute migrate:rollback em produção com evidências existentes. Em laboratório vazio, down da migration é validado pela suíte.
