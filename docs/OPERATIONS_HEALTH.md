> Este documento preserva contratos e procedimentos históricos RECOVERY. Para versões e entregas posteriores, consultar o [fluxo consolidado de 18/11/2026](FLUXO_ATUAL_20261118.md) e os fechamentos datados. Kits antigos não substituem releases assinadas e o Lifecycle vigente.

# Operations Health — RECOVERY-7

Tela existente `/operations`, administradores com tenant selecionado, polling leve. Estados VERDE / ATENÇÃO / NO-GO. Cada check inclui componente, motivo fechado, timestamp, valores observados e limite. Ausência de observabilidade nunca equivale a sucesso.

## Sinais funcionais

- Laravel web: atendimento da requisição atual; não é prova independente de processo FPM/edge.
- PostgreSQL: SELECT 1 pelo runtime; falha NO-GO.
- Diagnostic worker: marker atômico após ciclo completo de tenants; boot_id, relógio monotônico, iterações/progresso e idade <15s. Health local também exige PID no namespace correto.
- Laravel queue worker: job OperationsPulse enfileirado aproximadamente a cada 30s pelo scheduler e concluído pelo worker. Marker só após SELECT 1 e conclusão efetiva do job. Idade <90s. Healthcheck `agent-center:queue-health`. Não usa contagem de commits PostgreSQL ou mero PID como prova de progresso.
- Agents: heartbeat <60s, poll <30s, ausências e cobertura; detalhe contém journal/processo/versão/capabilities/freeze coletados. Endpoint próprio do companion registra última comunicação real. Nenhum Agent de laboratório vira presença de produção.
- Task Engine: tasks CLAIMED/RUNNING, attempts ativos vencidos, falhas de hoje e correspondência da geração do attempt com task. Mismatch de fencing é NO-GO; testes negativos continuam verificando rejeição de geração/lease stale.

## Probes externos da infraestrutura

`scripts/collect-operations.py` consulta somente containers fixos deste projeto, HTTPS ready e validação sem credencial do Agent API (422 esperado). Nunca recebe comandos/endpoints do navegador. Produz JSON sanitizado; Laravel só lê essa evidência. Após 90s, dados externos deixam de valer e componentes voltam a ATENÇÃO.

Configurar `deploy/agent-center-operations.service`/timer no host autorizado após deploy; o output usa o mountpoint do volume compartilhado `agent-center_app_storage` inspecionado nesta VPS; confirmar novamente com docker volume inspect ao implantar em outro host. O timer preparado não foi presumido ativo. Não expor Docker socket ao Laravel. Probe de status do container utiliza healthchecks existentes, não apenas estado running.

Nginx: instalar formato `deploy/nginx/host/agent-center-observability.conf` no contexto http e vhost revisado. Log registra exclusivamente timestamp/status/latência; não contém URL, IP, headers, cookies, token ou body. Leitura limitada a 10 mil linhas/5min; HTTP 5xx acima de 5% é NO-GO. Sem amostras é ATENÇÃO. Rotação específica preparada em deploy/nginx/host/agent-center-logrotate.conf (sete dias, 0640, somente arquivo deste projeto); não habilitar logs de payloads para compensar ausência de métricas.

Uma resposta local HTTPS comprova somente o caminho testado; monitor externo em outra rede continua pendente. Nginx e FPM ausentes na cobertura não são rotulados saudáveis pela tela. Logs/error samples podem conter informação confidencial; somente códigos fechados entram na Central.

## Diagnóstico de travamento e recuperação

Heartbeat recente com poll vencido é ATENÇÃO. Loop worker parado vence seu marker e gera NO-GO. Lease vencida é evidência de ausência de renovação, nunca autorização para reexecução cega. Reconcile/journal preservam execução uncertain. Cancel/timeout encerram grupo de subprocesso e aguardam reap; heartbeat usa thread/cliente separados.

Não restaurar journal antigo sobre state atual nem iniciar duas cópias da mesma identidade. Recovery do updater interrompido está em AGENT_UPDATES.md. Não existe dependência de FastAPI ou de um serviço Python na Central.
