# CONVERSATION CONFIRMATION PRODUCTION PASS

Publicação autorizada exclusivamente para o patch `d15c7b7`, em 11/11/2026. A correção está ativa na Central operacional. Não foi publicada a evolução de conversação natural dos commits `63db3d5` / `d9bb765`.

## Integração e escopo

- Origem aprovada: `/root/conversation-confirmation-fix`, commit `d15c7b7`.
- Integração à base operacional atual por cherry-pick: `3f6975385e65ffb9d3e2a8774d443e1c7565466a`.
- Patch ID idêntico ao commit aprovado; sem conflito ou substituição das alterações locais de outras frentes.
- **Somente dois arquivos de runtime mudaram**: `app/Conversation/Engine.php` e `app/Conversation/IntentDetector.php`.
- Imagem ativa: `agent-center-central:conversation-confirmation-20261111`.
- ID publicado: `sha256:298c13106ae36db3659b8e3c8a9ece156f27939e8bdbc2583550a431f54e7ced`.
- Base e rollback preservados: `agent-center-central:rag-v1-final-20261111`, `sha256:fcfa83717242a742a5c51ae36999166c055e47aa0144f2aef18dc6874d98efda`.

O build offline preservou todas as camadas da imagem RAG V1 ativa e acrescentou apenas os dois COPY aprovados. Hashes dos arquivos coincidem com `d15c7b7`; os arquivos do núcleo de linguagem natural não estão na imagem. Nenhuma migration, dependência, template, CSS ou JavaScript foi publicada. O checkout operacional completo não foi usado para reconstruir a Central.

A configuração efetiva do Compose mudou somente em `services.web.image`. Web foi recriado com `--no-deps --no-build`. Imagens, instantes de início e saúde de worker, diagnostic-worker, nginx e PostgreSQL permaneceram iguais. Os 59 arquivos que já tinham alterações locais foram preservados; em Compose, apenas o campo de imagem web foi acrescentado à diferença anterior. O commit de fechamento registra esse campo sem incorporar o restante do Compose modificado por outras frentes.

## Gates anteriores à publicação

- Prechecks existentes: TLS PostgreSQL positivo; rejeição de hostname/CA incorretos e conexão plaintext; PDO com `verify-full`; configuração de produção sem debug; sessão segura; fila → worker → cache; worker de diagnóstico; assets via nginx: **PASS**.
- Regressão PostgreSQL/Laravel reexecutada no candidato: **319 testes / 2.346 assertions PASS**, incluindo 36 testes de Conversation. Migrations up/down e remoção do banco descartável `agent_center_test` concluídos.
- Pint nos arquivos aprovados: **PASS**. Compatibilidade dos arquivos antigos com a base ativa e hashes do candidato: **PASS**.
- Mesmo fluxo de confirmação e checks de RAG passaram primeiro em `agent_center_lab`, usando a imagem candidata.

## Backup e restore verificados

Backup exclusivamente local, geração **20261111T174544Z**, com pruning desabilitado. Não foi usado `backup-run.sh`, não houve replicação ou chamada R2. O runner existente verificou o manifesto SHA-256, dump, bundle e componentes desta geração.

Restore em `agent_center_restore_check`, separado da produção, passou nas verificações do runner existente: schema, triggers imutáveis, views security_invoker e isolamento sem contexto. A verificação adicional comparou as 16 tabelas protegidas do snapshot, configuração dos Agents, FORCE RLS do RAG e descriptografia da credencial OpenAI usando a chave copiada no próprio backup. A etapa de leitura dessa cópia 0600 foi executada em container efêmero root, somente leitura, sem capabilities, mantendo o mesmo papel restrito no PostgreSQL. As permissões dos secrets originais não foram alteradas. O banco de restore foi removido pelo runner após sucesso. Nenhum restore foi aplicado sobre produção.

## Fluxo original comprovado em produção

Conversa de validação: `58eae956-d7dd-476d-b0a8-338fa60b1cc1`. Diagnóstico: `d58fb10c-5042-4a31-b718-6e04c64a1532`.

1. Agent Center: **“Confirma um novo diagnóstico para google.com? As evidências anteriores serão preservadas.”**
2. Usuário: **“Sim, confirmo o diagnóstico de google.com.”**
3. Intenção persistida: **CONFIRM**.
4. Pendência consumida; evento `confirmation_consumed`: **1**.
5. Estado observado após confirmação: **DIAGNOSTIC_RUNNING**.
6. Criação pelo Task Engine existente: **um diagnóstico**, com evento `diagnostic_started` e auditoria `diagnostic.created`, ambos únicos para esta validação.
7. A pergunta de confirmação apareceu **uma única vez**. O usuário recebeu “Confirmação processada para google.com.” e o encaminhamento do diagnóstico.

A repetição com a mesma chave não alterou sequência/histórico. Uma nova mensagem afirmativa com outra chave, durante o diagnóstico, não iniciou outro diagnóstico. Sync/refresh também não duplicaram encaminhamento. No HTTPS real, replay retornou **200**, reaproveitamento de chave com conteúdo diferente retornou **409**, e a requisição sem CSRF retornou **419**. O histórico permaneceu igual após refresh.

### Validação sem sondagens ou operação em equipamento

A reprodução usou a Engine e o Task Engine reais da imagem operacional, sob a identidade administrativa já existente e autorizada. Criação, confirmação, verificações de idempotência e cancelamento nativo ocorreram **dentro da mesma transação externa PostgreSQL**. O diagnóstico foi cancelado antes do commit: os workers nunca puderam observar um diagnóstico pendente executável. Qualquer falha anterior ao cancelamento teria revertido toda a transação.

O estado DIAGNOSTIC_RUNNING foi comprovado antes do cancelamento. Ao final, o diagnóstico de validação ficou **CANCELLED**, a conversa **CLOSED**, e o histórico/auditoria permaneceram preservados. **Não foi medida a conectividade de google.com nem executada uma Skill.** Agent tasks permaneceram **97 → 97**, sem task vinculada ao diagnóstico de validação. Nenhum worker foi pausado para obter esse resultado.

## RAG V1, OpenAI e dados preservados

Em produção, documento/índice sintéticos temporários foram exercitados dentro de uma transação integralmente revertida: publicação, indexação, recuperação, revalidação da fonte, isolamento por tenant, revogação e resposta `no_match` sem evidências: **PASS**. Um guard impediu chamadas externas; a contagem de chamadas do transporte foi **zero**. Não se apresenta esse teste como nova inferência OpenAI real.

Os snapshots antes/depois/final coincidem para as 16 tabelas protegidas, incluindo migrations, Knowledge, versões, índice RAG, providers, models, bindings e prompts. A credencial continua descriptografável e seus dados criptografados/configuração permaneceram iguais. A configuração dos Agents, releases, quarentenas e operações também permaneceu preservada. Não houve alteração do RAG, Client OpenAI, Knowledge, Task Engine, Python Agent, Lifecycle, Observability, R2 ou equipamentos.

## HTTPS, identidade, autorização e saúde

- HTTPS/login e readiness: **200**, certificados verificados sem ignorar TLS.
- PostgreSQL/PDO: TLS ativo, `verify-full`; runtime sem superuser e sem BYPASSRLS; RLS/FORCE RLS verificados.
- Operations Health final: **VERDE**. Task Engine, fila, worker e ausência de leases vencidas conferidos.
- Policy de envio para Viewer negada em fixture de principal somente em memória, sem modificar uma conta. RBAC completo, usuários reais de teste e tenant isolation passaram também na regressão Laravel/PostgreSQL.
- Tenant incorreto não viu a fonte ou chunks do RAG. Acesso anônimo à conversa redirecionou ao login.
- Auditoria e transições nativas conferidas; nenhuma autoridade operacional foi entregue ao LLM.

A interface autenticada foi validada em HTTPS com sessão temporária nativa Laravel criada pela validação CLI autorizada: guard, encriptação, Auth, Session, CSRF, Gates e middleware preservados, sem endpoint de bypass. Login de produção por senha não foi repetido. A sessão foi removida ao concluir, assim como os arquivos privados com cookie. Nenhum cookie, token de sessão ou secret foi registrado no Git.

Navegador: desktop 1440/mobile 390, claro/escuro, histórico, confirmação processada, retry, refresh, Operations Health e RAG: **PASS**, sem overflow horizontal ou erros JavaScript.

- [Histórico desktop](evidence/conversation-confirmation-production-20261111/browser/confirmation-1440-light.png)
- [Histórico mobile](evidence/conversation-confirmation-production-20261111/browser/confirmation-390-light.png)
- [Operations Health](evidence/conversation-confirmation-production-20261111/browser/health-1440-light.png)
- [RAG](evidence/conversation-confirmation-production-20261111/browser/rag-1440-light.png)

## Rollback controlado

Imagem anterior preservada e configuração do rollback validada: o override muda somente a imagem web. Nenhum rollback foi necessário, pois os gates de publicação e de validação passaram. O runner de publicação tinha rollback automático para falha crítica nos gates imediatos.

Se for necessário reverter esta publicação no escopo operacional autorizado:

```sh
cd /opt/agent-center
docker compose --env-file .env -f deploy/compose.yaml -f deploy/conversation-confirmation/rollback.yaml up -d --no-deps --no-build web
```

Reconciliar também `services.web.image` do Compose principal para a imagem anterior, preservando as outras alterações locais. Revalidar HTTPS/readiness e Health. Não executar migration down, não restaurar o banco inteiro nem apagar histórico, Knowledge ou auditoria para reverter dois arquivos PHP. Diagnósticos já autorizados não são recriados por um rollback de código. A imagem antiga retoma a limitação original do parser; registrar esse motivo caso a reversão seja usada.

## Evidências e fechamento

[Evidências sanitizadas](evidence/conversation-confirmation-production-20261111/) incluem imagem/hashes, integração, preservação de alterações locais, restore, regressão, prechecks, estados/eventos do fluxo, RAG, dados antes/depois, containers, rollback, sessão removida e navegador. Arquivos confidenciais do backup e logs administrativos privados continuam fora do Git.

A correção passa a reconhecer novas confirmações válidas das conversas pendentes; mensagens antigas já processadas não são reexecutadas automaticamente, preservando idempotência. Nenhuma conversa de usuário foi reescrita para simular aprovação.

**CONVERSATION CONFIRMATION PRODUCTION PASS**
