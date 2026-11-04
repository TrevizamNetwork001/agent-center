# Validação da fundação — 04/11/2026

Escopo: reconstrução nova em `/opt/agent-center`, somente fase 1. Sem busca de legado, FastAPI, Agent Python, API de Agent ou módulos de domínio. Nenhum serviço global/preexistente foi alterado por este trabalho.

| Verificação | Resultado |
|---|---|
| Inventário antes de instalações | Registrado em INVENTORY.md; dependências base já presentes |
| Compose | `config --quiet` válido; projeto `agent-center` |
| Serviços | postgres, web/FPM, worker e nginx com healthchecks |
| PostgreSQL | 17.11; UTF8; timezone Europe/Berlin; volume dedicado |
| Usuário da aplicação | sem superuser, criação de database ou criação de roles |
| Laravel | Framework 13.40.0; dependências fixadas em composer.lock |
| PHP-FPM final | 8.4.27; Build Date: Oct 28 2026 01:26:27 |
| PHP worker | 8.4.27; CLI compilado em Oct 28 2026 01:26:36 |
| PHP2Shell | patch mínimo 8.4.27 exigido no build; upload progress Off |
| Nginx | 1.30.6; `nginx -t` passou; usuário 101 sem privilégios |
| Health via Nginx | `/health/live` e `/health/ready` retornam HTTP 200 |
| Readiness sob falha | teste simulado retorna 503 genérico, sem detalhe sensível, com no-store |
| Migrations de produção | 3 migrations padrão Laravel executadas |
| Migrations isoladas | migrate e rollback completos em agent_center_test; database removido ao terminar |
| PHPUnit | 4 testes, 12 asserções; sem warnings; `--fail-on-warning` |
| Worker real | job técnico via fila database consumido e confirmado no cache PostgreSQL |
| Backup local | dump custom format, configs, Git bundle após commit e secrets separados; permissões 0600/0700 |
| Restore | dump restaurado em agent_center_restore_check; 3 migrations recuperadas; banco de ensaio removido |
| Exportação criptografada | CMS/OpenSSL testado com certificado descartável; recuperação dos secrets conferida byte a byte sem impressão; artefatos de teste removidos |
| Composer audit | sem advisories nas dependências de produção no momento da consulta |
| Segurança | web/worker UID 33, Nginx UID 101; sem containers privilegiados; somente 127.0.0.1:18083 publicado |
| Git | `.env`, backups reais e vendor ignorados; diff/check e scanner básicos executados antes do commit |
| Secrets reais | comparação literal em todos os arquivos do índice e logs do projeto: nenhum valor encontrado |

O teste de fila é tooling técnico, não um Task Engine nem protocolo de heartbeat de Agent. O timestamp de saúde do worker apenas observa seu loop local.

## Evidências reproduzíveis

```sh
./scripts/compose.sh config --quiet
./scripts/compose.sh ps
./scripts/compose.sh exec -T web php artisan migrate:status
./scripts/compose.sh exec -T web php-fpm -i | sed -n '/^PHP Version/p; /^Build Date/p; /^session.upload_progress.enabled /p'
curl --fail http://127.0.0.1:18083/health/live
curl --fail http://127.0.0.1:18083/health/ready
./scripts/test.sh
./scripts/queue-smoke.sh
./scripts/backup.sh
./scripts/restore-check.sh <DUMP_REAL_GERADO>
git diff --check
git diff --cached --check
./scripts/secret-scan.sh
```

As imagens PHP, Composer, PostgreSQL e Nginx estão fixadas pelos digests nos manifestos. Atualizações futuras devem trocar os digests de forma revisada e repetir as verificações pertinentes. A comparação de versão cobre as CVEs indicadas e não representa garantia de ausência de outras vulnerabilidades.

## Pendências externas explícitas

- Domínio/TLS não fornecidos: serviço somente HTTP local; nenhum vhost global alterado.
- Remoto privado/credencial Git não fornecidos: nenhum remoto ou push; comando documentado no README.
- Destino/chave externa de backup não fornecidos: backup local funcional; réplica externa e agendamento automático ainda não ativos. Concluir antes de produção conforme DISASTER_RECOVERY.md.
