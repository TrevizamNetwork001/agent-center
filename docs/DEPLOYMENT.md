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
