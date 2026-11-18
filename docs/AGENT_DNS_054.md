# Agent 0.5.4 — identificação de bloqueio DNS

O pacote deriva do artefato 0.5.3 verificado por SHA-256, incluindo onboarding,
telemetria e updater existentes. Build reproduzível:
`scripts/build-agent-dns-release.py` (Python somente para tooling do Agent).

Quando um IP retornado pelo resolver viola `allowed_networks`, a consulta inteira
retorna `policy_denied`, sem respostas parciais, IPs rejeitados ou exceções privadas.
Demais exceções continuam como `action_failed`. Nenhuma allowlist é ampliada.
Isso corrige a classificação do bloqueio; não comprova a causa da execução antiga
e não garante resolução DNS com a configuração local atual.

SHA-256 do pacote: `bb8c0274b9fc2121c283136b2ad66a9b67037c0c7d32e44f79f44b5d33f5cb19`.
Configuração, identidade, schema de state/journal e freeze são preservados.
Sem migrations ou alteração de equipamentos.

Validação: seis regressões DNS na fonte e no zipapp candidato; self-check do
zipapp anuncia 0.5.4, schemas 1 e freeze=true. Suíte da fonte: 46 testes PASS na
repetição. Primeira rodada falhou no teste preexistente de timeout/heartbeat,
que assume que o último evento concorrente é um resultado; nenhum ajuste nesse
teste ou no executor foi feito para ocultar a falha.

Laravel/PostgreSQL isolado: AgentOperationsTest e DnsPolicyRegressionTest,
23 testes / 114 assertions PASS, incluindo autorização e isolamento de tenant.

## Distribuição e bloqueios

O catálogo da Central apenas disponibiliza a release para seleção no painel.
O companion não baixa código: exige catálogo root-owned e arquivo previamente
disponibilizado em `/opt/agent-center-agent/releases/<sha256>.pyz` no host.
Não há comunicação do companion registrada para o Agent `teste` na inspeção.
Solicitar precheck não significa instalar ou autorizar a aplicação sem plano.
Não marcar operação COMPLETED ou versão atualizada sem retorno real do Agent.

Publicação em 2026-11-05: catálogo 0.5.4 disponibilizado na imagem
`agent-center-central:dns-agent054-catalog`, somente web recriado. Operação
`6df05f40-deca-4819-b44a-cbab73aa091b`, tenant 1, Agent `teste`, de 0.5.3 para
0.5.4, solicitada pelo controller nativo do painel com validação, Gate e auditoria,
usando o autor do diagnóstico reportado. Estado observado: PRECHECK_REQUESTED;
companion ainda sem comunicação. Nenhuma aprovação de plano ou aplicação emitida.
A primeira resolução do autor parou por escopo antes de criar operação; a leitura
seguinte usou somente o ID do autor já conhecido e contexto nativo de usuário.
Não se criou sessão, credencial ou usuário e não se removeu RLS ou global scopes.

## Rollback

Snapshot privado da publicação em `backups/agent-dns054-publication/` contém
compose e catálogos anteriores. A imagem candidata deriva exclusivamente da
imagem operacional `agent-center-central:dns-policy-fix`, trocando o catálogo.
Para retirar a release, restaurar o catálogo anterior e voltar somente a imagem
do serviço web para `agent-center-central:dns-policy-fix`, recriando apenas web
com `scripts/compose.sh up -d --no-deps web`. Preservar outras alterações do
compose feitas posteriormente. Worker, banco e demais serviços não são alterados.

Se a instalação remota ocorrer no futuro, usar o rollback controlado do painel
com a release anterior verificada, precheck e backup local do updater. Não
restaurar banco global, apagar identidade ou alterar política para forçar sucesso.
