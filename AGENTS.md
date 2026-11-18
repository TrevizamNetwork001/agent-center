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
- Device freeze obrigatório; acesso a equipamentos e ações de device exigem autorização operacional específica.
- Migrations futuras exclusivamente em Laravel.
- Alterações operacionais devem possuir rollback documentado e preservar dados.
- Preservar separação entre Laravel Central e Python Agent.
- Não tocar serviços não relacionados da VPS.
- Arquitetura operacional atual: Laravel Central, Agent API, Task Engine, Diagnostics, Profiles e Skills, Knowledge e LLM, Conversation Engine, Lifecycle Manager, Agent 0.6.2 e MTR seguro.
- Manutenção, correções e evolução dos módulos existentes são permitidas dentro do escopo autorizado em cada solicitação; novas Skills e melhorias não exigem revisão destas instruções, mas continuam sujeitas à allowlist e às demais regras de segurança.
- Preservar RBAC, PostgreSQL FORCE RLS, isolamento por tenant, autenticação da Central e do Agent, CSRF, TLS, auditoria e limites operacionais.
- Releases do Agent devem ser assinadas e distribuídas pelo Lifecycle Manager existente, com validação de compatibilidade, testes em laboratório, backup e rollback que preserve dados.
- Deploy de produção, atualização do Agent real, ampliação de privilégios, acesso a equipamentos e mudanças destrutivas exigem autorização operacional específica. Esta regra não permite shell nem comandos arbitrários.
- Novos módulos ou integrações dependem do escopo explicitamente autorizado; a arquitetura existente não autoriza automaticamente Telegram, WhatsApp, RAG ou operações em equipamentos.
- Secrets em arquivos fora do Git com permissões restritas. Não expor PostgreSQL/FPM/worker publicamente.
- Ler este arquivo antes de alterar o projeto; executar validações pertinentes e git diff --check.

## Skill de layout e interface — allowlist de desenvolvimento

- `web-development-design-ops` está explicitamente permitida para desenvolvimento de interfaces neste repositório, em [skills/web-development-design-ops/SKILL.md](skills/web-development-design-ops/SKILL.md).
- Ler e seguir essa skill ao criar, revisar ou alterar layouts, páginas, dashboards, componentes visuais, CSS e responsividade do Agent Center.
- Esta permissão é restrita à orientação de desenvolvimento; não registra uma Skill operacional no Agent, não libera dependências relacionadas e não autoriza deploy, acesso a equipamentos ou novas integrações.
- As regras obrigatórias acima prevalecem sobre a skill. Manter a Central em Laravel e preservar a stack e os controles existentes.
