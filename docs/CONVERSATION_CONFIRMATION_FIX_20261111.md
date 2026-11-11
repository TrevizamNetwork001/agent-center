# Conversation confirmation — revisão e rollout preparado

Escopo: correção determinística na Conversation Engine Laravel existente. Produção não publicada nesta entrega. Checkout isolado: /root/conversation-confirmation-fix, base c18c5c4. O checkout operacional contém outras alterações e não foi modificado.

## Causa

IntentDetector verificava palavras como “diagnóstico” e “verificar” antes da confirmação e aceitava somente respostas afirmativas curtas com correspondência integral. “Sim, confirmo o diagnóstico de google.com.” retornava START_DIAGNOSTIC e a Engine substituía pending, permanecendo em WAITING_FOR_CONFIRMATION e repetindo a pergunta. Não era uma decisão do LLM.

## Correção

Respostas afirmativas de gramática restrita são reconhecidas antes de palavras de diagnóstico. A intenção CONFIRM só autoriza diagnóstico quando a conversa está em WAITING_FOR_CONFIRMATION e possui contexto pending. Fora desse estado, inclusive depois de conclusão, uma confirmação não vira novo pedido de diagnóstico. Negação e cancelamento mantêm precedência.

O contexto pendente fornece perfil/destino/MTR; não há inferência de perfil a partir da resposta. Um destino explícito deve corresponder ao destino pendente após validação/canonicalização pelo Planner, inclusive URL e IPv6. Dados pendentes ausentes/invalidos ou destino diferente invalidam a confirmação e não usam o alvo antigo como fallback. A tentativa consome pending e registra confirmation_consumed, com transição para FOLLOW_UP antes de iniciar. O sucesso passa para DIAGNOSTIC_RUNNING pelo Tasks::create existente, mantendo histórico e vínculo do diagnóstico anterior. Bloqueios persistem a mensagem com a causa real e exigem nova solicitação. Nenhuma Skill é executada pelo LLM.

Consumo, histórico e criação de diagnóstico compartilham a transação e os locks existentes por usuário e conversa. request_key/digest já persistidos impedem replay HTTP, inclusive da confirmação antiga enquanto outra pendência existe. Uma segunda mensagem com nova chave durante execução ou após conclusão não inicia outra tarefa. Cancelamento/encerramento removem pending e confirm_cancel; negação também invalida ambos. GET/refresh e sync não criam diagnósticos. Uma nova resposta afirmativa enviada com chave nova diante de uma nova solicitação de confirmação é tratada como resposta à pendência atual; o texto isolado não identifica a geração de uma solicitação anterior.

A interface existente renderiza o pedido, a resposta, “Confirmação processada”, encaminhamento/bloqueio e a conclusão operacional quando disponível. Nenhum template, CSS ou JS alterado.

## Rollout (somente após autorização operacional específica)

Candidato local: agent-center-central:conversation-confirmation-20261111. Dockerfile e overrides em deploy/conversation-confirmation/. Base exata: agent-center-central:rag-v1-final-20261111, ID sha256:fcfa83717242a742a5c51ae36999166c055e47aa0144f2aef18dc6874d98efda. O build acrescenta apenas Engine.php e IntentDetector.php; não promover o restante do checkout operacional ou desta cópia. Não há migration, alteração de schema, dependência, configuração de provider ou tarefa em equipamento.

Antes de executar: reconciliar a imagem web ativa e seu ID com a base registrada, confirmar que os dois arquivos em produção ainda coincidem com a versão anterior do commit, verificar readiness/HTTPS/Operations Health, verificar o backup local com restauração já documentado e obter nova geração se exigida pela janela operacional. Registrar estado da imagem/Compose e filas. Se a base tiver mudado, parar e reconciliar, sem sobrepor outra publicação. Verificar o ID da base antes de reconstruir com --pull=false --network=none. Copiar apenas overrides aprovados para a área de deploy. Conferir docker compose config --quiet com o mesmo env e compose operacionais antes de recriar.

Com os overrides disponibilizados no host (comandos preparados, NÃO executados):

```sh
cd /opt/agent-center
docker compose --env-file .env -f deploy/compose.yaml -f /root/conversation-confirmation-fix/deploy/conversation-confirmation/rollout.yaml up -d --no-deps --no-build web
```

Validar readiness/HTTPS/login e Operations Health. Conferir imagem ativa, histórico de conversa, transições e ausência de duplicações. Não usar diagnóstico em Agent real como smoke sem autorização específica de operação/destino; a validação funcional desta entrega usa PostgreSQL e Agents sintéticos de laboratório. Não repetir inferência paga da OpenAI.

## Rollback preparado

Se ocorrer regressão atribuível ao candidato, recriar somente web com a imagem anterior preservada:

```sh
cd /opt/agent-center
docker compose --env-file .env -f deploy/compose.yaml -f /root/conversation-confirmation-fix/deploy/conversation-confirmation/rollback.yaml up -d --no-deps --no-build web
```

Revalidar readiness/HTTPS/Operations Health e registrar a reversão. Não restaurar banco inteiro, não apagar conversas, eventos, diagnósticos ou tabelas RAG. Nenhuma migration down. Diagnósticos já criados continuam sob o Task Engine existente; rollback não reconstitui a autorização já consumida. Não reescrever pending manualmente. O código antigo mantém a limitação original para novas confirmações naturais; registrar isso caso o rollback seja necessário.

Agent 0.6.2, Lifecycle Manager, RAG V1, Provider OpenAI, release em quarentena, R2 e equipamentos não foram alterados. Workers e nginx não entram no rollout.

## Validação

Ambiente: PostgreSQL separado rag-v1-postgres, rede rag-v1-lab, banco descartável agent_center_test, roles owner/runtime distintas e FORCE RLS. O runner cria o banco, aplica migrations/grants de teste, executa PHPUnit, verifica rollback e remove somente o banco que criou. Não utiliza o PostgreSQL de produção.

Comando reproduzível, da cópia isolada:

```sh
TEST_PG_CONTAINER=rag-v1-postgres TEST_NETWORK=rag-v1-lab TEST_OWNER_ENV=/root/rag-v1-lab/owner.env TEST_RUNTIME_ENV=/root/rag-v1-lab/runtime.env TEST_CA_FILE=/root/rag-v1-lab/no-ca scripts/test.sh
```

Pré-requisito: vendor pelo composer.lock e assets Vite compilados em app/public/build. O clone limpo não continha esses assets ignorados pelo Git: a primeira regressão apresentou 10 falhas de renderização, todas por Vite manifest ausente. Os assets já compilados da imagem ativa foram copiados somente para o checkout de testes, sem modificar fontes/assets de produção; a regressão final usa esse pré-requisito. As fixtures iniciais de Agent indisponível/status e consulta do último histórico entre várias conversas também foram corrigidas antes da rodada final.

CONVERSATION CONFIRMATION FIX PASS — validação em laboratório, sem publicação em produção.

- Regressão final completa: **319 testes / 2.346 assertions PASS**, código de saída 0, incluindo **36 testes de conversa (12 novos)**. Migrations/up/down e remoção do banco descartável concluídos pelo runner.
- Frase exata do operador reproduzida antes da correção como START_DIAGNOSTIC; após a correção, CONFIRM, confirmation_consumed e DIAGNOSTIC_RUNNING, com um único diagnóstico e tarefas pelo Engine existente.
- Confirmações curtas/naturais, negação, cancelamento e encerramento, destino divergente/IPv6, dados pendentes ausentes/inválidos, Profile desabilitado, Skills negadas, Agent revogado, retorno operacional policy_denied, replay com mesma chave, duplicação com chave nova, confirmação depois da conclusão, refresh, isolamento por tenant/usuário, RBAC e CSRF: PASS. Histórico, resultado, sequência e persistência conferidos.
- Apresentação verificada por respostas HTTP Laravel e conteúdo renderizado do histórico/resultado; não foi realizada nova sessão de navegador em produção nem diagnóstico em equipamento.
- Pint nos três arquivos PHP: PASS. Sintaxe PHP da imagem candidata: PASS. git diff --check: PASS. Scan básico do índice Git executado antes do commit.
- Imagem candidata final: sha256:44439b47357d2037461492497feb05ccfad131ae0682cd563ed98c59c91ab0d2. Todas as camadas da base preservadas; duas camadas COPY; hashes dos dois arquivos correspondem às fontes testadas.
- Produção permaneceu na imagem rag-v1-final-20261111 saudável. Nenhum compose up/deploy foi executado nesta entrega.

Evidências em docs/evidence/conversation-confirmation-20261111/.
