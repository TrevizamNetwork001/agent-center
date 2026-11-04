# Agent Center — Recovery Foundation

Fundação nova da Central Laravel, sem legado e sem implementação do sistema completo.
Leia [AGENTS.md](AGENTS.md) antes de alterar o projeto.

Arquitetura: Browser → Nginx → Laravel Central → PostgreSQL. Futuramente Central → Python Agent separado.
FastAPI é proibido. Nenhum Agent, acesso a device ou módulo de domínio foi implementado.

- [Inventário inicial](docs/INVENTORY.md)
- [Arquitetura e limites](docs/ARCHITECTURE.md)
- [Deploy, rollback e testes](docs/DEPLOYMENT.md)
- [Backup e recuperação](docs/DISASTER_RECOVERY.md)
- [Validações da entrega](docs/VALIDATION.md)

HTTP local: http://127.0.0.1:18083. Health: `/health/live` e `/health/ready`.
Nenhum serviço do host precisa ser substituído. PHP existe somente nos containers.

```sh
cd /opt/agent-center
./scripts/compose.sh config --quiet
./scripts/compose.sh up -d --build
./scripts/compose.sh exec -T web php artisan migrate --force
./scripts/compose.sh ps
curl --fail http://127.0.0.1:18083/health/ready
```

O `.env` real, gerado localmente (0600), não é versionado. Para uma VPS futura, seguir DEPLOYMENT.md antes de subir o stack.
A fundação não oferece login público nem endpoints de domínio. Multi-tenant é requisito bloqueante de toda evolução futura.

Repositório remoto privado ainda não informado. Após criar o repositório e configurar credenciais:

```sh
git remote add origin <URL_REAL_DO_REPOSITORIO_PRIVADO>
git push -u origin main
```

Não há URL inventada nem push executado.
