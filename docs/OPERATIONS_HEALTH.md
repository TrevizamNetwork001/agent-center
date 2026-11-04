# Saúde operacional

`/health/live` comprova Laravel vivo; `/health/ready` verifica PostgreSQL. `/operations`, restrito a administradores com tenant selecionado, mostra conexão web/banco, marker funcional de diagnostic-worker, Agents online/offline, idade de heartbeat/poll, tasks ativas, leases vencidas e attempts FAILED/EXPIRED.

Worker escreve storage/framework/diagnostic-worker.json atomicamente apenas depois de completar ciclo PostgreSQL de todos os tenants ativos. Marker tem boot_id do kernel, PID, hrtime(true), iterations e progress. Saúde exige mesma inicialização e loop monotônico com idade <15s. PID é verificado apenas no namespace do próprio container. Docker health usa `php artisan agent-center:worker-health` com SELECT 1 adicional. Falha ou travamento deixa marker vencer. Iterações sem tasks são trabalho legítimo de reconciliação; contador progress separa diagnósticos examinados de iterações.

Queue worker Laravel existente continua separado; diagnostic-worker agenda/reconcilia diagnósticos. Restart seguro com transações, locks e materialização idempotente. Stop por SIGTERM/SIGINT, intervalo 2s. Não há dependência de FastAPI nem de timestamp de COMMIT.

Heartbeat Python tem cliente/thread próprios; polling tem outro cliente; execução usa thread de controle e subprocesso isolado. Requisição lenta de execução, escrita de journal/status e subprocesso não compartilham locks com heartbeat. Leases 45s, renew 10s, exec <=20s, polling nominal 5s, heartbeat 20s. Sob falha de rede, não há garantia de sucesso de heartbeat; processo permanece controlado e resultados não são inventados.

Subprocesso é argv fixo do próprio Agent, stdin JSON limitado, dispatcher fechado. Ping/traceroute têm argv fixo sem shell. Timeout/cancel/lease perdida encerram o grupo de processos e aguardam reap. Journal nunca recebe lease_token; arquivo privado state/leases/<UUID>.json persiste a credencial somente até confirmação/expiração. Backup do state contém secrets e exige armazenamento privado/criptografado externo.
