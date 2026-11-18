# Acesso à atualização do Agent

A lista de Agents e o topo do detalhe agora mostram o link visível
**Atualizar Agent**, direcionado à seção `#agent-update` do mesmo Agent.
O link somente navega: criação e aprovação continuam sob as Policies, CSRF,
autorização e isolamento de tenant existentes.

A seção explica quando a conta não pode operar, quando há verificação pendente
e quando o atualizador remoto ainda não comunicou. Confirmar operação continua
disponível somente para administrador autorizado e operação PLANNED. Nenhuma
operação é criada ou aprovada pela navegação.

Teste de interface verifica acesso na lista/detalhe, pendência, ausência de
confirmação prematura, operador sem permissão de escrita e outro tenant com 404.
Validação Laravel/PostgreSQL isolado: AgentUpdateVisibilityTest e
AgentOperationsTest, 12 testes / 113 assertions PASS. `git diff --check` PASS.

Publicação: imagem `agent-center-central:agent-update-visibility`, derivada da
imagem operacional `dns-agent054-catalog`, altera somente cinco templates de
Agents. Recriar somente web e limpar views compiladas. Sem migrations,
mudança de identidade, atualização do Agent ou alteração de outros serviços.

Rollback: snapshot privado `backups/agent-update-visibility-publication/`.
Voltar a imagem somente do serviço web para
`agent-center-central:dns-agent054-catalog` em deploy/compose.yaml, executar
`scripts/compose.sh up -d --no-deps web` e
`scripts/compose.sh exec -T web php artisan view:clear`. Preservar quaisquer
outras alterações posteriores no compose. O catálogo 0.5.4 e a solicitação
PRECHECK_REQUESTED permanecem preservados.

## Solicitação repetida

Uma operação pendente ocultava a confirmação, mas o formulário ainda permitia
solicitar outra verificação. O controller devolvia 409. O formulário agora fica
oculto durante qualquer operação não terminal, e uma repetição por página antiga
redireciona ao andamento com mensagem, sem duplicar operação ou auditoria. A
verificação continua dentro da transação sob lock do Agent; Policies, tenant,
validação de release e aprovação do plano permanecem obrigatórios.

Publicação desse ajuste: imagem `agent-center-central:agent-update-conflict`,
derivada de `agent-update-visibility`, altera somente UpdateController e as views
detail/update-status. Snapshot privado `backups/agent-update-conflict-publication/`.
Rollback: voltar somente a imagem do web para
`agent-center-central:agent-update-visibility`, recriar somente web e executar
`php artisan view:clear` pelo scripts/compose.sh. Não apagar ou alterar a operação
pendente nem reverter o banco. A versão instalada do Agent não é alterada.

Validação do ajuste: 12 testes / 119 assertions PASS em PostgreSQL separado,
Pint PASS e diff check PASS. Após publicação, renderização do detalhe pelo
controller nativo com o mesmo autor autorizado retornou 200, mensagem de
verificação pendente presente e formulário duplicado ausente; uma única operação
preservada e versão instalada 0.5.3. Readiness HTTPS 200.

## Disponibilidade e progresso

A comparação numérica de versões exibe Atualizar Agent somente se houver uma
release posterior à versão instalada. Versão igual ou superior à última release
mostra “Não há atualização disponível”; versão desconhecida informa que a
comparação está indisponível. O formulário oferece somente versões mais novas;
rollback permanece uma ação separada. A comparação não depende da presença do
companion, que é apresentada separadamente. Dados de versão são relatos do Agent.

Uma solicitação em andamento mostra Acompanhar atualização. A barra reflete
etapas concluídas do protocolo: verificação, confirmação, instalação e conclusão.
Não representa percentual de bytes nem estima tempo. Só COMPLETED preenche todas
as etapas. A tela/fragmento existente atualiza a barra a cada consulta do painel;
nenhum job, comando remoto ou atualização é disparado pela visualização.

Publicação: `agent-center-central:agent-update-progress`, derivada de
`agent-update-conflict`, contendo ReleaseAvailability e sete templates de Agents.
Snapshot privado `backups/agent-update-progress-publication/`. Rollback: voltar
somente a imagem do web para `agent-center-central:agent-update-conflict`, recriar
somente web por scripts/compose.sh e executar php artisan view:clear. Sem
migrations, modificação de estado das operações, runtime ou configuração do Agent.

Validação: 13 testes / 150 assertions PASS em PostgreSQL isolado, incluindo
0.5.9 versus 0.5.10, versão atual e superior ao catálogo, versão desconhecida,
etapas reais no fragmento, autorização e acesso cruzado. Pint e diff check PASS.

## Apresentação simplificada

A seção mostra versão, barra/estado e uma orientação curta quando há bloqueio.
Releases, horários, códigos internos e evidências ficam em “Detalhes técnicos e
histórico”, fechado inicialmente. Evidência ausente não imprime null; erro ou
conclusão ausentes não geram linhas vazias. A confirmação do plano e seus dados
continuam visíveis fora dos detalhes quando PLANNED, com checkbox e autorização
originais. Não há alteração de estado, protocolo ou configuração do Agent.

Imagem `agent-center-central:agent-update-clean`, derivada de
`agent-update-progress`, modifica somente seis templates. Snapshot privado
`backups/agent-update-clean-publication/`. Rollback: voltar somente a imagem do
web para `agent-center-central:agent-update-progress`, recriar somente web por
scripts/compose.sh e executar php artisan view:clear. Preservar banco/operações.
Validação do layout simplificado: 13 testes / 151 assertions PASS em PostgreSQL
isolado, incluindo detalhes recolhidos, andamento, permissões e acesso cruzado.

## Porcentagem, animação e tela sem detalhes técnicos

A barra agora mostra 0%, 25%, 50% e 100% de acordo com etapas confirmadas do
protocolo, anima visualmente a transição e respeita movimento reduzido. Não é
percentual de download e não avança por tempo. Ao observar a mesma operação
passar para COMPLETED, o navegador exibe 100% e recarrega uma vez após 1,4 s.
Abrir uma operação já concluída não dispara recarregamento. Respostas de outro
tenant são rejeitadas antes de mudar a barra ou agendar reload.

Por solicitação do operador, “Detalhes técnicos e histórico” foi removido da
seção de atualização. A confirmação autorizada do plano permanece disponível.
Registros, auditoria e dados persistidos são preservados. Scrollbar da sidebar
oculto sem desabilitar rolagem. Assets passam a v12 para invalidar cache.

Publicação: `agent-center-central:agent-update-animation`, derivada de
`agent-update-clean`, com update-panel/update-progress/layout e assets públicos.
Snapshot privado `backups/agent-update-animation-publication/`: compose,
templates e assets anteriores. Os assets anteriores foram reconstruídos removendo
exclusivamente as adições desta rodada; demais alterações locais foram mantidas.
Rollback: restaurar agent-center.js/css desse snapshot em app/public, voltar
somente a imagem web para `agent-center-central:agent-update-clean`, recriar
somente web e executar view:clear via scripts/compose.sh. Não alterar operações,
secrets, banco, serviços não relacionados ou runtime do Agent.

Validação desta publicação: 13 testes / 156 assertions PASS em PostgreSQL
isolado. Chromium sem rede externa valida animação, porcentagens confirmadas,
recarregamento único, ausência de loop ao abrir COMPLETED, espera sem avanço,
rejeição de outro tenant e rolagem da sidebar com scrollbar oculto. O link
Acompanhar atualização pode manter foco sem interromper polling; formulários
em edição continuam protegidos contra substituição automática. Diff check PASS.
