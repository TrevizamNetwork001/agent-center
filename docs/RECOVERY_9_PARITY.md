# RECOVERY-9 — paridade funcional final

Continuação de bf54d1a. Referência histórica: briefing do operador e documentação RECOVERY-1–8 do repositório. Não há fonte executável íntegra da VPS perdida nem comprovação das 14 capabilities históricas. Esta matriz afirma paridade dos comportamentos documentados e testados, não recuperação integral de um sistema perdido.

| Funcionalidade | Classificação | Evidência comportamental (app/tests/Feature) | Implementação |
|---|---|---|---|
| Autenticação, logout, senha obrigatória, sessões/CSRF | IMPLEMENTED / RECOVERED | AuthenticationTest | app/app/Http/Controllers/AuthController.php |
| Tenants, usuários, RBAC, policies/gates e isolamento/RLS | IMPLEMENTED / RECOVERED | TenantIsolationTest; AuthorizationTest | app/app/Tenancy; app/app/Policies; deploy/database-roles.sql |
| Enrollment, cadastro, aprovação e revogação | IMPLEMENTED / RECOVERED | AgentApiTest; AgentOperationsTest | app/app/Http/Controllers/Agent |
| Heartbeat, polling, capabilities e presença | IMPLEMENTED / RECOVERED | AgentApiTest; WebUxTest | app/app/Agents; app/app/Web/OperationalSnapshot.php |
| Versão, atualização, rollback, fencing e logs sanitizados | IMPLEMENTED / RECOVERED | AgentOperationsTest | app/app/Agents; docs/AGENT_OPERATIONS.md |
| Diagnostics, targets e classificação determinística | IMPLEMENTED / RECOVERED | DiagnosticsTest; WebUxTest | app/app/Diagnostics |
| Task Engine, leases, fencing, recovery e cancelamento | IMPLEMENTED / RECOVERED | DiagnosticsTest | app/app/Diagnostics/Engine.php |
| Profiles, Skills, allowlist e fixtures | IMPLEMENTED / RECOVERED | DiagnosticsTest; WebUxTest | app/app/Diagnostics/Catalog.php |
| Progresso, evidências, histórico, resumo e próximos passos | IMPLEMENTED / RECOVERED | WebUxTest | app/resources/views/diagnostics; app/public/agent-center.js |
| Knowledge: draft, publicação, imutabilidade, supersede, rollback, archive, scopes/tags | IMPLEMENTED / RECOVERED | KnowledgePromptTest | app/app/Http/Controllers/KnowledgeController.php |
| Prompts: versões, publicação, preview, histórico e archive | IMPLEMENTED / RECOVERED | KnowledgePromptTest | app/app/Http/Controllers/PromptController.php |
| LLM: providers, models, bindings, secrets, conexão, fallback e proveniência | IMPLEMENTED / RECOVERED | LlmSafetyTest; LlmHttpTest | app/app/Llm |
| Conversation: histórico/estado/intents/contexto, target, confirmação, CLOSE/CONTINUE/CANCEL | IMPLEMENTED / RECOVERED | ConversationTest | app/app/Conversation |
| Adaptive Tone e mirror leve sem mudar classificação | IMPLEMENTED / RECOVERED | ConversationTest | app/app/Conversation; docs/CONVERSATION_ENGINE.md |
| Auditoria e fuso São Paulo | IMPLEMENTED / RECOVERED | AuditTimezoneTest; AuthorizationTest | app/app/Http/Controllers/AuditController.php |
| Operations: Web/worker/PostgreSQL/TLS, Agents/poll, tasks/leases/fencing/cobertura | IMPLEMENTED / RECOVERED | WebUxTest; HealthTest | app/app/Web/OperationalSnapshot.php |
| Configuração de backups e proteção dos secrets | IMPLEMENTED / RECOVERED | BackupSettingsTest | app/app/Backups; app/resources/views/backups |

A suíte completa revalidou 142 testes/1.187 assertions em PostgreSQL separado com papel runtime sem superuser/bypass/ownership. Os nomes individuais de todos os testes constam em evidence/recovery9/inventory.json; não se reimplementaram módulos corretos.

| Diferença / limitação | Classificação | Estado real |
|---|---|---|
| Agent real em produção | INTENTIONALLY_DISABLED | Zero Agents. Sem instalação autorizada; update/rollback implementados/testados, sem prova de self-update em host real |
| Conversation | IMPLEMENTED; rollout desabilitado | Flag por tenant; produção preserva zero tenants habilitados; habilitado apenas no laboratório sintético |
| Device actions | INTENTIONALLY_DISABLED | Freeze preservado; nenhum acesso a equipamentos |
| Capabilities históricas não documentadas | NOT RECOVERABLE FROM AVAILABLE EVIDENCE | Não completar artificialmente 14. Quatro capabilities comprovadas: agent_info, dns_lookup:v1, tcp_connect:v1, http_probe:v1 |
| Playbooks / inventário histórico de equipamentos | MISSING; NOT RECOVERABLE FROM AVAILABLE EVIDENCE | Nenhum módulo legítimo comprovado; sem menu fictício |
| FastAPI, bridge, Central Python | INTENTIONALLY RETIRED | Arquitetura Laravel/PostgreSQL mantida |
| Telegram, WhatsApp, n8n, RAG, MTR avançado, Huawei/BGP/OLT | FUTURE | Não implementados nesta fase |
| Restore independente, lock/retention e alertas externos | PARTIAL; adiados | Pendências RECOVERY-8 preservadas, sem alegação de resolução |

Lacunas corrigidas nesta fase: tema escuro incompleto, sidebar clara, contexto visual pouco explícito, contraste de tabelas/botões, ausência de aviso explícito de zero Agents no dashboard e runner de testes sem seleção segura de laboratório. Nenhuma migration ou arquivo agent/ alterado. Backend funcional preservado.
