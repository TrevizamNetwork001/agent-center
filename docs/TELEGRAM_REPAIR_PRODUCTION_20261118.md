# Telegram: correção publicada em 18/11/2026

**Continuação posterior:** o usuário confirmou que aceita MTR ou traceroute para o objetivo de ver a rota. O [fluxo simplificado publicado](ROUTE_SIMPLE_PRODUCTION_20261118.md) trata a seleção de método e a confirmação explícita; suas imagens substituem as referências finais abaixo. A validação de versão deste relatório não bastava para o pedido específico tracert, cujo preflight não era suportado.

## Escopo e autorização

O usuário autorizou expressamente o deploy limitado com backup e rollback. A publicação partiu das imagens efetivamente em execução, mantendo as dependências, assets, configuração, identidade, filas e controles existentes. Não foi promovido o checkout inteiro. Foram alterados Client, Deliver, Preflight e PreflightController em Web/worker/scheduler; a imagem reduzida do diagnostic-worker recebeu somente Deliver e Preflight, que já existiam nela.

Não houve migration, alteração de grants, ACLs ou flags operacionais, atualização do Python Agent, acesso a equipamentos, envio de mensagens sintéticas, chamada LLM ou execução de diagnóstico. PostgreSQL e Nginx conservaram seus IDs e horários de início. Os workers continuaram seu processamento normal, inclusive tarefas técnicas de saúde.

## Backup e rollback

Backup completo local: geração **20261118T181019Z**, sem prune. Dump custom, configuração, storage e material confidencial foram guardados pelo procedimento existente, com verificação de integridade. O primeiro intento não conseguiu copiar a chave LLM por permissão; o backup completo posterior foi concluído com acesso autorizado restrito, sem exibir secrets. Não houve replicação externa nesta ação.

O dump foi restaurado em PostgreSQL descartável, em rede Docker interna separada e com credenciais sintéticas: **51 tabelas públicas, 43 tabelas FORCE RLS, 24 migrations e um tenant**. A verificação confirmou trigger de auditoria imutável e ausência de acesso a conversas, Agents e outbox sem contexto de tenant, inclusive pelo owner de laboratório com FORCE RLS. Nenhum restore ocorreu sobre produção. Container e rede do restore foram removidos.

Imagens anteriores retidas:

- `agent-center-central:telegram-repair-rollback-web-20261118`
- `agent-center-central:telegram-repair-rollback-queue-20261118`
- `agent-center-central:telegram-repair-rollback-diagnostic-20261118`

Material operacional restrito e ignorado pelo Git em `backups/telegram-repair-production-20261118/`: patch exclusivo da produção, quatro fontes candidatas, Dockerfiles, inventário anterior/posterior, evidências e `rollback.sh`. O script, validado com `bash -n`, restaura o overlay para as imagens anteriores e recria somente Web/worker/diagnostic-worker/scheduler, com verificação de saúde. Não restaura dump, não reverte migrations e não remove filas, vínculos ou volumes. Não foi necessário executá-lo.

## Timeout residual e ajuste final

A primeira candidata corrigiu IPv4 e a rejeição indevida do Agent 0.6.6. A inspeção pós-deploy ainda reproduziu um timeout. Estatísticas sanitizadas mostraram `ConnectTimeoutException`, cURL 28, `http_code=0`, `request_size=0`, `size_upload=0`, `connect_time=0`, `pretransfer_time=0` e ausência de IP conectado. Portanto, nesse caso específico nenhum pedido saiu pela conexão.

O cliente final classifica esse timeout como TRANSPORT_UNAVAILABLE somente quando **todos** os cinco campos necessários estão presentes e zerados. A ausência de estatísticas, conexão já estabelecida, upload ou início de pedido mantém DELIVERY_UNKNOWN. Deliver aplica as mesmas cinco tentativas, com intervalo de 60 segundos e revalidação integral de tenant/identidade/autorização. Nenhum timeout ambíguo de sendMessage é reenviado automaticamente.

As imagens finais são:

- Web: `agent-center-central:telegram-repair-web-v2-20261118`
- Worker e scheduler: `agent-center-central:telegram-repair-queue-v2-20261118`
- Diagnostic-worker: `agent-center-central:telegram-repair-diagnostic-20261118`

O overlay `deploy/compose.telegram-repair.yaml`, aplicado pelo wrapper existente, contém somente essas referências de imagem. Nenhuma porta, mount, rede ou variável de ambiente foi ampliada. O migrator não foi executado.

## Validação final

- Suíte anterior da correção: 117 casos/794 asserções, zero falhas e dois skips opcionais SSH. Após ampliar a classificação do timeout: **12 testes/29 asserções**, incluindo campos ausentes, pedido iniciado, conexão estabelecida, limite de tentativas, tenant cruzado e revogação, todos aprovados em PostgreSQL TLS isolado.
- As próprias imagens Web/queue finais passaram um harness com transporte simulado, TLS obrigatório, IPv4, bloqueio de redirects, erros DNS/conexão e distinção entre timeout anterior/posterior ao pedido. Não houve envio ao Telegram por esse harness.
- Hashes dos quatro arquivos no worker publicado iguais aos da candidata limitada. Pint, Compose config, sintaxe do rollback e `git diff --check` aprovados.
- Web, worker e diagnostic-worker healthy; scheduler em execução. HTTPS `/login` e `/health/ready`: 200. PDO verify-full, testes TLS positivos/negativos, job técnico database→worker→cache, saúde do diagnostic-worker e entrega de CSS/JS pelo Nginx: PASS.
- Consultas reais finais no worker: três getMe e três getWebhookInfo. Cinco responderam OK; uma falha antes da conexão foi corretamente classificada TRANSPORT_UNAVAILABLE e a consulta seguinte respondeu OK. As três consultas webhook retornaram zero updates pendentes e nenhum last_error_date. Essas são leituras administrativas; não houve sendMessage de validação.
- Mensagem antiga preservada em UNKNOWN com uma tentativa. Os cinco registros UNKNOWN antigos continuaram preservados. Na consulta final havia 57 SENT; o aumento observado em relação à inspeção inicial ocorreu durante o processamento normal do sistema, sem reenvio manual ou sintético.

**Limite atual:** a instabilidade de conexão de saída ainda existe. A correção torna recuperáveis as falhas comprovadamente anteriores ao envio; não prova eliminação da causa externa/DNS nem entrega infalível. Falhas persistentes esgotam cinco tentativas e ficam visíveis. Entregas ambíguas continuam exigindo a revisão administrativa existente.

O aceite de versão 0.6.6 não autoriza um tracert automaticamente: política, helper correspondente, heartbeat, prova autenticada e confirmação operacional continuam obrigatórios. Não foi executado um tracert real para validar esta publicação.
