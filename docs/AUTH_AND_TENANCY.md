# RECOVERY-1 — identidade, tenancy, RBAC e auditoria

Base: commit b1a2e9e. Arquitetura preservada: Browser → Nginx → Laravel Central → PostgreSQL; Agent Python separado é somente arquitetura futura. Nenhum Agent, Agent API, diagnostics ou módulo operacional foi implementado. AGENTS.md permanece inalterado; esta fase foi autorizada explicitamente pelo usuário.

## Identidades e roles

`tenants`: name, slug único, status active/disabled e timestamps.
`users`: tenant_id, name, email globalmente único sem distinção entre maiúsculas/minúsculas, password, role, status, must_change_password, last_login_at e timestamps. Campos nativos adicionais do Laravel são preservados. CHECK constraints validam statuses/roles e exigem tenant para usuários de tenant. Somente platform_admin possui tenant_id NULL.

| Role | Permissões nesta fase |
|---|---|
| platform_admin | Dashboard da plataforma; criar/editar/desabilitar tenants; selecionar tenant ativo explicitamente; no tenant selecionado, criar e editar tenant_admin/operator/viewer; ver auditoria do contexto selecionado |
| tenant_admin | Dashboard do próprio tenant, listar usuários do tenant, criar/editar/desabilitar operator/viewer; auditoria do próprio tenant |
| operator | Dashboard, contagem de usuários ativos do próprio tenant, seus próprios eventos recentes e troca da própria senha |
| viewer | Mesmo acesso de leitura de operator nesta fase; nenhuma ação operacional existe |

Nenhuma UI permite criar outro platform_admin, editar/desabilitar a própria conta administrativa, transferir usuários entre tenants, promover tenant_admin por outro tenant_admin ou excluir identidades/auditoria. O bootstrap cria somente o primeiro platform_admin. Não há permissões dinâmicas nem superadmin implícito em Policies.

## Auth e sessões

Login/logout usam Laravel Auth, SessionGuard e EloquentUserProvider nativos. O callback documentado `withQuery` do provider retira somente o scope de domínio durante lookup de identidade; não substitui checagem de senha, sessão, Hash ou CSRF. RLS continua ativo durante o lookup, limitado ao email normalizado no login ou ao ID proveniente da sessão Laravel. `attemptWhen` valida o tenant ativo antes de login/rehash.

- Senhas: Laravel Hash Argon2id; memória 65536 KiB, time cost 4, uma thread; sem hash customizado. Work factors reduzidos apenas no ambiente de testes.
- Política: 14–128 caracteres, maiúscula, minúscula, número e símbolo; confirmação e senha atual obrigatórias na troca; nova senha deve diferir da atual.
- Senha temporária de toda conta nova exige troca antes de qualquer dashboard/administração. Apenas trocar senha e logout ficam disponíveis.
- Login válido regenera sessão/token, grava last_login_at e auditoria. Troca de senha regenera sessão e revoga outras sessões no PostgreSQL. `auth.session` nativo detecta hashes alterados.
- Logout via POST, protegido por CSRF, invalida a sessão e regenera token. Não existe logout por GET.
- Cookies HttpOnly, SameSite=Lax, sessão criptografada no PostgreSQL, inatividade padrão de 30 minutos e expiração ao fechar navegador.
- Secure cookie ainda false exclusivamente porque serviço é HTTP em loopback. Antes de qualquer acesso público: TLS, APP_URL HTTPS e SESSION_SECURE_COOKIE=true. Não expor HTTP de autenticação na internet.
- Login: 5 tentativas por identidade em 60 segundos, mais 30 tentativas por IP em 60 segundos; cache database compartilhado entre processos. Identificadores das chaves usam HMAC e não armazenam email/IP em texto. Resposta 429 com Retry-After.
- Conta/tenant disabled bloqueia login e sessões existentes. Desabilitar via UI revoga sessões correspondentes; middleware revalida status em cada request.
- Mensagem de credenciais inválidas é genérica; senha nunca retorna no HTML nem em old input. Sem cadastro público, MFA ou recuperação por email nesta fase.

## Isolamento em camadas

1. Middleware ResolveTenant roda depois de StartSession e antes de Auth/Bindings. Resolve identidade da sessão, ignora seleção de tenant para contas comuns e aceita contexto selecionado somente para platform_admin. Sempre limpa variáveis da conexão em finally, inclusive diante de erros.
2. Scopes globais de User/AuditEvent restringem consultas ao contexto. Sem contexto não há leitura de domínio. Platform_admin precisa selecionar tenant para páginas de usuários; sem seleção vê apenas seu contexto de plataforma.
3. Policies de User/Tenant e Gate de auditoria, com FormRequests, verificam ator, role, contexto e recurso. Bindings de usuários usam scope e retornam 404 para outro tenant. Campos sensíveis não são mass assignable; tenant_id/role/status nunca vêm de um fill genérico do request.
4. PostgreSQL RLS habilitado com FORCE em users, tenants e audit_events, testado sob role real não superuser e sem BYPASSRLS. SELECT e escrita fora do contexto são negados mesmo quando scopes Eloquent são removidos. Platform_admin possui autorização explícita para administrar tenants, mas as páginas continuam limitadas ao contexto escolhido.

Variáveis `app.tenant_id`, `app.user_id`, `app.platform_admin` e `app.auth_email` são estabelecidas exclusivamente pelo código de confiança. A janela de email de autenticação é removida imediatamente após a tentativa. Esse mecanismo protege contra esquecimento de filtros; não é uma defesa contra execução arbitrária de SQL com a credencial da aplicação. A role de runtime ainda é proprietária das tabelas por ser a role de migrations da fundação; quem obtiver essa credencial pode alterar DDL/RLS. Separar role de migrations e runtime antes de ampliar a superfície de produção.

Sessions/cache/jobs são infraestrutura nativa global, inacessível por endpoints de domínio. Nenhum job de tenant foi adicionado. Recursos futuros devem carregar tenant explicitamente e estabelecer contexto antes de consultas; não herdar contexto de outro job ou request. Não usar conexões persistentes/pooling transacional sem revisar o ciclo de contexto.

## Bootstrap seguro

No terminal da VPS:

```sh
cd /opt/agent-center
./scripts/compose.sh exec web php artisan agent-center:create-admin
```

Informar email, nome, nome/slug do tenant inicial e senha temporária forte. O prompt de senha é oculto, não a imprime e não aceita senha em argumento/env/arquivo. Não redirecionar uma sessão interativa para logs. Nenhuma senha padrão ou conta real é criada por deploy, seeder ou testes. Primeiro login obriga troca. Platform_admin é identidade global; selecione o tenant na UI depois da troca de senha.

Há lock transacional PostgreSQL e nova verificação do primeiro admin para impedir bootstrap concorrente duplicado. Execução não interativa é recusada. Uma vez criado, o comando recusa novas contas. Falhas são exibidas sem SQL, bindings ou senha. Recuperação de perda do único administrador exige procedimento de proprietário separado e revisado; não há backdoor ou conta de emergência automática.

## Auditoria

AuditEvent possui tenant_id/user_id nullable, event_type, entidade opcional, metadata JSONB, IP nullable e created_at. IP de auditoria é intencionalmente NULL por minimização de dados. A tabela de sessões Laravel mantém seu próprio IP técnico conforme implementação nativa.

Eventos: auth.login_success, auth.login_failed, auth.logout, auth.password_changed, user.created, user.updated, user.disabled, tenant.created e tenant.updated. Tentativas sem identidade válida têm tenant/user NULL e são visíveis somente no contexto global de plataforma. Dashboard de operator/viewer mostra apenas seus eventos; página completa de auditoria é exclusiva dos administradores do contexto.

Metadata admite apenas razões fixas, nomes permitidos de campos alterados, role e status. Não guarda valores antigos/novos de nome/email, senha, hash, token, cookie, headers ou body. Mutações de identidade e auditoria são transacionais. Falha na gravação de auditoria impede conclusão da operação; no login invalida a sessão. QueryException é registrada apenas como mensagem fixa e SQLSTATE, para evitar vazamento de bindings.

Não há UPDATE/DELETE por RLS em audit_events; um trigger adicional bloqueia alteração/remoção e o model recusa mutações. Não é armazenamento WORM contra o proprietário/superuser do PostgreSQL. A política inicial conserva todos os eventos; remoção futura exige política de retenção própria, sem apagar silenciosamente.

## Backup, restore e rollback

pg_dump administrativo inclui automaticamente as novas tabelas e todos os tenants, sem filtros de runtime. Backups são confidenciais e devem ser exportados criptografados para o destino externo ainda pendente. Restore aplica schema, dados, constraints, índices, políticas RLS e trigger. `scripts/restore-check.sh` valida migrações, tabelas de identidade e RLS em database separado e remove somente esse database ao terminar.

Antes do deploy: backup da base e retenção da imagem foundation em `agent-center-central:recovery1-rollback-b1a2e9e`. A imagem nova usa tag `agent-center-central:recovery1`. Em falha, parar somente web/worker do projeto, reapontar Compose à imagem retida e recriar. A aplicação foundation de health não exige remover o schema novo. Não executar migrate:rollback no banco real para desfazer release: down remove identidades/auditoria. Se precisar retornar ao estado anterior do banco, restaurar backup pré-deploy em database/volume separado, validar e trocar DB_DATABASE, preservando o atual para análise.

## Verificações

`./scripts/test.sh` cria database separado, executa migrate, PHPUnit com fail-on-warning, rollback das migrations e drop do database temporário. Testa login/sessão/rehash/logout, senha obrigatória, disabled, CSRF/cookies, todos os roles, negativa por role, scopes, SQL direto sob RLS, vazamento de contexto, auditoria, mass assignment, logs sanitizados e bootstrap.
Pint, composer validate, diff --check e scans de secrets completam a revisão. UI é Blade com CSS local, sem scripts ou assets externos no runtime. O build npm/Vite herdado da foundation foi validado adicionalmente em cópia isolada dentro de container Node 24; os artefatos não são usados pela UI nem adicionados ao repositório.

Fontes oficiais: [Laravel Auth](https://github.com/laravel/docs/blob/13.x/authentication.md), [Laravel Hashing](https://github.com/laravel/docs/blob/13.x/hashing.md) e [PostgreSQL RLS](https://www.postgresql.org/docs/17/ddl-rowsecurity.html).
