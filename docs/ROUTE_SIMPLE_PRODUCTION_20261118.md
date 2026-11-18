# Análise de rota simplificada — produção 18/11/2026

## Problema e comportamento final

O usuário confirmou que seu objetivo é ver a rota e que MTR ou traceroute podem atendê-lo, pedindo simplificar a operação. A investigação confirmou que o aviso de incompatibilidade era **novo**, entregue com SENT, no Agent 0.6.6. A correção anterior resolveu a versão no contrato MTR, mas não tratou a seleção de método: tracert selecionava CAMINHO_TRACEROUTE e o preflight publicado cobre somente MTR. Esse caso também retornava o erro genérico de versão.

Para Agents 0.6.5/0.6.6 ativos com capability MTR confirmada, o pedido coloquial de tracert pode selecionar MTR_ICMP para cumprir o objetivo de análise de rota. A seleção é determinística, não concede autorização nem cria tarefa. O preflight existente continua obrigatório. A confirmação Telegram agora diz expressamente **“Vou usar MTR ICMP”** ou o método efetivamente planejado. MTR TCP/443 e traceroute ICMP mantêm identificações próprias.

O usuário não precisa saber o nome da ferramenta: “veja a rota até 8.8.8.8” e “consegue fazer um tracert para 8.8.8.8?” selecionam o mesmo objetivo com MTR no Agent atual. Falta de destino continua pedindo o alvo pelo fluxo existente. Sem prova de autorização não há confirmação operacional executável; nenhum pedido contorna RBAC, tenant, ACL, helper ou confirmação.

Uma exigência como “somente traceroute”, “apenas traceroute” ou “não use MTR” mantém o método solicitado. Traceroute TCP também não é convertido em MTR. Se a autorização específica desse método não puder ser comprovada, o motivo agora é `preflight_method_unsupported`, sem culpar indevidamente a versão e sem executar substituto. Agents legados não recebem automaticamente esse fallback novo.

## Publicação limitada

Continuação da correção autorizada da Central, incorporando a orientação explícita do usuário sobre escolha automática de método. Não houve atualização do Agent, acesso a equipamento, concessão de permissões, migration, novo provider ou integração. Não foi executado tracert/MTR real nem enviada mensagem sintética ao Telegram.

As imagens foram derivadas das imagens efetivamente implantadas da correção Telegram, alterando somente sete classes: IntentParser, IntentPlanning, Preflight, OperationalCapabilities, PathReadiness, PlanningReply e Engine. Em Engine foi alterada somente a chamada da confirmação Telegram, preservando a árvore de produção sem promover integrações anteriores de laboratório. Preflight também foi preparado sobre a cópia da produção. As outras cinco classes tinham a mesma base do checkout.

Imagens finais:

- `agent-center-central:route-simple-web-20261118`
- `agent-center-central:route-simple-queue-20261118` para worker e scheduler
- `agent-center-central:route-simple-diagnostic-20261118`

O overlay existente `deploy/compose.telegram-repair.yaml` foi atualizado somente nas referências de imagem. Web/worker/diagnostic-worker/scheduler foram recriados pelo wrapper Compose existente. PostgreSQL, Nginx, volumes, configuração, redes, flags, credenciais e dados de negócio foram preservados.

## Validação

- **138 casos, 1.003 asserções, nenhuma falha e dois skips opcionais SSH**, em PostgreSQL TLS isolado, rede interna e credenciais sintéticas. Inclui Telegram, preflight real, seleção de intenção/método e capacidades; prova de que o pedido coloquial espera autorização antes de pedir confirmação, não cria trabalho antecipado, preserva método estrito, tenant, revogação e idempotência. Execução em `/tmp/agent-center-route-simple-tests.log`.
- As próprias imagens candidatas Web/queue passaram harness sem rede com seleção de rota/tracert para MTR, preservação de “somente traceroute” e traceroute TCP, identificação do método na confirmação e bloqueio de método sem contrato. O fixture inicial não tinha status active; foi corrigido no harness, sem relaxar a aplicação.
- Consulta read-only no worker implantado, usando o Agent real do tenant 1, confirmou MTR_ICMP para os dois pedidos comuns e CAMINHO_TRACEROUTE para “somente traceroute”. Foi chamada somente a seleção de Profile, sem preview, consulta de preflight ou tarefa no Agent.
- Os hashes das sete classes no worker publicado correspondem à candidata limitada. Pint, Compose config, sintaxe do rollback e `git diff --check` aprovados.
- Web, worker e diagnostic-worker healthy; scheduler em execução. Login e readiness HTTPS 200. Verificações TLS positivas/negativas, PDO verify-full, job técnico database→worker→cache, saúde do diagnostic-worker e CSS/JS: PASS.

## Backup e rollback

Backup completo local anterior a esta publicação: **20261118T182449Z**, concluído pelo procedimento existente sem prune, com inventário do dump e integridade da geração aprovados. Nenhum envio externo nesta ação. A restauração isolada da geração anterior durante esta mesma manutenção está documentada no [relatório Telegram](TELEGRAM_REPAIR_PRODUCTION_20261118.md); não afirmar que esta geração adicional passou por novo restore completo.

Material restrito fora do Git em `backups/route-simple-production-20261118/`: imagens/inventário anterior e posterior, fontes candidatas, evidências e rollback. Imagens preservadas `route-simple-rollback-web-20261118`, `route-simple-rollback-queue-20261118` e `route-simple-rollback-diagnostic-20261118`. O `rollback.sh` validado repõe o overlay para essas imagens e recria somente os quatro serviços, seguido de deploy-precheck. Não foi executado, porque as validações passaram. Não restaurar dump, executar migrations down, apagar contexto ou reenviar UNKNOWN como rollback.

## Limites preservados

Selecionar MTR não comprova autorização para o destino. O Agent ainda precisa responder ao preflight com prova válida, helper compatível, política vigente e heartbeat. A confirmação segue vinculada ao plano e ao alvo. A instabilidade de conexão de saída e sua recuperação limitada, registradas no relatório Telegram, não foram alteradas por esta publicação. Não houve teste externo de ponta a ponta com envio real de resposta: o próximo pedido normal do usuário usará o fluxo atualizado.
