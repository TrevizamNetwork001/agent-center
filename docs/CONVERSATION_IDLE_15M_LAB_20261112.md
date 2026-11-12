# Conversas Web/Telegram: encerramento após 15 minutos

NATURAL CONVERSATION CONTEXT FIX LAB PASS

Laboratório de 12/11/2026. Implementação: `42d0d36`; compatibilidade de `/help` com conversas desabilitadas: `f1bfa30`.
Inclui a naturalidade conforme o interlocutor, registrada em [ADAPTIVE_SPEAKING_STYLE_LAB_20261112.md](ADAPTIVE_SPEAKING_STYLE_LAB_20261112.md), commits `c816559` e `b5a09f8`.

## Comportamento

Após 15 minutos sem interação, a conversa recebe um agradecimento e é encerrada com o histórico preservado. A checagem ocorre a cada minuto, portanto o aviso pode chegar na checagem seguinte ao prazo. Isso funciona mesmo sem o navegador aberto. Não há chamada ao LLM para encerrar chats.

Exemplo observado nos dois canais, com interlocutor informal:

> Como ficamos 15 minutos sem conversar, vou encerrar este chat por aqui. Valeu pela conversa! O histórico está salvo. Quando precisar, é só mandar outra mensagem.

Para português padrão:

> Como não houve interação por 15 minutos, estou encerrando esta conversa. Agradeço pelo contato! O histórico está preservado. Envie uma nova mensagem quando precisar.

Ao retornar com uma saudação ou novo assunto, a pessoa inicia outra conversa; a anterior continua encerrada e consultável. O vínculo Telegram continua ativo, inclusive em grupos e tópicos. `/historico` aponta para a conversa atual.

Um diagnóstico pendente ou em execução impede o encerramento automático. O resultado oficial é preservado e inicia nova contagem de 15 minutos. Polling e varreduras não simulam interação do usuário. Mensagens e comandos reais aceitos, como `/historico`, renovam a contagem. Uma mensagem recente na fila Telegram impede o encerramento prematuro enquanto aguarda processamento.

Confirmações operacionais pendentes são invalidadas ao encerrar. Um “sim” atrasado não abre teste, não reaproveita alvo antigo e não executa Skill. Encerramento explícito e cancelamento continuam com a semântica anterior.

## Implementação e controles

- `Engine::closeIdle` usa autorização nativa, identidade/canal/contato/tópico, advisory lock do usuário e lock da conversa. Transição nativa `CLOSED`, evento `idle_timeout`, chave única `idle_closed` e marcador de encerramento por inatividade. Sem migration e sem novo estado.
- Web: `IdleSweep` e comando `conversations:close-idle {tenant}`, por tenant explicitamente permitido, carregando o dono pela política nativa de self RLS. Suporta PlatformAdmin com tenant NULL; bloqueia proprietário revogado e tenant desabilitado.
- Telegram: integração à varredura existente, mantendo a transação de contexto/vínculo, cursor e outbox. A entrega revalida permissões pelo caminho nativo; não há envio paralelo ou direto ao Telegram.
- Ativação explícita, desligada por padrão: `CONVERSATION_IDLE_ENABLED=true` e `CONVERSATION_IDLE_TENANTS=1` para o tenant de homologação. Telegram também depende de `TELEGRAM_ENABLED` e `TELEGRAM_TENANTS` já existentes. O prazo permanece fixo em 15 minutos.
- `/help` continua disponível quando as conversas estão desabilitadas, tanto com a flag de inatividade ligada quanto desligada; a renovação do timer não bloqueia os comandos existentes.
- Parser, Task Engine, RAG/fontes, RBAC/FORCE RLS, limites OpenAI e autorização operacional preservados. Sem alterações em Agent, Lifecycle, Observability, R2 ou equipamentos.

## Evidências e validação

Evidências sanitizadas: `docs/evidence/conversation-idle-15m-20261112/`. Artefatos completos e capturas adicionais: `/root/agent-center-chat-idle-20261112/`.

A prova funcional usou usuários fictícios, PostgreSQL de laboratório e a imagem candidata `agent-center-central:conversation-idle-15m-lab-20261112`, sem montar o código-fonte no container. Timestamps de duas conversas sintéticas foram recuados 16 minutos; os handlers reais de varredura executaram o fechamento. Esse ensaio acelera a espera e não é apresentado como uma espera real de 15 minutos. Os testes automatizados verificam separadamente os limites de 14:59 e 15:00.

O ingresso, Runner, outbox e Deliver nativos do Telegram entregaram ao cliente simulado exatamente o aviso salvo no painel. A repetição da varredura produziu um único aviso. O retorno abriu outra conversa sem refazer vínculo. Nenhum envio Telegram real, chamada LLM ou tarefa operacional ocorreu nesse ensaio de inatividade.

A interface Web abriu uma conversa pelo formulário real, exibiu o encerramento e redirecionou a mensagem de retorno para uma nova conversa. Oito capturas Web/histórico Telegram em 1440 e 390 px, claro/escuro; sem overflow ou erros JavaScript. Não houve mudança de layout. A rodada de naturalidade anterior também tem 12 capturas e exemplos de três chamadas OpenAI reais autorizadas.

Os resultados finais da regressão, Pint, build, concorrência e varredura local de secrets estão nos arquivos de evidência. A varredura de secrets usa padrões locais, não gitleaks.

Regressão final: 442 testes e 3.115 assertions nos 41 arquivos, em três grupos disjuntos e sequenciais. Testes de processos separados aprovados para duas varreduras simultâneas e mensagem disputando o fechamento, além da concorrência existente do Task Engine. Pint: nove arquivos PHP aprovados. Build Vite aprovado. Varredura local de secrets e `git diff --check` aprovados. Os bancos de teste foram criados e removidos pelo script nativo; os servidores Web criados para estes dois laboratórios foram removidos ao concluir as provas, mantendo imagens e evidências.

## Publicação preparada; não executada

A produção permanece em `3341c3f`, imagem `agent-center-central:context-colleague-v1-20261112`. Esta rodada depende de autorização operacional específica para publicação.

Após autorização: conferir diff e hashes da candidata, backup com restauração comprovada, manter a proteção de revogação de colaboradores da produção atual, promover imagem compatível aos serviços Central pertinentes e configurar explicitamente a flag/lista de tenants. Validar scheduler, saúde, saudação informal e padrão, `/historico`, inatividade e proteção de diagnóstico em execução. Nenhuma migration ou alteração de credencial/vínculo é necessária. A primeira varredura poderá encerrar conversas antigas já inativas no tenant habilitado; o histórico será preservado.

Rollback preferencial: desativar `CONVERSATION_IDLE_ENABLED` nos serviços pertinentes e recarregar a configuração. Isso remove o agendamento Web e torna o fechamento Telegram inativo, conservando a abertura de nova conversa ao retornar a um chat já encerrado. O desligamento foi verificado pela listagem real do scheduler. Não apagar nem reabrir conversas em massa.

Se for necessário reverter código, usar a imagem de naturalidade imediatamente anterior, compatível com `3341c3f`, preservando o guard de membros revogados. Conversas já fechadas continuam no histórico; usar Nova conversa ou `/nova`. A naturalidade tem rollback independente dos dois arquivos descritos no relatório correspondente. Sem reversão de banco ou interferência nos diagnósticos.
