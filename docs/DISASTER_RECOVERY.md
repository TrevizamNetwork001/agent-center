# Disaster Recovery

Reconstrução começa do repositório novo e dos backups novos. Não depende de VPS, runtime ou banco anterior.

## Backups desde o primeiro dia

Executar `./scripts/backup.sh`: dump PostgreSQL custom format validado com `pg_restore --list`, configs em tar.gz, hash SHA-256 e cópia do `.env` em diretório separado. Não registra conteúdo de secrets. Backup usa lock para evitar concorrência e arquivos 0600 em diretórios 0700; dumps também são confidenciais. O script captura schema e dados com snapshot consistente de pg_dump; não copia arquivos internos do volume.

- `backups/database`: dumps; retenção local de 7 dias.
- `backups/config`: configs, scripts, docs, template e referência do commit; retenção local de 7 dias.
- `backups/secrets`: `.env` e APP_KEY, separados de configs/Git; retenção local de 7 dias.
- Código: após o primeiro commit, cada backup inclui Git bundle com histórico em `backups/config`; enviar esse bundle também para fora da VPS. Pode ser recuperado com `git clone arquivo.bundle agent-center`. Repositório remoto privado mais espelho externo. Não há remoto disponível nesta entrega.
- Futuro storage de uploads: adicionar backup consistente antes de implementar uploads. Atualmente não há conteúdo de usuário.

Agendamento inicial preparado em `deploy/backup.cron.example`; instalar apenas o cron específico do projeto quando houver destino externo configurado. Nenhum cron preexistente é alterado. Rodar diariamente e antes de deploy; RPO alvo 24h; RTO alvo 4h, a medir em ensaio completo.

## Cópia externa obrigatória antes de produção

Backup local sozinho não satisfaz recuperação de perda da VPS. Destino externo e credenciais não foram fornecidos; réplica automática NÃO está ativa. Não há garantia contra perda da VPS até concluir os passos abaixo.

1. Prover armazenamento em outra conta/provedor ou máquina externa, com credencial de mínimo privilégio. Guardar chave de recuperação fora desta VPS.
2. Criptografar dumps/configs/secrets antes do envio. `scripts/export-backup.sh <certificado-publico.pem> <diretorio-saida>` cria três arquivos CMS separados usando OpenSSL. Chave privada do destinatário fica exclusivamente fora da VPS. Arquivos criptografados também devem ficar fora do Git.
3. Copiar os três arquivos criptografados para destino real com ferramenta aprovada (ex.: scp/SFTP ou object storage com versionamento e imutabilidade). Não inventar URL, conta ou credencial. Verificar tamanho/checksum do objeto recebido e alertar falhas.
4. Retenção externa: 30 diários, 12 semanais e 12 mensais. Separar acesso aos secrets do acesso ao código/configs. Impedir que credencial desta VPS apague retenções externas imutáveis.
5. Mensalmente testar descriptografia e restore isolado; trimestralmente ensaiar VPS nova e registrar duração. Alertar se não houver backup externo verificado em 24h. Somente apagar cópias antigas após confirmar retenção externa.

## Restaurar

1. Recuperar código do commit registrado, configs e `.env` da mesma janela. Descriptografar fora da VPS com `openssl cms -decrypt -binary -inform DER -in arquivo.cms -recip certificado.pem -inkey chave-privada.pem -out arquivo.tar.gz`; conferir os hashes.
2. Na VPS nova, instalar dependências base e preparar diretório. Restaurar `.env` com 0600. Não regenerar APP_KEY de instalação com dados existentes, pois invalida criptografia/sessões.
3. Subir PostgreSQL vazio; bootstrap recria roles usando os secrets. Não restaurar em banco que contém dados de produção. Criar database separado pelo usuário administrativo, com owner da aplicação e UTF8.
4. Validar `pg_restore --list` e restaurar o dump em database separado com `pg_restore --no-owner --no-privileges --exit-on-error -U <admin> --role <usuario_app> -d <database_novo>`; `--role` garante ownership dos objetos pelo usuário da aplicação. O dump não inclui roles/senhas; elas vêm do bootstrap e do `.env`.
5. Apontar DB_DATABASE para o banco restaurado, recriar web/worker, verificar migrations, `/health/live`, `/health/ready`, permissões e fila. Executar migrations adicionais apenas após revisar compatibilidade do commit.
6. Somente liberar tráfego após validar. Preservar banco/volume anterior para rollback. Não executar restore destrutivo automaticamente.

O backup local inicial e a restauração de ensaio são registrados em VALIDATION.md. A publicação externa depende de destino real; deve ser concluída antes de uso de produção.

## RECOVERY-2

Backup PostgreSQL administrativo inclui automaticamente agents, agent_enrollments, audit e schema completo com FORCE RLS. Runtime não possui os objetos; restore usa APP_DB_USER migrator como owner e scripts/db-grants.sh para restaurar concessões do runtime. scripts/restore-check.sh testa também leitura negada a runtime sem contexto, além de cinco tabelas protegidas e trigger imutável. Secrets de banco separados continuam no .env privado; .env.migrator pode ser regenerado sem imprimir valores.

Backups do Agent (config, state/identity/journal e runtime/updater/previous) estão detalhados em AGENT_OPERATIONS.md. São confidenciais, não entram no Git e precisam de cópia externa criptografada. A entrega não inventa destino externo nem habilita réplica sem credenciais reais.

## RECOVERY-3

pg_dump integral inclui diagnostics, diagnostic_plans, diagnostic_steps, agent_tasks, agent_task_attempts, diagnostic_conclusions, profiles e skills, além de audit/agents/enrollment. Restore-check verifica 13 tabelas FORCE RLS, triggers imutáveis e role runtime sem owner/visibilidade sem contexto.

Ao recuperar execução após desastre, manter Agents parados até verificar estado de leases. Não restaurar journal antigo sobre state mais novo; resultados desconhecidos não devem ser reexecutados automaticamente. Backups de /etc/agent-center-agent, /var/lib/agent-center-agent e runtime incluem identidade/leases e exigem destino externo privado e criptografado; credenciais nunca no Git. Backup local não substitui cópia fora da VPS.
