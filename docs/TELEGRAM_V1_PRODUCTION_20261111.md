# Telegram V1 — publicação autorizada em 2026-11-11

Autorização: operador confirmou publicação com todos os testes, backup e rollback nesta sessão. Candidato original: `0a2d71e`, evidências LAB: `526b7c6`.

A imagem candidata deriva de `agent-center-central:natural-conversation-v1-20261111` e acrescenta 30 arquivos de runtime. Auditoria e navegação são integradas aditivamente aos arquivos realmente publicados. A rota de permission-check de Observability, ausente na imagem anterior, permanece ausente; nenhuma mudança de Observability é publicada. O checkout de origem mantém sua rota anterior para a regressão de funcionalidades completas.

Web e worker de fila usam a mesma imagem Telegram. O worker recebe somente a conectividade de saída e a chave LLM necessárias ao canal existente; o runtime PostgreSQL permanece sem superuser, BYPASSRLS ou DELETE nas quatro tabelas Telegram. O worker de diagnósticos não é recriado. Scheduler Laravel separado, sem portas publicadas, executa apenas os agendamentos definidos na aplicação.

A ativação inicial permite configuração pelo painel HTTPS para o tenant 1. Sem bot cadastrado e vinculado não há recepção nem entrega externa. Tokens devem ser cadastrados exclusivamente no painel seguro, nunca no chat ou nos relatórios.

## Rollback

Desabilitar o bot pelo painel compatível, quando configurado, para revogar vínculos e trocar geração. Definir `TELEGRAM_ENABLED=false`, manter schema e histórico, remover somente o include HTTPS Telegram e validar Nginx antes do reload. Recriar web, worker e scheduler com a imagem compatível e flag desabilitada. Validar readiness, login, fila e Health. Não executar down nem restaurar integralmente o banco para remover o canal. Reativação exige geração e vínculos novos.

A imagem anterior só pode ser usada como recuperação de falha inicial se comprovadamente não existir nenhuma linha em bots, links, updates, outbox e nenhuma conversa Telegram; após haver histórico, usar rollback funcional com imagem compatível. O rollback de schema é ensaiado somente em banco isolado sem histórico Telegram.

Evidências privadas de preparação, backup, restore e publicação: `/root/telegram-production-20261111`. Resultado final será registrado após os gates operacionais.
