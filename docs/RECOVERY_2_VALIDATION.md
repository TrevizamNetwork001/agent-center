# RECOVERY-2 — evidências da entrega

Base da20d9c; AGENTS.md da raiz e app/ lidos e preservados. Implementação nova, sem busca de arquivos antigos, FastAPI, bridge, Diagnostics completo ou acesso a devices. Protocolo de payload é o contrato explícito desta reconstrução; fixtures históricas não estão disponíveis para comprovar equivalência byte a byte com runtime perdido.

| Item | Resultado |
|---|---|
| Schema | Nova migration cria agents UUID e agent_enrollments; cinco migrations totais aplicadas |
| Modelos/isolamento | Agent, AgentEnrollment, scopes, AgentPolicy e FORCE RLS; lookup temporário apenas ID/hash |
| Agent API | Oito POSTs v1, todos testados com PHPUnit e com Client Python real contra Laravel HTTP |
| Enrollment | Uso único, prazo, tenant, row lock/transação, segredo/token somente SHA-256 no servidor; emissão única |
| Auth | Bearer, comparação hash_equals, revogado/tenant disabled rejeitados; sem HMAC/nonce de bridge |
| Rate limit | Origem 1200/min; enrollment 10/min; Agent 120/min; casos 429 testados |
| UI | Lista, detalhe com datas/capabilities e revogação; enrollment exclusivo de administradores; sem Diagnostics |
| Auditoria | Enrollment/criação/capabilities/revogação, IDs UUID validados em metadata; heartbeat só timestamp para não gerar evento por request |
| Roles PostgreSQL | Runtime não superuser, não owner, sem BYPASSRLS/membership migrator; CREATE TABLE negado; audit sem UPDATE/DELETE |
| TLS PostgreSQL | Overlay/CA/verify-full/HOSTSSL preparados e config validada; não ativados sem PKI; conexão atual não rebaixada |
| Laravel | 44 testes, 390 asserções, sem warnings; migrate/rollback em database separado, testes sob role runtime |
| Python | 29 testes aprovados em Python 3.13.18; host tooling também usa Python 3.13.5 |
| Temporal | Heartbeat independente de lock do journal, HTTP lento/loop principal e gravação bloqueada de status; teste escalado e long-run real |
| Rede | DNS/refused/timeout, 401/403/409/429/5xx, JSON inválido/vazio/envelope errado, TLS obrigatório e redirects bloqueados |
| Persistência | JSON atômico com fsync/rename; lock de instância; journal WAL/FULL; ordem/replay/crash e resultado durável sem reexecução |
| Freeze | Config false rejeitada; nomes device_* conhecidos/desconhecidos/futuros e ações fora da allowlist negados |
| Capabilities | Seis nomes com handlers justificados; quatro disponíveis/anunciados no lab; ping/traceroute condicionais; oito nomes históricos desconhecidos não inventados |
| Update/rollback | SHA, zip path traversal, dry-run, artefato real/self-check/exit codes, serviço/lock antes de swap, .previous/.failed, recovery e preservação de state testados |
| Systemd | systemd-analyze verify passou; unit dedicada não instalada/habilitada globalmente |
| Qualidade | Ruff, compileall, pip check com pacote local 0.5.2 instalado em container descartável, Pint, Composer validate/audit aprovados |
| Frontend | Build Vite herdado conferido em cópia descartável; nenhum node_modules/artefato frontend adicionado ao repo; UI usa Blade/CSS local |
| Backups | Dump de produção com schema novo; restore preenchido do lab recuperou 1 tenant, 1 user, 1 Agent, 1 enrollment e 3 eventos |
| Restore | FORCE RLS em cinco tabelas/trigger/grants revalidados; runtime sem owner e zero Agents visíveis sem contexto; database de restore removido |
| Operação | Quatro serviços de produção healthy; fila técnica e health/live/ready confirmados após deploy |
| Secrets | Comparação literal de APP_KEY, senhas de banco e token real do Agent contra logs de produção/lab, sem ocorrência; scan do índice Git antes do commit |

## Long-run final — 900,22 segundos

60 amostras de processo não root UID 65532, Client 0.5.2 contra Laravel/PostgreSQL lab. Nenhum endpoint stub Python substituiu a Central.

- 45 heartbeats bem-sucedidos; maior gap 20,493 s (<60 s).
- 174 polls bem-sucedidos; maior gap 6,328 s.
- RSS 28.308–29.476 KiB; crescimento 1.168 KiB durante inicialização/execução, sem tendência de leak evidente.
- FDs 4 no início do boot, até 8, normalmente 7; no máximo 1 socket, 3 threads e 0 filhos.
- CPU agregada observada: 1,44 s no intervalo (CLK_TCK=100 conferido).
- Journal integrity_check=ok em todas as amostras; zero eventos de tarefas, esperado porque tasks_enabled=false.

O ensaio foi iniciado novamente depois de finalizar schema/result do journal; somente a execução final completa conta para o critério. Dados sanitizados integrais: [evidence/recovery2-longrun.json](evidence/recovery2-longrun.json). Containers lab encerrados/removidos depois de concluir a medição, database lab removido e secrets temporários eliminados após backup privado. State do lab e artefatos foram preservados em backups fora do Git.

## Limites e riscos residuais

- Não há contrato histórico completo nem evidência para os oito nomes restantes das 14 capabilities. Esta entrega não finge suportá-los.
- start/renew/result retornam no_active_task; reconcile mantém hold. Não existem tarefas/leases executáveis nesta fase.
- Unit de produção está preparada, sem habilitação automática; produção precisa de URL HTTPS, usuário/config próprios e enrollment pelo proprietário. Nenhum usuário/Agent real de produção foi criado pelo teste.
- TLS interno e verify-full aguardam PKI. HTTP Central continua somente loopback; não publicar antes de TLS.
- RLS confia no código que estabelece contexto; credencial de runtime comprometida ainda permite abuso de DML, embora sem DDL/ownership. SHA de artefato não autentica publicador: verificar canal de distribuição.
- Backup externo e remoto Git privado continuam pendentes de destino/credenciais reais. Nenhuma réplica externa foi inventada.
