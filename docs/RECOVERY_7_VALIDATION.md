# RECOVERY-7 — inventário e validação

Base inspecionada: ff168b9. AGENTS.md raiz e app lidos e preservados. As restrições antigas de fase são superadas pelo pedido explícito RECOVERY-7; invariantes permanecem.

## Inventário inicial (antes das alterações)

| Área | Estado | Evidência / decisão |
|---|---|---|
| Laravel / PostgreSQL / tenant / RBAC / auditoria | IMPLEMENTED | scopes, Policies, GUCs e FORCE RLS; reutilizar |
| Cadastro e inventário web | PARTIAL | Agents existentes; cadastro cria somente código |
| Enrollment com hash, expiração, lock e rate limit | IMPLEMENTED | ProtocolController, AgentEnrollment, AgentOriginLimit |
| Aprovação humana anterior à emissão | NEEDS_FIX | enroll emite token imediatamente |
| Instalador assistido | MISSING | unit/config/build existem; procedimento manual apenas |
| Heartbeat, polling e capabilities | IMPLEMENTED | clientes independentes e allowlist fechada |
| Inventário OS/processo/recursos | PARTIAL | informação local não transmitida para painel |
| Presença dinâmica na lista | IMPLEMENTED | fragment polling a cada 10s |
| Presença dinâmica nos detalhes/onboarding | MISSING | detalhe estático |
| Task Engine / leases / fencing / journal | IMPLEMENTED | RECOVERY-3; não duplicar |
| Catálogo de releases | MISSING | somente build local 0.5.2 |
| Updater e rollback locais | PARTIAL | engine, wrappers, dry-run, locks, previous/failed; sem painel |
| Atualização/rollback remoto com aprovação | MISSING | sem protocolo nem executor privilegiado seguro |
| Saúde worker de diagnósticos | IMPLEMENTED | marker monotônico funcional; não usa commits PG |
| Operations Health completo | PARTIAL | web/DB/tasks/worker/agents; cobertura incompleta |
| Device freeze | IMPLEMENTED | Central/Agent allowlists e negação device_* |
| Backup local / bundle / secrets / restore | IMPLEMENTED | scripts existentes; revalidar |
| Destino externo autorizado | MISSING | DISASTER_RECOVERY registra ausência; sem inventar destino |
| Host real autorizado de Agent | MISSING | não há identidade/destino autorizado no projeto |

Nenhuma execução em equipamentos de rede autorizada. Nenhum status de produção inferido do laboratório.
