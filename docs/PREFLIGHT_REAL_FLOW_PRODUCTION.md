# Preflight Real Flow — publicação controlada

Escopo autorizado: commit `39b1eed` e lacuna de notificação terminal explicitamente autorizada no item 8. Base operacional: `ce6efa6`. Sem migrations, mudanças no Agent, Lifecycle, políticas, ACLs, freeze, equipamentos ou R2.

## Causa e complemento mínimo

O commit 39b1eed explicava a consulta pendente, mas ainda exigia uma nova mensagem. A resposta autenticada do Agent não atualizava a conversa. O complemento associa a conversa ao UUID/destino/protocolo/digest da consulta existente e agenda um job na fila de banco já utilizada. A aceitação da resposta agenda o mesmo sincronizador depois do commit; outro job encerra a espera no prazo do pedido. Ambos reutilizam `Conversation\Engine::sync`, identidade/Gates nativos e o outbox Telegram existente. A Web continua usando seu polling de fragmentos.

Não há novo autorizador. `Preflight::observation` apenas lê a prova já autenticada, sem emitir consulta, mudar política, renovar prazo ou executar testes. Uma resposta ALLOWED passa novamente pelo Planner existente; só então oferece confirmação. O executor conserva sua revalidação obrigatória. A confirmação possui acompanhamento de validade; a entrega Telegram verifica novamente o vínculo da consulta e a validade, suprimindo mensagens antigas.

| Situação | Autorização | Estado apresentado | Efeito |
|---|---|---|---|
| Consulta em andamento | UNKNOWN | PENDING | Retorno automático; nenhuma tarefa |
| Prova atual autorizada | ALLOWED | ALLOWED | Confirmação explícita, sem execução automática |
| Política nega | DENIED | DENIED | Comunica negativa, sem confirmação |
| Prova vencida/trocada/revisão mudou | UNKNOWN | STALE | Invalida confirmação; requer nova consulta |
| Prazo sem resposta | UNKNOWN | ERROR / preflight_timeout | Encerra espera; não afirma política negada |
| Agent/contrato indisponível | UNKNOWN | UNAVAILABLE | Bloqueio preservado |
| Helper indisponível | UNKNOWN | HELPER_UNAVAILABLE | Bloqueio preservado |

Janelas originais mantidas: pedido 45 segundos, retorno autenticado em até 5 segundos do envio, prova reutilizável por 10 segundos desde envio e recebimento. Fila atrasada não estende esses prazos. Não se mantém uma espera sem limite nem se inicia ciclo automático de novas consultas. Novo pedido, cancelamento e encerramento invalidam o acompanhamento anterior. Locks de conversa, evento terminal único e outbox existente impedem duplicação.

Exemplo pendente: “A consulta de autorização para analisar a rota até 8.8.8.8 está pendente. Avisarei aqui quando terminar. Nenhum teste foi executado.”

Exemplo terminal: “A política atual não permite analisar a rota até 8.8.8.8. Nenhum teste foi executado.”

Detalhes técnicos ficam no painel por mensagem. Classificações de diagnósticos e cobertura do objetivo não foram alteradas.

## Reversão preparada

Restaurar os engines e imagens anteriores da Central, preservando banco, histórico e Agent 0.6.5. Imagens de rollback derivam exatamente de `preflight-v1-avatar-web-20261112` (Web) e `preflight-v1-web-20261112` (fila/scheduler), com somente a classe do job adicionada para que jobs já gravados continuem desserializáveis e drenem pelo sincronizador anterior. O worker de diagnóstico retorna a `preflight-v1-diagnostic-20261112`. Não apagar jobs, mensagens ou journal; não restaurar o banco sobre dados mais recentes. O acompanhamento novo deixa de existir na reversão, mas a autorização e revalidação anteriores permanecem.

## Limitações

O prazo de entrega depende da fila e do transporte Telegram. Uma autorização que vencer enquanto aguarda entrega é suprimida e exige novo Preflight, nunca transformada em permissão. Uma entrega Telegram ambígua segue o comportamento anterior do outbox: não se repete automaticamente um envio inicial com resultado desconhecido. Nenhum teste libera 8.8.8.8 nem executa MTR.

Resultados, hashes e evidências efetivos constam nas seções seguintes.

## Validação de laboratório para esta publicação

- Suíte completa: **519 testes / 3.996 asserções PASS** em PostgreSQL separado com FORCE RLS.
- Depois da proteção adicional contra aviso pendente atrasado: **108 testes / 749 asserções PASS**, incluindo Telegram, Web, preflight autenticado, idempotência, helper, isolamento e confirmação vencida.
- Rechecagem do prazo exato e concorrência: **13 testes / 139 asserções PASS**; processos distintos para Task Engine, fechamento de conversa e dois callbacks de preflight PASS. Exatamente uma mensagem terminal e nenhuma tarefa no caso de callbacks concorrentes.
- O primeiro rollback do schema descartável encontrou `enrolled_at` ausente na fixture nova de concorrência. A fixture foi corrigida, sem alterar schema/runtime/asserções; todos os testes de concorrência e o rollback integral das migrations passaram na repetição.
- Pint: **314 arquivos PASS**. Vite build PASS; dependências do build isolado: zero vulnerabilidades reportadas pelo npm.
- Painel Blade: 390/768/1440 pixels, claro/escuro, teclado e ausência de overflow PASS. Fixture sanitizada, sem dados de produção.
- Backup local `20261112T195854Z`: integridade verificada e restauração isolada PASS; 16 tabelas protegidas comparadas, FORCE RLS, identidade/configuração e credenciais cifradas conferidas. Sem replicação R2.
- Precheck operacional: Agent 0.6.5 ativo, sem operações pendentes; política revisão 4; webhook registrado sem backlog; PostgreSQL/TLS, fila e Operations Health verdes. Nenhuma sondagem.
- Rechecagem específica de RBAC no callback com acompanhamento ainda pendente: revogar o papel operacional impede atualização; cancelamento e outro tenant continuam sem efeito operacional. **13 testes / 141 asserções PASS**, incluindo rollback do schema descartável.

## Publicação observada

Commits integrados: `39b1eed` e `2f16a80`. O complemento foi implementado e homologado antes da publicação, conforme autorização específica do item 8. As 220 alterações preexistentes do checkout foram preservadas por hash; a única sobreposição era uma formatação em teste, cujo conteúdo de trabalho foi preservado integralmente.

Imagens operacionais:

| Papel | Imagem | SHA-256 da imagem |
|---|---|---|
| Web | `agent-center-central:preflight-real-flow-web-20261112` | `c125cd7d8a95297ec87fc50f2eaded48cff60bc0e3b3ea44911b4580a2cef5db` |
| Fila e scheduler | `agent-center-central:preflight-real-flow-queue-20261112` | `d74cc47b5f0eb3be2ff1a23068836f53302b8f049db0c60a5ee1b04169dd1cd9` |
| Worker diagnóstico | `agent-center-central:preflight-real-flow-diagnostic-20261112` | `d9509c129b88e97262c1037bbd99a428138c1a67bdcc883e17b9dde6c2cacc96` |

O callback autenticado real do Agent 0.6.5 produziu **PENDING → DENIED** e a fila gravou a resposta na conversa em aproximadamente **11,4 segundos**, sem outra mensagem. O observador desta prova apenas lia o banco, sem chamar `Engine::sync`. Uma segunda prova no navegador autenticado validou a atualização automática do DOM pelo polling Web, em desktop e celular, sem erros JavaScript e sem confirmação oferecida.

Telegram: ingresso, identidade, Runner, outbox e entrega nativos foram executados com a decisão real DENIED; o transporte foi capturado e os registros temporários revertidos. Não houve envio externo de teste nem alteração no vínculo real. A atualização Telegram assíncrona sem nova mensagem foi validada em laboratório; a mesma classe revisada foi conferida por hash na fila publicada.

Plano observado: `ROUTE_PATH_ANALYSIS`, `MTR_ICMP`, somente `mtr_icmp`, sem HTTP/HTTPS. Resposta curta: “A política atual não permite analisar a rota até 8.8.8.8. Nenhum teste foi executado.” Agent, identidade, digest/revisão de política 4 e 16 tabelas protegidas permaneceram iguais. Contagens de tarefas e diagnósticos não aumentaram.

HTTPS/login e readiness responderam 200. PostgreSQL/TLS, fila funcional, webhook e scheduler foram verificados. A troca dos containers provocou sete respostas 502 entre 20:09:38,928 e 20:09:43,966 UTC, registradas pelo Health. O alerta de taxa de erros foi preservado e acompanhado, sem limpar logs, alterar limiares ou filtrar as falhas. Não houve falha funcional de preflight ou tarefa executada nesse intervalo.

Evidências sanitizadas: [callback](evidence/preflight-real-flow/production-callback-timing.json), [Web](evidence/preflight-real-flow/production-web-proof.json), [Telegram capturado](evidence/preflight-real-flow/production-telegram-proof.json), [hashes/dados](evidence/preflight-real-flow/production-source-proof.json) e [restauração](evidence/preflight-real-flow/production-restore-proof.json).

Rollback operacional preparado em `/root/preflight-real-flow-publish-private/rollback.sh`; os Dockerfiles reproduzíveis das imagens compatíveis estão junto às evidências. O rollback não precisou ser acionado. A limitação operacional permanece: 8.8.8.8 está DENIED pela política vigente; este patch não libera o destino nem comprova saúde de uma rota não executada.

## Encerramento

**PREFLIGHT REAL FLOW PRODUCTION PASS.** Operations Health final **VERDE**, webhook OK, fila com zero pendências e zero falhas na captura final, scheduler com drain observado e nenhuma falha. Após a janela real de cinco minutos foi reexecutado o coletor existente, sem modificar seu código, filtros, limiares ou logs; a recoleta confirmou recuperação. O intervalo de 502 permanece registrado nas evidências. Nenhuma falha posterior à inicialização foi observada.

[Health final](evidence/preflight-real-flow/production-health-proof.json). Screenshots autenticados Web (desktop/celular) e Operations Health estão preservados no diretório privado de evidências. A sessão temporária de validação foi revogada. Nenhum rollback de produção foi necessário; backup restaurado e imagens de reversão compatíveis continuam disponíveis.
