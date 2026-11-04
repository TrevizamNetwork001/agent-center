# RECOVERY-1 — validação

Base inicial b1a2e9e, branch main limpa antes do trabalho. AGENTS.md da raiz e de app/ foram lidos e não foram alterados. Escopo implementado: identidade, tenants, roles, auth, UI mínima e auditoria, exclusivamente Laravel/PostgreSQL.

| Verificação | Resultado |
|---|---|
| Migrations | Nova migration create_identity_and_audit; 4 migrations totais aplicadas; migrate/rollback testados em database isolado |
| Modelos | Tenant, User e AuditEvent; Role enum com quatro valores explícitos |
| Auth | SessionGuard/EloquentUserProvider/Hash/CSRF nativos; troca obrigatória e last_login_at |
| Password hashing | Argon2id nativo disponível; custo de produção 64 MiB/time 4; teste de hash e rehash passou |
| Tenant/RBAC | Scopes globais, Policies, Gate, FormRequests e bindings; negativas por papel e entre tenants testadas |
| PostgreSQL | FORCE RLS em users/tenants/audit_events; role de teste é a mesma role não superuser da aplicação; 8 políticas |
| SQL direto | Leituras entre tenants, escrita cruzada e autopromoção para platform_admin bloqueadas |
| Contexto | Contexto limpo entre requests; contexto de sessão forjado ignorado para usuário de tenant |
| Auditoria | Eventos obrigatórios cobertos; metadata permitida; modelo e banco impedem UPDATE/DELETE |
| PHPUnit | 37 testes, 211 asserções, sem warnings; execução com --fail-on-warning |
| Pint | Formatação aplicada e --test aprovado |
| Composer | composer validate --strict aprovado; nenhum pacote novo de autenticação ou RBAC |
| Frontend herdado | npm install e npm run build aprovados em container Node 24 temporário, cópia isolada do scaffold; npm audit retornou zero vulnerabilidades |
| UI entregue | Blade e CSS local; sem @vite, scripts ou fonts externos no runtime; nenhum artefato de frontend/node_modules foi colocado no projeto |
| HTTP real/Nginx | login 200; dashboard redireciona guest; CSRF sem token 419; login inválido retorna erro genérico e grava auditoria |
| Cookies/headers | HttpOnly/SameSite=Lax; sessão criptografada; no-store; sessão regenerada após login e senha |
| Sessões | Revogação em logout, mudança de senha, usuário disabled e tenant disabled coberta |
| Health/worker | live e ready 200; quatro serviços healthy; job técnico de fila consumido pelo worker |
| Rotas | 19 rotas próprias; nenhuma Agent API, diagnostics ou rota de módulo operacional |
| Produção local | Zero usuários reais criados; apenas um evento auth.login_failed do smoke HTTP controlado |
| Backup anterior | 20261104T152638Z; imagem foundation retida como agent-center-central:recovery1-rollback-b1a2e9e |
| Backup de schema novo | 20261104T153415Z; pg_dump administrativo inclui todos os tenants, users e audit_events |
| Restore isolado | 4 migrations recuperadas; tenants/users/audit_events presentes; FORCE RLS e trigger audit_immutable conferidos; 1 evento de auditoria recuperado; database temporário removido |
| Git/security | diff/check, scan básico e comparação literal dos secrets reais contra índice/logs antes do commit |

O build do scaffold emitiu aviso não bloqueante sobre a otimização opcional de fontes (fontaine). Esse scaffold não é consumido pela UI entregue; não foi instalado pacote adicional nem alterado o frontend da aplicação por causa do aviso. A primeira tentativa de download de Node falhou por conexão; a repetição terminou com sucesso. Nenhuma configuração de rede/serviço global foi alterada.

Dados reais vazios de tenants/users no restore são esperados: não foi criado bootstrap de produção sem email/nome/senha fornecidos pelo proprietário. Os testes em PostgreSQL usam múltiplos tenants e usuários com dados fictícios, sempre em database separado com rollback/drop.

## Riscos e pendências

- Bootstrap do primeiro administrador deve ser feito pelo proprietário em terminal interativo com `./scripts/compose.sh exec web php artisan agent-center:create-admin`. Não há senha padrão nem transporte de senha por argumento.
- HTTP somente em loopback; TLS e Secure cookie são pré-requisitos para qualquer exposição pública.
- Sem MFA ou recuperação de senha por email nesta fase. Recuperação do único platform_admin exige procedimento de proprietário revisado; nenhum backdoor foi criado.
- RLS depende de contexto estabelecido por código de confiança; a credencial de runtime ainda possui ownership de DDL da fundação. Não protege de comprometimento dessa credencial ou do processo da aplicação. Separação de roles de migrations/runtime fica documentada antes de produção ampliada.
- Auditoria é append-only para os acessos da aplicação; não é WORM contra superuser/proprietário do banco.
- Remoto Git privado e destino/chave de backup externo ainda não fornecidos. Cópias externas automáticas continuam pendentes.
