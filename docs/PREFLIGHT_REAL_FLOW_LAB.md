# Preflight real — análise do fluxo e resposta simplificada

**PREFLIGHT REAL FLOW LAB PASS**

Correção concluída somente em laboratório. Nenhuma publicação nesta tarefa.

## Causa concreta

O evento real do Telegram `3b1e2588-3840-4fe1-86cf-0df6aa72f891` foi recebido às **19:19:22 UTC de 12/11/2026**, processado sem erro e sem retentativa. A mensagem “Meu rei, quero analisar a rota para 8.8.8.8” foi registrada às **19:19:23**, no mesmo segundo da resposta do sistema e da criação da consulta `c486621b-24b0-42d3-9795-ca28fe07def1`.

O Agent **c88457e9-748f-4906-982a-166d0ee0f6d3**, versão **0.6.5**, só retirou essa consulta às **19:19:41**. Às **19:19:42**, um segundo depois, a Central recebeu **DENIED / policy_denied**, para o mesmo destino, ICMP, revisão **4**, digest igual ao da política atual.

O atraso de **18 segundos antes da retirada**, compatível com o ciclo periódico do heartbeat, explica o estado inicial. A resposta Telegram foi construída antes da resposta do Agent. Não foi encontrada divergência de identidade, política, revisão, protocolo, capability ou compatibilidade nesse evento. Não houve timeout do retorno autenticado: a resposta chegou em 1 segundo, dentro do limite de 5 segundos.

A decisão anterior DENIED da publicação não podia ser reutilizada indefinidamente: o contrato só reutiliza uma decisão recente por 10 segundos. Uma consulta nova, sem prova atual, corretamente bloqueia com UNKNOWN. O problema comprovado era a apresentação genérica, que juntava consulta pendente, expiração, incompatibilidade e outras causas como “não comprovada”, além de exibir detalhes internos no chat.

O helper está saudável na leitura operacional complementar. No resultado DENIED do evento, `helper_healthy=null` significa que a autorização foi recusada antes da checagem do helper; não significa helper com falha. Não se inventou saúde retrospectiva do helper a partir desse campo.

## Correção mínima

- Manter os estados de autorização **ALLOWED, DENIED, UNKNOWN** e os mesmos critérios de autorização/validade.
- Acrescentar motivos determinísticos no retorno interno da Central para pendência, decisão vencida, timeout sem resposta, slot ocupado e contrato incompatível. Nenhum campo novo foi exigido do Agent.
- Manter **STALE** e **HELPER_UNAVAILABLE** como condições da verificação, com autorização UNKNOWN; não são novas permissões.
- Timeout significa ausência de resposta no prazo. O contrato V1 não informa o código HTTP/TLS de uma tentativa de transporte que não chegou à Central; não se atribui uma falha de comunicação específica sem evidência.
- Simplificar a apresentação de planejamento/confirmacão de rota no Telegram. Guardar objetivo, Profile, etapas, Agent, estado, motivo, revisão, identificador e tempos da consulta em metadados da resposta e em detalhes recolhíveis do painel protegido.
- Não esperar pelo Agent dentro da transação nem criar polling, jobs ou segundo motor de autorização. O pedido continua bloqueado até uma nova avaliação com prova válida.

## Estados

| Autorização | Condição/motivo | Efeito |
|---|---|---|
| ALLOWED | Prova autenticada atual e helper saudável | Somente oferece confirmação; revalidação permanece obrigatória |
| DENIED | policy_denied | Bloqueia; não é tratado como desconhecido |
| UNKNOWN | preflight_pending | Consulta emitida/aguardando resposta; não é negação |
| UNKNOWN | preflight_stale / STALE | Prova venceu; nova consulta, sem reutilizar a antiga |
| UNKNOWN | helper_unavailable / HELPER_UNAVAILABLE | Recurso indisponível; não é política negada |
| UNKNOWN | preflight_timeout / COMMUNICATION_TIMEOUT | Janela terminou sem resposta; não afirma causa HTTP/TLS |
| UNKNOWN | preflight_busy | Outra consulta ocupa o slot ou há contenção |
| UNKNOWN | preflight_incompatible | Contrato/capability/destino não suportados pelo preflight V1 |

Janelas preservadas: pedido **45 s**, aceitação do retorno **5 s**, reutilização **10 s**. Policies, ACLs, destino, device freeze e classificação técnica não foram alterados.

## Exemplos Telegram

Pendente:
> A consulta de autorização para analisar a rota até 8.8.8.8 ainda está pendente. Peça novamente em instantes. Nenhum teste foi executado.

Negado, depois da resposta válida:
> A política atual não permite analisar a rota até 8.8.8.8. Nenhum teste foi executado.

A Web conserva a apresentação técnica anterior e acrescenta os detalhes por mensagem. O painel identifica explicitamente que o registro corresponde ao momento da resposta. Não o apresenta como uma nova decisão atual. A classificação oficial de diagnósticos executados não foi alterada, e nenhum NORMAL é usado para afirmar saúde de rota não testada.

## Rollback e publicação futura

Esta tarefa é somente laboratório. Produção permanece no commit/imagens publicados anteriormente, Agent 0.6.5 preservado. Nenhum Agent, updater, Lifecycle, ACL, equipamento ou R2 foi modificado.

Para uma publicação posterior autorizada: usar as imagens incrementais de laboratório, compatíveis com o mesmo contrato do Agent 0.6.5; verificar backup/ausência de operações conflitantes, trocar somente Central Web/fila/scheduler e worker de diagnóstico correspondentes, limpar views compiladas e validar os dois canais sem confirmar execução.

Rollback: restaurar as imagens atuais da Central (`preflight-v1-avatar-web-20261112` no Web, `preflight-v1-web-20261112` na fila/scheduler e `preflight-v1-diagnostic-20261112` no diagnóstico). Reverter somente os arquivos deste commit se necessário. Não há migration. Os metadados novos por mensagem podem permanecer: a versão antiga os ignora. Não apagar histórico, tarefas, mensagens, conclusões, identidade ou journal. Agent e Lifecycle não exigem rollback, pois não foram alterados.

## Validação final

- **511 testes Laravel, 3.943 asserções PASS**, PostgreSQL separado com FORCE RLS; rollback das migrations do banco descartável PASS.
- Concorrência em processos distintos: claims, corrida worker/result, exclusividade, fencing e idempotência PASS; fechamento de conversa concorrente/mensagem PASS.
- Cenário reproduzido com 18 segundos até a retirada e 1 segundo para a resposta: UNKNOWN pendente → DENIED autenticado → UNKNOWN/STALE após expiração, sempre sem executar sondagens.
- Telegram: ingresso/Runner/outbox com transporte HTTP falso, resposta curta, nenhuma chave de Profile no texto e nenhuma tarefa/diagnóstico indevido. Repetição do mesmo update não duplica mensagens nem planejamento.
- Web: metadados técnicos por mensagem, consulta correlacionada e acesso de outro tenant retornando 404; ausência de contexto RLS continua ocultando registros.
- Provas anteriores de ALLOWED atual, tampering, rejeição de prova vencida e revalidação no start permaneceram na regressão.
- Pint: **312 arquivos PASS**. Foram formatadas duas ocorrências já existentes (alias de controller na rota e um espaço em teste), sem mudar rotas, autenticação ou conteúdo da asserção.
- O teste antigo de capability anunciada passou a esperar o motivo específico `preflight_incompatible` para uma versão sem contrato. Foi acrescentada a asserção de autorização UNKNOWN; os bloqueios e a ausência de trabalho permanecem exigidos. A Web manteve o rótulo técnico de autorização não comprovada.
- Preview Blade com fixture sanitizada: seis combinações de viewport 390/768/1440 e tema claro/escuro; teclado e ausência de overflow PASS. Não representa uma navegação em produção.
- Imagens incrementais Web/worker construídas apenas em laboratório. Resposta produzida pela classe empacotada foi verificada, sem rede e sem sondagens.

Limite mantido deliberadamente: a primeira resposta continua imediata e não recebe uma atualização automática quando o Agent responder. Não se promete isso no texto. O usuário deve repetir o planejamento em instantes; uma nova avaliação pode aproveitar somente a prova ainda válida. A publicação dessa melhoria depende de autorização operacional posterior.
