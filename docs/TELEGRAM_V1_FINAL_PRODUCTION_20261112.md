# AGENT CENTER TELEGRAM V1 FINAL PRODUCTION PASS

Publicação autorizada expressamente pelo usuário, limitada ao ajuste homologado `533eff8`.

Commit integrado: **`533eff8b52b48ddcbfd623fb5f10352adcdd8307`**, por fast-forward sobre `e48cc4e`. Não havia commits posteriores na produção. Todos os arquivos locais de trabalhos alheios foram conferidos por SHA-256 e preservados.

Imagem publicada: **`agent-center-central:telegram-final-v1-20261112`**, id **`sha256:56f4fc875eae0ee02693b7a105c430dc577dd54ceeab139ef989aef8411d185b`**. É a imagem homologada promovida sem rebuild. Única alteração de runtime: `Conversation/Engine.php`, SHA-256 `de59adfde7d722b458276195c3d7803fba0b9a89f20d3b21a2f41305cc43b5e0`, idêntico ao commit aprovado.

## Comportamento publicado

A criação de conversa Telegram deixa de inserir a apresentação institucional automática. `/nova` e o retorno após encerramento geram somente a resposta normal do diálogo, com tom adaptativo. A abertura Web conserva seu comportamento. Histórico, contexto autorizado, RAG/fontes, OpenAI, parser, confirmação explícita, idempotência, RBAC, FORCE RLS e auditoria permanecem preservados.

A política de **15 minutos** e a verificação **a cada minuto** não mudaram. O compose foi alterado exclusivamente para trocar quatro referências de imagem: Web, worker, scheduler e migrator. O migrator não foi executado. Flags, lista de tenants e demais configurações permaneceram idênticas.

## Prechecks, backup e regressões

Backup local novo **`20261112T012351Z`**, restaurado em banco isolado e removido ao final do verificador. Dezesseis tabelas protegidas idênticas, FORCE RLS e descriptografia da credencial OpenAI com a chave do backup: PASS. Sem replicação R2 adicional ou remoção de backups anteriores.

Homologação: **449 testes / 3.209 assertions / 42 arquivos**, Pint, build, interface, isolamento, RAG e concorrência aprovados. Reexecutados antes desta publicação **173 testes / 1.134 assertions**, incluindo Telegram, agendamento, Conversation Engine, Natural Conversation e RAG; concorrência separada do Task Engine e da inatividade: PASS. São reexecuções aplicáveis, não 622 testes distintos.

Prechecks e pós-publicação: configuração de produção, HTTPS/readiness, TLS PostgreSQL com `verify-full`, fila database→worker→cache, CSS/JS pelo Nginx: PASS. Web e worker saudáveis; scheduler executando ambos os comandos com `DONE` em minutos consecutivos. **Operations Health VERDE**, fila sem pendências/falhas na inspeção.

Webhook confirmado na API real por `getWebhookInfo`: **OK**, URL HTTPS correspondente à configuração, **zero atualizações pendentes**, sem `last_error_date`. Consultas iniciais do cliente retornaram `DELIVERY_UNKNOWN`; repetição nativa e leitura HTTP retornaram sucesso, sem alterar webhook, token, rede ou código do cliente. A verificação final foi aprovada.

## Teste operacional no runtime publicado

Ingress, ProcessUpdate, Runner, Conversation Engine, outbox e Deliver nativos foram exercitados com a identidade já verificada e Gate nativo, em uma transação revertida. O ponteiro da conversa real foi isolado apenas nessa transação para evitar cancelar ou modificar o atendimento existente; ao final, os registros de teste desapareceram e o vínculo original foi conferido como restaurado.

O envio de mensagens sintéticas ao usuário real foi capturado por um cliente de teste. Não houve envio sintético ao Telegram real nem sondagem em equipamentos. Duas chamadas OpenAI reais, **2.077 tokens informados**, saída limitada a 1.024 por chamada, `store=false`, sem tools e sem ampliar quotas.

| Entrada | Resposta observada |
| --- | --- |
| /nova | Olá! Tudo certo por aqui. Como vai? |
| boa noite ce ta bao? | Boa noite! Tudo bão por aqui, e cê? |
| Tô bão também, meu rei! Kkkkk | Que bom, meu rei! |
| boa noite meu chapa, após encerramento | Boa noite, meu chapa! Tudo certo por aqui. Como vai? |

Uma única resposta na abertura e no retorno; nenhuma apresentação institucional adicional. Continuidade adaptativa, replay sem duplicidade, histórico anterior preservado e `/historico` apontando para o novo chat: PASS.

Pedido de teste permaneceu em `WAITING_FOR_CONFIRMATION`; “marcha” não consumiu a confirmação. O parser preservou a confirmação explícita; confirmação atrasada após fechar não abriu tarefa. O consumo de confirmação e os diagnósticos foram cobertos pelos testes isolados, sem disparar sondagem real em produção.

O encerramento e o retorno foram verificados envelhecendo somente o chat temporário da transação. Nenhum timestamp de conversa real foi modificado. A proteção de diagnóstico ativo e o prazo periódico foram cobertos na homologação; o encerramento real anteriormente observado em 15min30s permanece compatível com a mesma política, que não foi alterada nesta publicação.

Navegador autenticado: dezesseis combinações de páginas/tamanhos/temas, incluindo histórico, Telegram e perfil, sem overflow ou erros JavaScript. Sessão temporária e arquivo privado de cookie removidos.

## Preservação e rollback

Tarefas **97** e diagnósticos **28**, sem alteração. Configuração do Agent, tabelas de releases e quarentenas, incluindo a release 0.6.3, preservadas. Contêineres de diagnóstico, PostgreSQL e Nginx mantiveram os mesmos IDs e horários de início. Python Agent, Lifecycle, Observability, R2 e equipamentos não foram modificados. Não houve trabalho em WhatsApp, migrations ou grants de produção.

Rollback preparado em **`/root/agent-center-telegram-final-production-20261112/rollback.sh`**, sintaxe validada. Aceita somente a imagem desta release e restaura as referências para `agent-center-central:speaking-idle-v1-20261112`, conservando flags, política de 15 minutos, banco, histórico, vínculos e autorizações. Atualiza somente Web/worker/scheduler e limpa views. Não foi executado porque as validações finais passaram. Não restaurar dump nem executar rollback de migrations para reverter esta alteração de código.

Evidências sanitizadas: [evidence/telegram-final-production-20261112](evidence/telegram-final-production-20261112). Artefatos completos e scripts privados: `/root/agent-center-telegram-final-production-20261112/`. Secrets, cookies, sessões e arquivos de ambiente não estão no Git. Varredura local de padrões de secrets e `git diff --check`: PASS; a varredura não usa gitleaks.
