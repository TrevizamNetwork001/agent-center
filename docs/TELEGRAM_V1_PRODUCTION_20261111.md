# Telegram V1 — publicação autorizada em 2026-11-11

Autorização: operador confirmou publicação com todos os testes, backup e rollback nesta sessão. Candidato original: `0a2d71e`, evidências LAB: `526b7c6`.

A imagem candidata deriva de `agent-center-central:natural-conversation-v1-20261111` e acrescenta 30 arquivos de runtime. Auditoria e navegação são integradas aditivamente aos arquivos realmente publicados. A rota de permission-check de Observability, ausente na imagem anterior, permanece ausente; nenhuma mudança de Observability é publicada. O checkout de origem mantém sua rota anterior para a regressão de funcionalidades completas.

Web e worker de fila usam a mesma imagem Telegram. O worker recebe somente a conectividade de saída e a chave LLM necessárias ao canal existente; o runtime PostgreSQL permanece sem superuser, BYPASSRLS ou DELETE nas quatro tabelas Telegram. O worker de diagnósticos não é recriado. Scheduler Laravel separado, sem portas publicadas, executa apenas os agendamentos definidos na aplicação.

A ativação inicial permite configuração pelo painel HTTPS para o tenant 1. Sem bot cadastrado e vinculado não há recepção nem entrega externa. Tokens devem ser cadastrados exclusivamente no painel seguro, nunca no chat ou nos relatórios.

## Rollback

Desabilitar o bot pelo painel compatível, quando configurado, para revogar vínculos e trocar geração. Definir `TELEGRAM_ENABLED=false`, manter schema e histórico, remover somente o include HTTPS Telegram e validar Nginx antes do reload. Recriar web, worker e scheduler com a imagem compatível e flag desabilitada. Validar readiness, login, fila e Health. Não executar down nem restaurar integralmente o banco para remover o canal. Reativação exige geração e vínculos novos.

A imagem anterior só pode ser usada como recuperação de falha inicial se comprovadamente não existir nenhuma linha em bots, links, updates, outbox e nenhuma conversa Telegram; após haver histórico, usar rollback funcional com imagem compatível. O rollback de schema é ensaiado somente em banco isolado sem histórico Telegram.

Evidências privadas de preparação, backup, restore e publicação: `/root/telegram-production-20261111`. Resultado final será registrado após os gates operacionais.

## Resultado verificado

**AGENT CENTER TELEGRAM V1 PRODUCTION DEPLOY PASS — teste externo Telegram pendente.**

Imagem publicada em web, fila e scheduler: `agent-center-central:telegram-v1-20261111`, ID `sha256:295da05c82115134c28b4d5e860a130c50e2b351215404d56cb713387d2aac20`. Integração: `5324843` e `4fbc748`, além dos commits originais `0a2d71e` e `526b7c6`. Não há bot, vínculo ou histórico Telegram artificial em produção.

- Regressão Laravel/PostgreSQL descartável: **370 testes / 2580 assertions PASS**, incluindo migrações e down apenas no banco descartável. O código-base completo de rotas foi usado na suíte; a imagem preserva a ausência prévia da rota permission-check de Observability. As únicas diferenças relevantes de integração do layout/auditoria foram testadas; expectativas de contexto em inglês foram atualizadas para os textos portugueses já publicados, sem mudar RBAC ou a tela.
- Backup local `20261111T201630Z`, sem pruning e sem alteração de R2. Restore isolado verificou dados protegidos, RLS e decriptação da credencial OpenAI com a chave de backup. Migration Telegram/grants/rollback de schema passaram também sobre esse restore, exclusivamente em banco isolado.
- Migration operacional exclusivamente `2026_11_12_000001_add_telegram_channel`; quatro tabelas com FORCE RLS, runtime NOSUPERUSER/NOBYPASSRLS e sem DELETE nessas tabelas. Leitura sem contexto retorna zero linhas. Quinze tabelas de configuração preservadas integralmente; somente o registro da nova migration foi acrescentado ao conjunto protegido.
- Build npm, sintaxe PHP dos 30 arquivos da imagem, manifesto de hashes, secret scan e Pint dos 26 arquivos PHP alterados passaram. O Pint global identifica um problema de estilo preexistente em `tests/Feature/AgentManagedSettingsTest.php` (`method_argument_space`); o arquivo foi preservado, e isso não afeta os 370 testes funcionais aprovados.
- HTTPS/readiness, TLS PostgreSQL (inclusive rejeição de hostname/CA incorretos e plaintext), fila database → worker → cache, assets e login passaram.
- Revisão Playwright e inspeção visual: desktop1440/tablet768/mobile390, claro/escuro, sem overflow da página ou erros JavaScript. Painéis Conversas, RAG, LLM e Operations responderam200. Falta de CSRF419; tentativa de injetar tenant_id422; usuário sem sessão vai ao login. Sessão sintética removida após a validação.
- Ingresso HTTPS específico: GET403, POST para bot inexistente404 e corpo acima de32KiB413. A ACL do painel foi preservada byte a byte, exceto pela inclusão da localização Telegram restrita.
- `telegram-scheduler` executou efetivamente `telegram:drain 1` com DONE; `schedule:work` fica em serviço separado sem porta pública. Web e fila compartilham a imagem publicada. Os 72 arquivos críticos comparados em Agent/Diagnostics/Jobs/Llm/Rag/Tenancy/Models/Policies são idênticos à Central anteriormente publicada; a fila adota essa versão já existente da Web. Worker de diagnósticos, PostgreSQL e Nginx Docker mantiveram imagem e instante de início.
- Rollback funcional foi **executado e verificado em produção antes do cadastro de bot**: flagfalse/tenants vazios, include público removido, schema e contagens preservados. Reativação posterior passou nos prechecks. Não houve down operacional ou restauração do banco de produção.
- Health final **VERDE**; **97 tarefas / 28 diagnósticos**, sem alteração. Configurações protegidas, Agent/Lifecycle/Observability, R2, equipamentos e arquivos locais alheios foram preservados.

## OpenAI real após a publicação

Uma chamada HTTPS real à OpenAI, a partir do container de fila usando a Conversation Engine compartilhada e identidade **Web**, sem simular bot Telegram em produção. Somente conversa sintética, store=false, sem tools, teto1024tokens e uma chamada permitida pelo transporte de prova. Resultado ASSISTED: **513 tokens de entrada + 63 de saída = 576 tokens**. Conversa encerrada; nenhuma tarefa ou diagnóstico criado. Nenhuma configuração de modelo, provider, binding ou prompt foi alterada.

Entrada: “E você, como é ajudar num plantão tranquilo?”

Resposta: “Boa tarde! Num plantão tranquilo, dá para conversar com calma e ajudar a organizar as ideias. Sou o assistente virtual do Agent Center. Como está o plantão por aí?”

## Pendência externa

**Nenhum bot autorizado foi cadastrado.** O transporte real Telegram → HTTPS → fila → Telegram não foi exercitado; a publicação da infraestrutura não é prova desse fluxo externo. O ensaio Telegram com transporte simulado permanece restrito ao laboratório anterior.

Para concluir, acessar [Canais → Telegram](https://agente.trevizamnetwork.com.br/integrations/telegram) em sessão autenticada e no tenant1, cadastrar o token do bot de homologação no campo seguro, registrar webhook, gerar o código, enviar `/start CODIGO` em conversa privada e confirmar o ID no painel. Depois validar mensagens sociais, RAG, revogação e diagnóstico com confirmação sobre dados sintéticos e alvos previamente autorizados. Não enviar token no chat. A disponibilidade de credencial e de uma identidade Telegram real é a dependência restante.

## Evidências

- [Prova final](evidence/telegram-production-20261111/final-proof.json)
- [Regressão completa](evidence/telegram-production-20261111/regression-pass.log)
- [Restore](evidence/telegram-production-20261111/restore-proof.json)
- [OpenAI real](evidence/telegram-production-20261111/openai-real.json)
- [Ingresso HTTPS](evidence/telegram-production-20261111/https-ingress-proof.json)
- [Browser](evidence/telegram-production-20261111/browser/browser.json)
- [Desktop escuro](evidence/telegram-production-20261111/browser/telegram-1440-dark.png)
- [Celular claro](evidence/telegram-production-20261111/browser/telegram-390-light.png)
- [Health](evidence/telegram-production-20261111/browser/operations.png)
- [Diff exclusivo de infraestrutura Telegram](evidence/telegram-production-20261111/compose-telegram.patch)
