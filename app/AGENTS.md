# Laravel Central — regras locais

Aplicam-se integralmente as regras de [../AGENTS.md](../AGENTS.md).

- PHP e Composer são executados nos containers definidos em `../deploy/php/Dockerfile`.
- Não instalar PHP global nem executar instaladores remotos do template Laravel.
- Não adicionar Laravel Boost, SDK de IA ou tooling opcional fora do escopo explicitamente autorizado.
- Usar `../scripts/test.sh` para testes em PostgreSQL separado e `../scripts/compose.sh` para operações deste projeto.
- Permitir manutenção e evolução dos recursos de domínio e da Agent API existentes dentro do escopo autorizado, preservando RBAC, FORCE RLS, isolamento por tenant e auditoria.
- Preservar device freeze; rotinas de device e operações em produção exigem autorização operacional específica conforme as regras da raiz.
- Preservar middleware, criptografia, sessões e autorização nativos do Laravel.
