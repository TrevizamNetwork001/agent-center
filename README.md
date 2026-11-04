# Agent Center — RECOVERY-1

Central Laravel com base de autenticação, multi-tenant, RBAC e auditoria. Fundação original: b1a2e9e.
Leia [AGENTS.md](AGENTS.md) antes de alterar o projeto; esta fase foi autorizada explicitamente. Nenhum AGENTS.md foi alterado no RECOVERY-1.

Browser → Nginx → Laravel Central → PostgreSQL. Python Agent permanece arquitetura futura, sem implementação. FastAPI é proibido.

- [Identidade, roles, isolamento, bootstrap e auditoria](docs/AUTH_AND_TENANCY.md)
- [Inventário da fundação](docs/INVENTORY.md)
- [Arquitetura](docs/ARCHITECTURE.md)
- [Deploy e rollback](docs/DEPLOYMENT.md)
- [Backup e recuperação](docs/DISASTER_RECOVERY.md)
- [Validação da fundação original](docs/VALIDATION.md)
- [Validação RECOVERY-1](docs/RECOVERY_1_VALIDATION.md)

HTTP somente local: http://127.0.0.1:18083/login. Usar túnel SSH para acesso remoto até configurar TLS. Não há conta ou senha padrão.

```sh
cd /opt/agent-center
./scripts/compose.sh config --quiet
./scripts/compose.sh build web
./scripts/compose.sh up -d postgres web
./scripts/compose.sh exec -T web php artisan migrate --force
./scripts/compose.sh up -d --wait --wait-timeout 180
./scripts/compose.sh exec web php artisan agent-center:create-admin
```

O bootstrap é interativo, usa senha oculta e obriga troca no primeiro login. Conta real não é criada automaticamente. Para implantação/rollback completos, seguir a documentação antes de executar os comandos.

Health: `/health/live` e `/health/ready`. `.env` e backups reais ficam fora do Git. Banco, FPM e worker não são publicados. Serviços globais da VPS permanecem separados.

Remoto privado não informado. Após prover repositório e credenciais reais:

```sh
git remote add origin <URL_REAL_DO_REPOSITORIO_PRIVADO>
git push -u origin main
```

Réplica externa de backup ainda depende de destino/chave reais. Procedimentos e retenção estão em DISASTER_RECOVERY.md; backup local não protege contra perda da VPS.
