# Regras obrigatórias — Agent Center

- Central sempre em Laravel. FastAPI proibido, inclusive bridges e migrações de runtime legado.
- Python apenas para Agent separado e tooling técnico explicitamente autorizado.
- PostgreSQL é o banco de dados.
- Multi-tenant obrigatório: toda funcionalidade futura deve isolar dados, jobs, cache e autorização por tenant; nenhum recurso de domínio pode ser entregue sem isolamento e testes contra acesso cruzado.
- Usar Laravel Auth, Session, CSRF, Hash, Policies e Gates nativos.
- Nenhum shell arbitrário, inclusive originado de usuário, LLM ou Agent.
- Nenhum secret em logs, respostas, commits ou documentação.
- LLM sem autoridade operacional; decisões operacionais exigem validação determinística e autorização.
- Skills somente por allowlist explícita.
- Nenhuma ação de device nesta fase.
- Migrations futuras exclusivamente em Laravel.
- Alterações operacionais devem possuir rollback documentado e preservar dados.
- Preservar separação entre Central e Agent; Agent não implementado nesta fase.
- Não tocar serviços não relacionados da VPS.
- Fase 1 apenas: infraestrutura, Laravel base, health, backup e testes. Proibido implementar Agent API, enrollment, heartbeat, diagnostics, Task Engine, Profiles, Skills, Knowledge, Prompts, LLM, Conversation, Telegram, WhatsApp, RAG, MTR, device access e Huawei.
- Secrets em arquivos fora do Git com permissões restritas. Não expor PostgreSQL/FPM/worker publicamente.
- Ler este arquivo antes de alterar o projeto; executar validações pertinentes e git diff --check.
