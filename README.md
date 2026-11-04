# Agent Center — RECOVERY-2

Central Laravel com identidade, tenants, RBAC/auditoria e Agent API v1. Python Agent reconstruído em 0.5.2; protocol/tasks operacionais permanecem bloqueados nesta fase. Base RECOVERY-1: da20d9c.
Leia [AGENTS.md](AGENTS.md) antes de alterar o projeto; esta fase foi autorizada explicitamente. Nenhum AGENTS.md foi alterado no RECOVERY-2.

Browser → Nginx → Laravel Central → PostgreSQL. Python Agent é cliente separado, sem FastAPI/bridge.

- [Agent: arquitetura](docs/AGENT_ARCHITECTURE.md), [API](docs/AGENT_API.md), [operação](docs/AGENT_OPERATIONS.md), [roles do banco](docs/DATABASE_ROLES.md)
- [Validação RECOVERY-2](docs/RECOVERY_2_VALIDATION.md)
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
./scripts/compose.sh --profile operations run --rm --no-deps migrator
./scripts/db-grants.sh
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

## RECOVERY-3

Diagnósticos determinísticos, seis Profiles e seis Skills fechadas, Task Engine com leases/fencing e worker funcional. Documentação: [Task Engine](docs/TASK_ENGINE.md), [Diagnósticos](docs/DIAGNOSTICS.md), [Profiles/Skills](docs/PROFILES_AND_SKILLS.md), [Saúde](docs/OPERATIONS_HEALTH.md), [Vendors desativados](docs/VENDOR_SKILLS_ROADMAP.md).

## RECOVERY-4

Experiência operacional com dashboard real, navegação por grupos, filtros de histórico, Perfis/Skills, saúde dedicada e atualização por fragmentos. [WEB_UX](docs/WEB_UX.md) · [Galeria desktop/mobile](docs/evidence/recovery4/README.md).

## RECOVERY-5

[Knowledge versionado](docs/KNOWLEDGE.md), [Prompts/preview](docs/PROMPTS.md) e [LLM foundation](docs/LLM.md). Interpretação opcional não altera classificação, tasks ou estado técnico. Sem tools e com fallback determinístico.

## Conversation Platform (RECOVERY-6)

Conversa Web com estado, intenções determinísticas, Adaptive Tone automático, histórico e integração ao Task Engine. Ativação por tenant, desabilitada por padrão. Ver [Conversation Engine](docs/CONVERSATION_ENGINE.md) e [validação RECOVERY-6](docs/RECOVERY_6_VALIDATION.md). HTTPS com certificado válido e acesso restrito à VPS enquanto o backup externo não é configurado.
