# Instalador do Agent 0.5.3

Fluxo atual: instalação sem código digitado, com solicitação na administração da plataforma e seleção do tenant ao aceitar. Ver AGENT_AUTOMATIC_ACCEPTANCE.md. As descrições de código manual abaixo documentam o fluxo anterior, ainda preservado para compatibilidade.

Página: https://agente.trevizamnetwork.com.br/instalar/
Script: https://agente.trevizamnetwork.com.br/instalar-agent.sh
Kit: https://agente.trevizamnetwork.com.br/downloads/agent/0.5.3/agent-center-agent-0.5.3.tar.gz

Kit original aprovado preservado. O wrapper confere SHA-256 do arquivo antes de extrair, executa o check do instalador e solicita o código em terminal interativo. Aguarda aprovação por até 900 segundos, consultando exclusivamente o recibo já emitido a cada 10 segundos. Não gera novo código ou aprova a si próprio. Após aprovação, o helper original habilita o serviço e verifica heartbeat/poll. Erros preservam config e identidade. Não há instalação de Agent na Central.

Painel mantém autenticação, CSRF, autorização e isolamento por tenant. Aceitar Agent envia identity_reviewed=1 como confirmação explícita do botão, com texto explicando a conferência da máquina. Nenhuma mudança no backend ou na emissão de credenciais. Administrador da plataforma com tenant selecionado recebe formulário no topo para voltar ao contexto da plataforma. Contas de cliente não recebem a ação; endpoint continua restrito a platform_admin.

Requer Debian 12/13 com /usr/bin/python3 3.13, systemd, curl, tar e terminal interativo. Downloads aprovados e endpoints exatos da API do Agent são públicos por HTTPS. Painel e demais rotas continuam sujeitos à allowlist administrativa. Cadastro exige código de uso único, recibo e aprovação; operação exige bearer token. Rate limits Laravel preservados. Kit não contém dados de tenant nem credenciais.

Implantação: imagem derivada de recovery9, contendo somente as quatro views alteradas; arquivos públicos servidos pelo mount existente do Nginx, habilitando index.html como índice adicional. Sem migrations ou alteração de banco, firewall e outros projetos.

Rollback: snapshot privado /opt/agent-center/backups/installer-053-<timestamp>/ guarda todos os arquivos anteriores e rollback.json com lista de arquivos novos. Restaurar os arquivos existentes do snapshot, remover somente arquivos novos listados e restaurar deploy/compose.yaml. Executar scripts/compose.sh up -d --no-deps web worker diagnostic-worker e docker exec agent-center-web-1 php artisan view:clear. Revalidar nginx -t e recarregar somente agent-center-nginx-1 se a configuração do índice for restaurada. Preservar volumes, dados e artefatos originais. Código público e kit precisam acompanhar futuras publicações.

Validação em 2026-11-05: 18 testes de Agent API/Operations, 286 assertions; regressão de retorno de contexto 1 teste, 10 assertions. Kit original --check PASS em CPython 3.13, sem instalação. Bash syntax e git diff --check PASS. Página, script, kit e readiness HTTPS200; downloads conferidos por SHA-256 após leitura pública; web, worker e diagnostic-worker healthy. Nenhum piloto real instalado. Snapshot de rollback: /opt/agent-center/backups/installer-053-20261105T123914Z/.

Publicação do acesso dos Agents autorizada explicitamente pelo operador após explicação do bloqueio automático e da restrição de fase do AGENTS.md. Exceção limitada à distribuição e aos endpoints existentes, sem criar features de domínio. Nginx validado e recarregado; regras administrativas mantidas. Leitura pública do instalador e SHA-256 conferidos. Heartbeat sem token HTTP401; enrollment inválido HTTP422. Snapshot: /opt/agent-center/backups/agent-public-access-20261105T130205Z/.
