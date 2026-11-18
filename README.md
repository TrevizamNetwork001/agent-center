# Agent Center

Central em Laravel e PostgreSQL, com Agent Python separado. Autenticação nativa Laravel, isolamento por tenant, RBAC, FORCE RLS, auditoria e execução limitada por contratos e allowlist. Leia [AGENTS.md](AGENTS.md) antes de alterar o projeto.

O fluxo completo e a situação das entregas estão em [Fluxo atual — 18/11/2026](docs/FLUXO_ATUAL_20261118.md). A consolidação distingue implementação, laboratório e publicação documentada. A conciliação do repositório está em [Publicação GitHub — 18/11/2026](docs/PUBLICACAO_GITHUB_20261118.md).

As [pendências verificadas no runtime](docs/PENDENCIAS_20261118.md) distinguem a Central saudável, as diferenças de implantação e a correção autorizada do certificado do laboratório antigo `backup-ui`. Esse laboratório não comprova o estado do backup externo de produção.

```text
Web / Telegram → Laravel → identidade e tenant → Conversation / Diagnostics
→ Preflight e autorização → Task Engine → Agent API → Python Agent
→ evidência estruturada → conclusão determinística → painel / conversa
```

Knowledge, RAG e LLM auxiliam respostas com fontes; o modelo não tem autoridade operacional. Releases do Agent são assinadas e distribuídas pelo Lifecycle Manager existente. A infraestrutura 0.6.6 tem rollout documentado na VM teste; consultas a equipamentos físicos continuam sujeitas a freeze e autorização específica.

- [Arquitetura](docs/ARCHITECTURE.md) e [identidade/tenancy](docs/AUTH_AND_TENANCY.md).
- [Task Engine](docs/TASK_ENGINE.md), [Diagnostics](docs/DIAGNOSTICS.md) e [Profiles/Skills](docs/PROFILES_AND_SKILLS.md).
- [Conversation Engine](docs/CONVERSATION_ENGINE.md), [Telegram](docs/TELEGRAM_V1_OPERATIONS.md) e [RAG publicado](docs/RAG_V1_PRODUCTION_PASS_20261111.md).
- [Agent API](docs/AGENT_API.md), [operação do Agent](docs/AGENT_OPERATIONS.md) e [rollout 0.6.6](docs/AGENT_PANEL_ROLLOUT_066_20261116.md).
- [Integração MikroTik LAB](docs/MIKROTIK_READONLY_INTEGRATION_V1_IMPLEMENTED.md) e [aceite LAB 0.6.6](docs/MIKROTIK_READONLY_066_LAB_COMPLETION.md).
- [Deploy](docs/DEPLOYMENT.md), [backup](docs/BACKUP_POLICY.md), [recuperação](docs/DISASTER_RECOVERY.md) e [GitHub](docs/GIT_REMOTE.md).

Os relatórios RECOVERY e os fechamentos datados preservam o histórico de cada fase; versões, bloqueios e endereços antigos devem ser lidos nesse contexto. Código no GitHub, código no workspace e runtime implantado precisam ser conferidos separadamente. Secrets e backups sensíveis ficam fora do Git.
