# Roles PostgreSQL — RECOVERY-2

| Role | Uso e privilégios |
|---|---|
| POSTGRES_USER (admin inicial) | administração, bootstrap de roles e backup/restore; somente container PostgreSQL/arquivo secreto do operador |
| APP_DB_USER (migrator, role antiga agent_center_app) | owner do database/schema de aplicação/tabelas; credencial somente no serviço profile operations e testes de migration |
| RUNTIME_DB_USER (agent_center_runtime) | web/worker/lab; NOSUPERUSER, NOCREATEDB, NOCREATEROLE, NOINHERIT, NOBYPASSRLS, sem memberships no migrator e sem ownership |

Não foi necessário um usuário worker distinto: nesta fase ele só usa fila/cache nativos e o mesmo limite de DML do runtime. Separar quando houver jobs com outro conjunto de privilégios, sem conceder DDL ao worker.

Runtime: CONNECT, USAGE no schema; SELECT/INSERT/UPDATE em users, tenants, agents, agent_enrollments; SELECT/INSERT em audit_events (sem UPDATE/DELETE); CRUD em sessions/cache/cache_locks/jobs/job_batches/failed_jobs/password_reset_tokens; SELECT migrations; USAGE/SELECT nas sequences. Sem CREATE no schema, sem ownership ou bypass de RLS. Audit ainda possui trigger imutável. FORCE RLS é preservado nas cinco tabelas de identidade/Agent.

Credenciais aleatórias separadas no `.env` 0600 fora do Git. Web/worker não recebem senha do migrator/admin. `.env.runtime` mínimo para o lab e `.env.migrator` são derivados por `python3 scripts/create-migrator-env.py`, ignorado/0600 e usados somente pelos processos isolados correspondentes. Backup secreto do `.env` contém as credenciais necessárias para derivá-lo novamente; nunca imprimir o arquivo.

```sh
./scripts/compose.sh --profile operations run --rm --no-deps migrator
./scripts/db-grants.sh
```

Grants são explícitos, não um wildcard de CREATE/DDL. Após novas migrations, atualizar a lista autorizada em deploy/database-roles.sql e reaplicar grants. O init de volume vazio cria as roles; para volume existente, o mesmo SQL é aplicado pelo administrador. O script aceita apenas databases do próprio projeto/lab/test/restore. Não administra bancos de outros projetos.

Testes criam database com owner migrator, aplicam schema como migrator, concedem grants, executam PHPUnit como runtime e fazem rollback como migrator. Testes verificam zero tabelas próprias do runtime e rejeição de CREATE TABLE. Nenhum teste torna runtime superuser para fazer RLS passar.

## TLS interno preparado

Conexão atual mantém sslmode=prefer e rede backend privada, sem publicar PostgreSQL. Não foi rebaixada para disable. PKI/certificados finais não foram fornecidos, por isso TLS obrigatório não está ativo.

Overlay opcional `deploy/compose.postgres-tls.yaml`: servidor ssl=on, clientes web/worker/migrator verify-full e sslrootcert. Certificado deve possuir SAN DNS:postgres (hostname interno da conexão). Arquivos ficam em diretório root controlado fora do Git (PG_TLS_DIR): server.crt, server.key, ca.crt e pg_hba.conf. Chave com proprietário/permissões que permitam leitura somente ao postgres do container; validar UID/GID antes da instalação.

`deploy/postgres/pg_hba.hostssl.example` rejeita hostnossl e usa SCRAM em hostssl. NÃO ativar esse exemplo sem testar backups/administração: local também usa SCRAM, então scripts locais precisarão de PGPASSWORD obtido do ambiente do container, sem argv/logs. A ativação deve ser uma mudança revisada com rollback da configuração anterior e teste de backup/restore antes de liberar tráfego. O overlay foi validado sintaticamente e não aplicado sem PKI.

Não inventar certificados/domínio nem usar sslmode=disable ou desabilitar validação TLS para facilitar testes. Antes de produção ampliada, prover CA/certificados, testar verify-full/HOSTSSL e só então ativar o overlay.

## RECOVERY-3 grants e isolamento

Runtime: SELECT/INSERT/UPDATE em skills, profiles, diagnostics, diagnostic_steps, agent_tasks e agent_task_attempts; SELECT/INSERT apenas em diagnostic_plans/diagnostic_conclusions. Sem DELETE, DDL, ownership ou BYPASSRLS. Todas as oito tabelas têm ENABLE/FORCE RLS com tenant_id; FKs compostas amarram Agent/diagnóstico/step/task ao mesmo tenant. Não há bypass de platform_admin nas tabelas de domínio. Worker usa a mesma role runtime nesta fase; separação adicional por workload é evolução possível.

Restore inclui schemas, índices, triggers, dados e policies; reaplicar scripts/db-grants.sh no destino. TLS prefer atual permanece; HOSTSSL/verify-full preparados exigem PKI final.

## RECOVERY-5

Dez tabelas adicionais FORCE RLS (total 23). Runtime SELECT/INSERT/UPDATE em knowledge_items, prompts, llm_providers, llm_models e llm_bindings; SELECT/INSERT somente em payloads versionados, version_events, diagnostic_interpretations e interpretation_sources. Views knowledge_versions/prompt_versions recebem SELECT e security_invoker=true, preservando RLS do caller. Sem DELETE, owner ou BYPASSRLS. FKs compostas impedem vínculos entre tenants.
