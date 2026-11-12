# Publicação consolidada — Context Fix e Diagnostic Experience V2

**AGENT CENTER DIAGNOSTIC EXPERIENCE V2 PRODUCTION PASS**

Publicação executada sob autorização operacional expressa de 12/11/2026, exclusivamente na Central Laravel. Não houve nova sondagem de rede, acesso a equipamentos, alteração de Python Agent, Lifecycle, Observability, R2, quarentena da release 0.6.3 ou início de WhatsApp.

## Commits e imagem efetivamente publicados

Base de produção conferida: `54cf48f9fc1b89af0fda2be05b8f71896f93c316`. Integração por fast-forward até o candidato real `1918167835673c1e65c5555053284e581b9c7b1e`, sem reaplicar patches anteriores:

| Commit | Alteração homologada |
| --- | --- |
| `245a92c` | Resumo de diagnóstico baseado em evidências, acompanhamento durável e Web V2 |
| `ea9ccf3` | Vínculo entre pergunta e resposta curta e encerramento explícito |
| `63b14db` | Preservação do tom através da confirmação operacional |
| `c4d3242` | Regressão da pergunta pendente com conversa breve intercalada |
| `1918167` | Evidências, homologação e rollback de laboratório |

Imagem publicada: `agent-center-central:diagnostic-experience-v2-20261112`.

ID: `sha256:04c78a7c6c0a2ab12b7183e9d8d312a8c1e5e7d098d20b03ac16730e1694db49`.

Web, worker Laravel e scheduler Telegram executam essa imagem. O worker de diagnóstico permaneceu na imagem e no processo anteriores. A configuração operacional teve somente as quatro referências de imagem da Central atualizadas, incluindo o migrator inativo; nenhuma migration foi executada. Todos os arquivos locais não relacionados foram conferidos por hash e preservados.

## Gates antes da publicação

- Regressões completas repetidas: **471 testes, 3.466 asserções, 44 arquivos PASS** em PostgreSQL separado e papel de runtime com FORCE RLS.
- Concorrência do Task Engine e encerramento por inatividade em processos separados: PASS.
- Pint em 16 arquivos PHP alterados; build Vite; scan local por padrões de segredos; diff check: PASS.
- TLS positivo e negativo, PDO com `verify-full`, banco, fila → worker → cache e assets Nginx: PASS.
- Candidata exercitada contra o schema de produção com fixtures transacionais, respostas de sondas sintéticas e entrega Telegram capturada. Registros integralmente revertidos; nenhuma execução visível para o Agent real.

Os testes incluem as quatro classificações oficiais, fila/recebimento/execução, indisponibilidade, bloqueio de política, timeout, cancelamento, falha OpenAI/Telegram, duplicidade, confirmação, isolamento, RBAC/RLS, auditoria, RAG e proteção de diagnóstico ativo durante inatividade.

[Gates registrados](evidence/diagnostic-experience-v2-production-20261112/prepublish-tests.json).

## Backup e rollback verificados

Backup local novo: **`20261112T024720Z`**, criado sem podar gerações anteriores. Integridade do manifesto e restauração em banco separado aprovadas. Foram comparadas 16 tabelas protegidas, configuração do Agent, políticas RLS, imutabilidade e descriptografia da credencial OpenAI com a chave do backup. O banco temporário de restauração foi removido.

Dump: `/opt/agent-center/backups/database/20261112T024720Z.dump`.

Manifesto: `/opt/agent-center/backups/config/20261112T024720Z.sha256`.

Rollback preparado: `agent-center-central:diagnostic-v2-compatible-rollback-20261112`, ID `sha256:6909e71b85665a36fe721927b4ebfca94245599a3f3ee912f5a6e83094c017ae`.

A variante compatível restaura a apresentação e o comportamento conversacional anteriores, mantendo o tratamento de entrega necessário para concluir notificações V2 já persistidas sem duplicá-las. Os arquivos dessa imagem foram conferidos por hash no laboratório. Procedimento operacional: `/root/agent-center-diagnostic-v2-production-20261112/rollback.sh`. Atualiza exclusivamente Web, fila Laravel e scheduler; preserva banco, histórico, políticas, dados e serviço de diagnóstico. Não restaura dump por cima da produção nem executa rollback de migrations.

O script de publicação possuía rollback automático em falha crítica de rollout. **Rollback não foi necessário nem executado.** Replicação externa do backup não faz parte desta execução; R2 não foi acessado.

[Prova de restauração](evidence/diagnostic-experience-v2-production-20261112/restore-proof.json).

## Validação na aplicação publicada

A sequência foi executada em Web e Telegram usando as identidades nativas autorizadas e o gerador OpenAI real, em conversas de validação transacionais:

| Canal | Entrada | Resposta real gerada |
| --- | --- | --- |
| Web | “15 dias”, após “Há quanto tempo você está esperando resposta?” | “Você está esperando uma resposta há 15 dias. É uma espera longa. Sobre qual assunto você aguarda retorno?” |
| Telegram | Mesma pergunta e entrada, em mensagens separadas | “Entendi, você está há 15 dias esperando uma resposta. Essa demora é inconveniente. Sobre qual assunto você aguarda retorno?” |

Ambas retornaram `ASSISTED`. Foram duas chamadas na prova final publicada, `store=false`, sem ferramentas, máximo de 1.024 tokens de saída e dez segundos por chamada. A repetição do mesmo request key não duplicou mensagens. “Pode encerrar” fechou a conversa e preservou o histórico.

O caminho de diagnóstico foi exercitado no runtime publicado com Agent e respostas sintéticas dentro da mesma transação não commitada: confirmação pendente → aceitação real pelo Task Engine → fila → claim → início → resultado `NORMAL`. O acompanhamento editou a mensagem conhecida, preservou o botão autenticado e não acrescentou histórico repetitivo. “Marcha” não confirmou a operação. Encerramento com tarefa ativa exigiu a confirmação de cancelamento original. Inatividade de 16 minutos não encerrou a investigação ativa. Uma pergunta posterior não criou diagnóstico novo.

As fixtures nunca ficaram disponíveis ao Agent/worker real e foram revertidas. O vínculo Telegram original foi restaurado, e a contagem operacional permaneceu em **102 tarefas / 29 diagnósticos**. Nenhuma mensagem de teste foi enviada a um destinatário real; o transporte do adaptador foi capturado para conferir texto, edição e botão.

`/historico` passou pelo Ingress, Runner, outbox e Deliver publicados, com request autenticado pelo segredo do webhook em memória, entrega capturada e replay idempotente. O histórico continuou na rota autenticada da conversa original; todos os registros temporários foram revertidos.

[Prova operacional](evidence/diagnostic-experience-v2-production-20261112/smoke.json) · [Prova `/historico`](evidence/diagnostic-experience-v2-production-20261112/history-smoke.json).

## Exemplo com evidências reais já existentes

Foi apresentada novamente, sem reexecutar sondas, a conclusão de `youtube.com` do diagnóstico `24087c35-2e18-43fc-a943-bb98636b8ac4`:

- `NORMAL`: “Não foram encontradas anormalidades no escopo testado.”
- TCP estabelecido, tempo da sondagem **4,84 ms**.
- ICMP complementar: **5 de 5 respostas**, **0%** de perda de respostas, média **3,33 ms**.
- HTTPS: **HTTP 301**, redirecionamento recebido; destino do redirecionamento não testado; TLS validado; tempo da sondagem **153,01 ms**.
- DNS A e AAAA: um endereço retornado em cada consulta.
- Rastreamento de rota dispensado, apresentado separadamente das verificações realizadas.

O texto preserva a limitação do ponto de observação do Agent, não afirma saúde de toda a rede e não descarta a lentidão percebida pelo cliente. As medidas pertencem à execução já encerrada, não ao momento desta publicação. Classificação e evidências originais não foram alteradas.

[Web em produção — desktop claro](evidence/diagnostic-experience-v2-production-20261112/production-diagnostic-1440-light.png) · [Celular escuro](evidence/diagnostic-experience-v2-production-20261112/production-diagnostic-390-dark.png).

[Prévia do texto Telegram capturado](evidence/diagnostic-experience-v2-production-20261112/telegram-result-preview.png): representação da saída do adaptador publicado, explicitamente identificada como prévia. Não é screenshot de uma mensagem enviada pelo aplicativo Telegram.

## Saúde final

| Componente | Evidência final |
| --- | --- |
| HTTPS/readiness | Certificado validado e `/health/ready` retorna `ready` |
| Login/acesso | Página de login acessível; diagnóstico anônimo redireciona para login; sessão Laravel nativa autenticada aprovada |
| Interface | 24 capturas: diagnóstico, Telegram, Operations Health e histórico; 1.440/768/390, claro/escuro; sem overflow ou erro JavaScript |
| PostgreSQL | TLS `verify-full`; papel de runtime sem superuser/BYPASSRLS |
| FORCE RLS | Dez tabelas operacionais/Knowledge conferidas; sem contexto não há linhas de conversas/diagnósticos visíveis |
| Fila | Teste funcional aprovado; zero jobs pendentes e zero failed jobs no fechamento |
| Scheduler | Execuções periódicas observadas; `telegram:drain 1` e `conversations:close-idle 1` a cada minuto; política de 15 minutos preservada |
| Telegram | `getWebhookInfo` retorna `OK`, endereço HTTPS confere, zero updates pendentes e sem erro de webhook registrado |
| OpenAI | Conversação real `ASSISTED` nos dois canais; credencial preservada e descriptografável |
| RAG | Consulta nativa e isolamento preservados; índice vazio no tenant atual, já antes da publicação |
| Operations Health | **VERDE**, Agent online, worker de diagnóstico funcional e sem alteração de imagem/processo |

A primeira consulta read-only ao Telegram ficou inconclusiva por transporte; as consultas subsequentes retornaram HTTP 200/`OK`, sem mudança de rede ou configuração. Não houve regressão crítica de aplicação.

A sessão temporária de QA foi removida e o arquivo privado de cookie apagado. Os testes de captura foram ajustados durante a execução sem alterar o código homologado. Nenhuma credencial foi incluída nas evidências compartilhadas.

[Saúde final](evidence/diagnostic-experience-v2-production-20261112/health.json) · [Navegador](evidence/diagnostic-experience-v2-production-20261112/browser.json) · [Scheduler](evidence/diagnostic-experience-v2-production-20261112/scheduler-proof.json) · [Operations Health](evidence/diagnostic-experience-v2-production-20261112/production-operations-1440-dark.png).

## Limitações preservadas

1. O tenant 1 tem um item Knowledge e nenhum documento/chunk RAG indexado, tanto antes quanto depois desta publicação. A busca retorna `no_match`; não foram publicados ou indexados conteúdos fora do escopo autorizado.
2. O resumo V2 está ativo com composição determinística completa. O modo assistido de diagnóstico exige binding `diagnostic_explanation` publicado e as permissões específicas; o binding conversacional existente continua funcionando. Não foram habilitados novos bindings ou alteradas políticas de inteligência silenciosamente.
3. A entrega Telegram foi validada pelo adaptador publicado com captura, além do webhook real. Não se enviaram mensagens artificiais a usuários nem se executaram novas sondas para produzir evidência de publicação.

Dados protegidos, configuração do Agent, quarentena e alterações locais não relacionadas permaneceram idênticos por hash. [Prova de preservação](evidence/diagnostic-experience-v2-production-20261112/preservation.json) · [Fechamento consolidado](evidence/diagnostic-experience-v2-production-20261112/final-proof.json).

Entrega encerrada após validação. Nenhuma expansão de escopo foi iniciada.
