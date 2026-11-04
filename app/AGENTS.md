# Laravel Central — regras locais

Aplicam-se integralmente as regras de [../AGENTS.md](../AGENTS.md).

- PHP e Composer são executados nos containers definidos em `../deploy/php/Dockerfile`.
- Não instalar PHP global nem executar instaladores remotos do template Laravel.
- Não adicionar Laravel Boost, SDK de IA ou tooling opcional nesta fase de fundação.
- Usar `../scripts/test.sh` para testes em PostgreSQL separado e `../scripts/compose.sh` para operações deste projeto.
- Não implementar recursos de domínio, Agent API ou rotinas de device nesta fase.
- Preservar middleware, criptografia, sessões e autorização nativos do Laravel.
