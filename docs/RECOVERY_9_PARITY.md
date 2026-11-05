# RECOVERY-9 — inventário e paridade

Auditoria estática inicial; IMPLEMENTED indica código localizado, não validação funcional nova. Nenhum resultado histórico foi convertido em PASS desta fase.

| Área | Estado | Evidência |
|---|---|---|
| Authentication, sessões, CSRF e senha obrigatória | IMPLEMENTED | app/tests/Feature/AuthenticationTest.php |
| Tenants, RBAC, isolamento e RLS | IMPLEMENTED | app/tests/Feature/TenantIsolationTest.php; AuthorizationTest.php; deploy/database-roles.sql |
| Auditoria | IMPLEMENTED | app/app/Http/Controllers/AuditController.php |
| Agents, enrollment, aprovação, revogação e presença | IMPLEMENTED | app/tests/Feature/AgentApiTest.php |
| Agent updates/rollback e inventário | PARTIAL | app/tests/Feature/AgentOperationsTest.php; sem Agent real autorizado |
| Diagnostics, planejamento, tasks, leases/fencing, recovery e cancelamento | IMPLEMENTED | app/tests/Feature/DiagnosticsTest.php; docs/TASK_ENGINE.md |
| Profiles e Skills | IMPLEMENTED | app/app/Http/Controllers/DiagnosticCatalogController.php |
| Knowledge e versões | IMPLEMENTED | app/tests/Feature/KnowledgePromptTest.php; docs/KNOWLEDGE.md |
| Prompts e preview | IMPLEMENTED | app/tests/Feature/KnowledgePromptTest.php; docs/PROMPTS.md |
| LLM, secrets, fallback e proveniência | IMPLEMENTED | app/tests/Feature/LlmSafetyTest.php; LlmHttpTest.php |
| Conversation, estados e Adaptive Tone | IMPLEMENTED | app/tests/Feature/ConversationTest.php; docs/CONVERSATION_ENGINE.md |
| Dashboard e Operations | PARTIAL | app/app/Http/Controllers/DashboardController.php; docs/OPERATIONS_HEALTH.md; revalidação autenticada pendente |
| Backups R2 | PARTIAL | RECOVERY-8: transferência comprovada; restore independente, retenção e alerta adiados |
| Device actions | INTENTIONALLY_DISABLED | AGENTS.md; freeze preservado |
| Playbooks e inventário histórico de equipamentos | MISSING | Não encontrada evidência suficiente de módulo funcional legítimo; não criar menu |
| Capabilities históricas não recuperadas | FUTURE | Não igualar número 14 sem implementação e evidência |
| Tema claro/escuro | NEEDS_FIX | candidato local; auditoria visual real e persistência ainda não validadas |

Inventário completo de caminhos: evidence/recovery9/inventory.json. A matriz ainda precisa de inspeção comportamental item a item; agrupamento acima não comprova cada requisito granular.

RECOVERED: módulos existentes acima, conforme evidências históricas locais. INTENTIONALLY RETIRED: FastAPI/bridges e Central Python conforme arquitetura autorizada. NOT RECOVERABLE FROM AVAILABLE EVIDENCE: capabilities não documentadas, Playbooks/equipamentos e capturas históricas não fornecidas. FUTURE DEVELOPMENT: operações proibidas, sem implementação nesta continuação.
