# DIAGNOSTIC INTENT PLANNING PRODUCTION PASS

Publicação autorizada em 12/11/2026 na Central Laravel Web e workers. Nenhuma sondagem real, tarefa de Agent ou diagnóstico foi criado pela validação.

## Código integrado e imagens

Homologação integrada: `8c6e4c8`, `a66a8b6`, `3aeb82e`, com seus ancestrais de Intent Planning (`c84a05c`, `4f97956`, `1dd33c4`). O ancestral `9f661dc` corresponde à correção de saudação já presente na imagem operacional; seu conteúdo local foi comprovado antes da integração. O commit `350336e` registra apenas o bloqueio anterior. Nenhuma alteração de implementação adicional foi feita nesta publicação.

| Serviço | Imagem publicada | ID imutável |
| --- | --- | --- |
| Web, fila e scheduler Telegram | `agent-center-central:intent-planning-final-web-20261112` | `sha256:c4a0f088aefe67c992bcb9d920610edb2b1bdc0a83a2ec47938e58315b374f6d` |
| Diagnostic worker | `agent-center-central:intent-planning-final-diagnostic-20261112` | `sha256:fb4fb3e4615fad3511894bd6e093090ae9fa76e698db0df3abf9357c567c13e3` |

As imagens são exatamente as candidatas homologadas, retaggeadas; foram construídas incrementalmente sobre as duas bases operacionais respectivas. Os hashes de cada arquivo foram conferidos nos quatro containers em execução. Observability ausente. Sem migrations, alteração de schema ou publicação de módulos futuros. As demais modificações locais do checkout operacional foram preservadas por hash, incluindo Python Agent, Lifecycle e arquivos operacionais não relacionados.

## Plano observado na Web e no Telegram

Entrada: **Quero analisar a rota para 8.8.8.8**.

| Informação | Resultado |
| --- | --- |
| Intenção | `ROUTE_PATH_ANALYSIS` |
| Profile | `MTR_ICMP` |
| Skill pertinente | `mtr_icmp` |
| HTTP/HTTPS, DNS ou ping substituto | Não selecionados |
| Autorização efetiva | `UNKNOWN / effective_policy_unverified` |
| Confirmação oferecida | Não |
| Execução | Bloqueada; zero tarefas |
| Cobertura | Análise de rota NÃO EXECUTADA; solicitação INCONCLUSIVA |

Resposta nos dois canais: “Objetivo: Análise de rota até 8.8.8.8. Profile planejado: MTR_ICMP. Verificação planejada: MTR ICMP. Autorização efetiva não comprovada: a Agent API publicada não fornece à Central uma decisão por destino/protocolo nem a saúde atual do helper.” A mensagem informa a limitação, não promete execução e identifica conectividade básica como alternativa que precisa ser solicitada explicitamente.

O código diferencia autorização desconhecida de `policy_denied`. A amostra determinística de negação mantém a causa específica de política. Classificação técnica, objetivo, plano, execução e cobertura continuam separados; conectividade NORMAL não comprova rota saudável. As conclusões históricas não foram reclassificadas.

A Web e o canal Telegram foram validados pelo Engine nativo com identidade e Gates reais. O Telegram também percorreu Ingress autenticado, Runner, outbox e Deliver, com transporte capturado, fila simulada e transação revertida. Não foi enviado um teste ao chat real. O vínculo e sua conversa original ficaram intactos. As capturas mostram as páginas autenticadas da conversa Web e do histórico nativo do canal Telegram; não simulam uma captura do aplicativo Telegram.

## Validações

- Nova execução completa de Laravel em PostgreSQL separado: **495 testes, 3.775 asserções PASS**, incluindo FORCE RLS, RBAC/CSRF, isolamento entre tenants, Task Engine, Conversation Engine, Telegram, RAG/OpenAI e Profiles/Skills. Concorrência de tarefas e encerramento de conversas PASS.
- Pint: 22 arquivos PASS. Build Vite PASS. Secret scan básico do índice Git PASS; não equivale a um scanner especializado. Diff check PASS.
- 30 verificações autenticadas no navegador, larguras 390/768/1440 e temas claro/escuro: plano bloqueado nos dois canais, login exigido para acesso anônimo, Diagnostic Experience V2, integração Telegram e Operations. Sem erros JS ou overflow.
- TLS reproduzido na imagem publicada em container sem rede externa: certificado confiável aceito; falha de verificação preservada como `transport_failure` no estágio TLS; porta fechada preservada como `connection_refused` em TCP. Nenhum contato com provedor externo.
- HTTPS/login e `/health/ready`: 200. PostgreSQL com TLS verificado, runtime sem superuser/bypass RLS, dez tabelas com FORCE RLS, nenhuma linha de conversa/diagnóstico visível sem contexto.
- Fila database → worker → cache PASS. Scheduler ativo com `telegram:drain` e `conversations:close-idle`. Web e workers saudáveis.
- Webhook consultado por `getWebhookInfo`: registrado, URL HTTPS correspondente, zero updates pendentes, sem erro informado. Não foi reconfigurado.
- RAG preservado: índice vazio, recuperação nativa `no_match`, estado `READY_EMPTY`. Credenciais habilitadas continuam decifráveis; não houve chamada paga ao OpenAI.
- Operations Health final **VERDE**. Durante a recriação houve erros HTTP transitórios, refletidos temporariamente no indicador de janela; HTTPS/readiness se recuperaram e o indicador voltou a VERDE sem mudança de limiares ou limpeza de evidências.
- Quinze conjuntos de dados protegidos preservados integralmente, mais todos os Profiles existentes. Somente três Profiles de fábrica previstos pela homologação foram adicionados pelo Catalog. Configurações dos Agents e contagens de tarefas/diagnósticos preservadas. Conversas exclusivas de validação encerradas; sessão temporária removida.

## Backup e rollback

Backup **local** `20261112T151232Z` validado por restauração em `agent_center_restore_check`, removido após a prova. Os 16 conjuntos protegidos, FORCE RLS, triggers de imutabilidade e decifragem da credencial com chave respaldada foram comprovados. Não foi alterado ou acionado R2 nesta publicação.

Estado operacional privado, modo 0700: `/root/intent-planning-final-production-20261112`. Contém compose anterior, hashes, logs privados e rollback preparado. Não contém arquivos publicados no Git com chaves ou cookies.

Rollback operacional preparado: `bash /root/intent-planning-final-production-20261112/rollback.sh`. O helper exige autorização de rollback e administrador da plataforma; percorre cada tenant com contexto e Gate nativos. Desabilita somente `CAMINHO_TRACEROUTE`, `CONECTIVIDADE_BASICA` e `PORTA_SERVICO`, incrementa versão e registra auditoria, preservando histórico. Se houver diagnóstico pendente desses Profiles, recusa a troca incompatível e mantém o worker compatível para drenagem e nova tentativa; não cancela tarefas alheias. A prova em banco restaurado desabilitou três Profiles em transação revertida.

Com zero pendências, restaura o compose anterior e as imagens `greeting-restart-20261112` para Web/fila/scheduler e `agent064-workers-20261110` para diagnostic-worker, sem rebuild. Limpa views compiladas e executa prechecks. Não restaura o dump sobre produção nem apaga resultados. As imagens anteriores estão retidas por ID no manifesto. O script de publicação aciona esse rollback se a troca/precheck falhar; após publicação as verificações também foram aprovadas, portanto não foi necessário acioná-lo em produção.

## Limitações que mantêm o MTR bloqueado

A Agent API operacional não fornece decisão autoritativa atual por **destino/protocolo**, nem saúde atual verificável do helper. Capability anunciada, versão compatível e Agent online não comprovam essa autorização. O Planner mantém `UNKNOWN`, bloqueia o plano e omite confirmação. Isso não significa uma negação explícita de ACL. Não há evidência para autorizar `8.8.8.8`, e nenhuma ACL ou política foi ampliada.

A habilitação futura exige contrato operacional autoritativo e autorização correspondente em tarefa própria. Esta publicação não modifica Python Agent, Observability, Lifecycle, release 0.6.3 em quarentena, R2 ou equipamentos. Não há conclusão sobre a saúde real da rota, pois ela não foi sondada.

## Evidências

[Resumo e checks](evidence/intent-planning-final-production/validation.json), [imagens efetivas](evidence/intent-planning-final-production/published-images.json), [planejamento nos canais](evidence/intent-planning-final-production/channel-smoke.json), [Health final](evidence/intent-planning-final-production/health-after.json), [backup](evidence/intent-planning-final-production/restore-proof.json), [rollback isolado](evidence/intent-planning-final-production/rollback-proof.json), [navegador](evidence/intent-planning-final-production/browser.json), [Web](evidence/intent-planning-final-production/web-390-light.png), [histórico Telegram](evidence/intent-planning-final-production/telegram-390-dark.png).
