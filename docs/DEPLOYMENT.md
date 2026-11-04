# Deploy e rollback

## Pré-requisitos

Linux com Git, Docker Engine/Compose plugin, curl, openssl, CA certificates, tar/gzip e flock. Não instalar PHP global. Inventário desta VPS feito antes de alterações; dependências já disponíveis. Não alterar serviços de outros projetos.

## Primeiro deploy

1. Criar `/opt/agent-center/{app,deploy,docs,agent,backups,scripts}` e obter este repositório.
2. `umask 077; cp .env.example .env; chmod 600 .env`. Preencher POSTGRES_PASSWORD e APP_DB_PASSWORD com saídas independentes de `openssl rand -hex 32`; repetir APP_DB_PASSWORD em DB_PASSWORD. Gerar APP_KEY com prefixo `base64:` e saída de `openssl rand -base64 32`. Não imprimir nem colar os valores em logs.
3. Manter APP_ENV=production, APP_DEBUG=false, DB_CONNECTION=pgsql. TZ=Europe/Berlin no banco/PHP/app. APP_URL deve refletir acesso real. `.env.example` é somente template.
4. `./scripts/compose.sh config --quiet` (não imprimir config renderizada, pois contém secrets).
5. `./scripts/compose.sh build web` e `./scripts/compose.sh up -d postgres web`.
6. `./scripts/compose.sh --profile operations run --rm --no-deps migrator
./scripts/db-grants.sh`.
7. `./scripts/compose.sh up -d --wait --wait-timeout 180`.
8. Validar health e testes descritos abaixo; executar backup e réplica externa.

O bootstrap do usuário da aplicação só roda quando o volume PostgreSQL está vazio. Alterar `.env` depois não troca senhas no banco existente; usar procedimento SQL controlado com backup e atualizar os consumidores. Não excluir volume para trocar credenciais.
Não usar `docker compose down -v`, `docker system prune` ou comandos globais.

## Validação

```sh
./scripts/compose.sh config --quiet
./scripts/compose.sh ps
./scripts/compose.sh exec -T web php artisan --version
./scripts/compose.sh exec -T web php artisan migrate:status
curl --fail http://127.0.0.1:18083/health/live
curl --fail http://127.0.0.1:18083/health/ready
./scripts/test.sh
./scripts/queue-smoke.sh
git diff --check
./scripts/secret-scan.sh
```

Testes montam o `.env` real somente para leitura, com APP_ENV e database de teste sobrescritos explicitamente e DB_DATABASE fixado no phpunit.xml. Executam em container temporário do mesmo PHP com dependências de desenvolvimento do lockfile; não instalam pacotes dev no runtime. Readiness é testado também sob falha simulada do banco, com resposta genérica. Uma migration de teste executa e faz rollback em database separado, nunca no database de produção.

## HTTPS futuro

HTTP é local e sem dados de negócio. Para acesso remoto atual, usar túnel SSH: `ssh -L 18083:127.0.0.1:18083 usuario@host`.
Após informar domínio e DNS, provisionar certificado e configurar proxy/TLS específico do projeto, sem substituir Nginx global. Atualizar APP_URL, SESSION_SECURE_COOKIE=true e proxies confiáveis explicitamente. Só ativar HSTS após HTTPS validado. Nenhum certificado ou domínio foi inventado.

## Rollback

Antes de mudança: backup, commit/tag e guardar imagem corrente com tag específica (`docker image tag agent-center-central:foundation agent-center-central:rollback-<COMMIT>`). Não remover imagem anterior até validar o novo deploy.
Para parar esta fundação: `./scripts/compose.sh stop`; para remover somente containers/redes deste projeto: `./scripts/compose.sh down` (sem `-v`). Volumes e arquivos permanecem.
Para desfazer uma atualização: voltar ao commit anterior, definir a imagem previamente retida no Compose e recriar apenas web/worker/nginx. Inspecionar migrations antes; não fazer rollback de schema automaticamente. Se houver incompatibilidade, restaurar backup em database/volume separado conforme DISASTER_RECOVERY.md e então trocar a configuração.
A criação inicial não alterou serviços globais. A reversão mantém `/opt/agent-center`, `.env` e volumes para permitir recuperação; exclusão definitiva exige ação explícita do operador.

## RECOVERY-1

Consultar AUTH_AND_TENANCY.md para bootstrap interativo e rollback sem perda de auditoria. A UI atual usa Blade sem build JS; credenciais de administrador não são criadas automaticamente. Imagem de rollback: agent-center-central:recovery1-rollback-b1a2e9e.

## RECOVERY-2

Antes do primeiro deploy desta fase: prover RUNTIME_DB_USER/PASSWORD aleatórios separados do migrator, recriar apenas postgres do projeto para disponibilizar o novo ambiente e aguardar health, executar scripts/db-grants.sh. Nenhuma senha é versionada. Gerar .env.migrator privado com scripts/create-migrator-env.py quando necessário para testes. Ver DATABASE_ROLES.md.

Migrations não podem mais rodar como web/worker. Usar profile operations/migrator e reaplicar grants. Após migração, ativar imagem recovery2, aguardar health e validar login/Agent API. Unit Python foi validada, sem habilitação global automática; produção exige URL HTTPS/identidade fornecida pelo operador.

Rollback de Central: imagem retida agent-center-central:recovery2-rollback-da20d9c. Reapontar somente web/worker e recriar com --no-build; preservar schema/roles atuais, sem down de migrations que apagaria Agents/enrollments/auditoria. Banco anterior só em restore separado com validação e troca explícita de database. Os dados do Agent não são restaurados indiscriminadamente ao trocar runtime; seguir AGENT_OPERATIONS.md.

## RECOVERY-3 — deploy e rollback

Validar imagem e suíte no lab antes. Backup com `./scripts/backup.sh`; preservar imagem anterior com `docker tag agent-center-central:recovery2 agent-center-central:recovery3-rollback-ada5edb`. Construir recovery3, executar migrator e db-grants, subir web/worker/diagnostic-worker; recarregar apenas Nginx do projeto para resolver novamente o upstream FPM. Verificar health web/ready e health do worker.

```sh
./scripts/compose.sh --profile operations run --rm --no-deps migrator
./scripts/db-grants.sh
./scripts/compose.sh up -d --no-deps --no-build web worker diagnostic-worker
./scripts/compose.sh exec -T nginx nginx -s reload
```

Rollback preservando evidências:

```sh
./scripts/compose.sh stop diagnostic-worker
./scripts/compose.sh -f deploy/rollback-recovery3.yaml up -d --no-deps --no-build web worker
./scripts/compose.sh exec -T nginx nginx -s reload
```

Não remover volumes nem executar down de migrations em produção. Manter a nova imagem para investigação. Agents sem opt-in de protocolo continuam sem receber tarefas; para interromper Agents novos, parar serviços locais com procedimento autorizado. Nunca restaurar journal antigo sobre state atual.

## RECOVERY-4

Preservar recovery3 como agent-center-central:recovery4-rollback-1f36c51. Aplicar a migration aditiva de índices com migrator, subir a imagem recovery4 em web/worker/diagnostic-worker e recarregar somente o Nginx do projeto. Validar live/ready, assets locais e health do worker.

Rollback sem apagar evidências:

```sh
./scripts/compose.sh -f deploy/rollback-recovery4.yaml up -d --no-deps --no-build web worker diagnostic-worker
./scripts/compose.sh exec -T nginx nginx -s reload
```

A imagem anterior possui seu layout inline; assets novos não são exigidos por ela. Índices novos podem permanecer. Não executar migrate:rollback em produção nem remover volumes.

## RECOVERY-5

Preparar chave com `python3 scripts/create-llm-key.py` (não sobrescreve existente); backup da chave é obrigatório. Preservar recovery4 como recovery5-rollback-de7d25f. Aplicar migration com migrator, reaplicar db-grants, atualizar web/worker/diagnostic-worker e recarregar Nginx do projeto. LLM_ALLOWED_HOSTS fica vazio até configuração autorizada; não há provider real seedado.

Rollback operacional: `./scripts/compose.sh -f deploy/rollback-recovery5.yaml up -d --no-deps --no-build web worker diagnostic-worker`, seguido de reload Nginx. Preservar schema, versões, ciphertext e master key; não executar migration down ou restaurar DB antigo sobre dados novos.

## RECOVERY-6: Conversation e HTTPS

Imagem `agent-center-central:recovery6`; migration aditiva nona, total 27 tabelas FORCE RLS. Apply via container migrator e reaplicar `scripts/db-grants.sh`; runtime continua sem ownership. Agent 0.5.2 não é atualizado nesta fase. Feature flag de Conversation continua desabilitada por ausência de settings; habilitar apenas tenant autorizado após validação em sua tela de conversas.

O A de agente.trevizamnetwork.com.br resolve para 169.58.239.73; AAAA para 2a02:c207:2370:5362::1, ambos nesta VPS na verificação desta fase. Certificado Let's Encrypt emitido em 04/11/2026, válido até 02/02/2027. Vhost dedicado em `deploy/nginx/host`, sem upstream FastAPI. HTTP redireciona para HTTPS, exceto HTTP-01; TLS1.2/1.3, HSTS curto e headers seguros. Sem alteração de firewall ou vhosts de outros projetos.

Inventário inicial de produção: zero tenants, usuários e Agents. Após autorização do e-mail pelo usuário, bootstrap Artisan nativo criou um tenant Agent Center e um platform_admin Administrador com troca obrigatória; senha forte temporária em `secrets/bootstrap-admin-password` (0600/root), nunca impressa. Remover o arquivo após a troca. Nenhum fixture foi copiado para produção; continuam zero Agents. A validação autenticada e o Agent real são exclusivamente do laboratório separado.

Gate de recuperação em `/etc/nginx/snippets/agent-center-access.conf` permite somente loopback e os endereços desta VPS. Aplicação permanece restrita porque ainda não existe destino externo autorizado/verificado de backup. Não confundir certificado emitido ou health local HTTPS com publicação liberada para usuários externos.

Pré-requisito para liberar acesso público: definir destino externo e responsável, exportar banco/config/Git bundle e key ring separadamente, confirmar integridade e disponibilidade de recuperação, revisar rollout/usuários e então atualizar apenas o access snippet deste vhost. Não remover o gate como efeito colateral de renovar certificado ou fazer deploy. O template versionado contém a restrição deliberadamente.

Configuração privada aplicada: APP_URL=https://agente.trevizamnetwork.com.br, APP_DEBUG=false, SESSION_SECURE_COOKIE=true e TRUSTED_PROXY_CIDRS com IP exato do gateway `agent-center_frontend` (atualmente 172.21.0.1). Nunca usar `*` nem confiar em headers de origem pública. Se recriar a rede, inspecionar o novo gateway e atualizar o env. Edge sobrescreve Host/Forwarded headers; PostgreSQL/FPM/worker continuam sem publicação externa. Não usar API de Agent por HTTP fora do lab loopback.

Instalação específica: copiar vhost e access snippet aos destinos indicados, `nginx -t`, reload gracioso. Certbot usa webroot `/var/www/agent-center-acme`; hook exclusivo `/etc/letsencrypt/renewal-hooks/deploy/renew-agent-center.sh` verifica e recarrega Nginx. Timer certbot existente permanece em uso. Emissão por conexão direta foi necessária porque o proxy de saída da sessão rejeitou ACME; não houve alteração global do proxy. Para certbot interativo nesta sessão, remover variáveis de proxy só do processo. Fontes: [Laravel trusted proxies](https://laravel.com/framework/docs/requests#configuring-trusted-proxies), [Let's Encrypt HTTP-01](https://letsencrypt.org/docs/challenge-types/).

Rollback: tag preservada `agent-center-central:recovery6-rollback-0bc1379`, overlay `deploy/rollback-recovery6.yaml`. Desabilitar Conversation no tenant, usar overlay somente nos serviços web/worker/diagnostic-worker, preservar schema/histórico/keys e reaplicar grants. Nunca migrate:rollback na produção. Manter gate HTTP(S) e revalidar sessão/health; o runtime anterior não contém a nova configuração de trusted proxies. Se necessário retornar acesso somente loopback com o env privado do backup pré-deploy; não publicar HTTP ao fazer rollback. Certificados e dados não devem ser removidos.

Backups agora também incluem somente o certificado/material TLS deste projeto em arquivo privado separado. O restante do Let's Encrypt, certificados de outros sites e serviços da VPS não entram nesse pacote. Certificado pode ser reemitido após validar DNS/ACME; APP_KEY e master key de LLM precisam ser recuperadas do backup correto.
