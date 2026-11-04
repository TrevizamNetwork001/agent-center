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

## Implementação entregue

Reutilizados: Laravel Auth/Session/CSRF/Policies/Gates, tenant scope/GUC/FORCE RLS, Agent API, heartbeat/poll independentes, allowlist, Task Engine, leases/fencing, journal SQLite, updater/rollback e UI existentes. AGENTS.md raiz/app preservados, sem FastAPI ou execução device.

Cadastro cria identidade registrada sem bearer utilizável; enrollment consome código e aguarda revisão. Receipt temporário permite emissão única após aprovação. Detalhe/lista com polling, revisão explícita, revogação, inventário real e operações. Ausências são indisponíveis. Tenant é vinculado no cadastro e confirmado na aprovação; não se move identidade ativa entre tenants.

Release 0.5.3 implementada; 0.5.2 reconstruída de ff168b9 e testada separadamente (40 testes). Catálogo inclui SHA, artefato, compatibilidade, data/status/changelog/Git e quatro capabilities comprovadas. Nenhuma release da VPS perdida foi inventada.

Update/rollback: intenções estruturadas, precheck, plano, aprovação, companion estreito com catálogo root local previamente instalado, parada/swap/backup/postcheck/retorno. Nenhum script/URL/caminho livre. Locks de manutenção/instância, previous/failed, journal preservado, recuperação incerta em hold e resolução manual com revisão explícita de host. FKs compostas impedem cruzamento de tenant também em SQL direto.

Operations Health acrescenta conclusão real de job de fila, valores/limites/timestamps, checks de geração/fencing, manutenção interrompida e ingestão de probes fixos externos. Não usa commits PostgreSQL como prova de saúde. Collector/timer e log de status sanitizado preparados; ausência/expiração da evidência continua ATENÇÃO. Não foram presumidos ativos em produção.

## Testes e evidências

- Laravel/PostgreSQL: 132 testes, 1.087 assertions; migrations up/down, runtime sem ownership, 28 tabelas FORCE RLS, testes negativos HTTP/RBAC/RLS e FK composta. Nova UI também revalidada após campos de fila/última operação.
- Python: 52 testes; runtime, onboarding retomável, leases, execução, heartbeat independente, journal, update/rollback, falhas, crash, pacotes/SHA inválidos, freeze e telemetria sem crescimento de FDs.
- Baseline reconstruído 0.5.2: 40 testes independentes.
- Contrato HTTP real: enrollment/aprovação/credencial, Agent autenticado, heartbeat/poll/capabilities, precheck/apply/report, atualização 0.5.2→0.5.3 e rollback 0.5.3→0.5.2 em container dedicado com parada/partida reais. Backup consistente de cada operação verificado; identidade e entrada uncertain do journal mantidas. Serviço controlado pelo harness Docker, não por systemd real.
- Instalador: pacote/manifesto íntegros, layout isolado, recusa de sobrescrita, erro de permissões encontrado e corrigido com regressão. Self-check do runtime instalado como UID 65532 passou. A unit systemd foi verificada por systemd-analyze; instalação completa numa máquina Debian 13 com systemd real permanece pendente.
- Chromium: sete screenshots desktop/mobile, sem overflow, fragmentos, ausência de campos secretos, ausência de token antes da aprovação, checkbox/CSRF e revogação da identidade de teste. Caminho Browser→Nginx→Laravel/FPM→PostgreSQL testado separadamente, com readiness 200 e o mesmo fluxo autenticado.
- Pint, Ruff, compileall, pip check, Composer validate/audit e frontend build passaram. O build frontend usa Node24 Alpine, compatível com os módulos nativos existentes; tentativa em Debian falhou por binding musl/glibc e não foi tratada como sucesso.
- git diff --check e secret scan do índice são exigidos novamente antes da entrega. Scanner é básico, não garante detecção universal de secrets em texto livre.

## Ensaios prolongados

Primeiro ensaio durante migração do laboratório teve poll gap acima do limite e foi rejeitado. Outro ensaio de 901,52s teve 56 amostras, 46 heartbeats, 175 polls, gap heartbeat 20,45s/poll 6,32s, RSS +352 KiB, 8–9 FDs, 3 threads, zero filhos, journal íntegro, worker/banco/fila funcionais em todas as amostras. Resultado global desse ensaio: FAIL, pois um snapshot capturou o estado transitório normal CLAIM; o critério adicional exigia ONLINE em todas as amostras. Arquivos `longrun-first-*` preservam a falha. Nenhum limite foi relaxado; repetição independente de 15 minutos em andamento, resultado a acrescentar ao fechamento.

## Backup e restore

Backup local antes de operações incluiu PostgreSQL, env/APP_KEY, configuração, Git bundle e TLS. Manifesto agora cobre todos os arquivos da geração, incluindo secrets/bundle, e é verificado. Restore RECOVERY-6 foi validado com verificador do mesmo commit, sem tentar exigir tabela RECOVERY-7 num dump anterior. Restore do laboratório RECOVERY-7 passou com 28 FORCE RLS, triggers, views/grants e isolamento de runtime. Chave master recuperada de arquivo confidencial separado e comparada à original sem emitir valor. Dois backups do Agent tiveram hashes/identidade verificados. Nunca se restaurou sobre produção ou sobre journal atual.

A restauração ocorre em banco/diretórios/containers separados nesta VPS; não comprova recuperação depois da perda da própria VPS. Destino externo autorizado e chave de recuperação fora da VPS não foram identificados. Não há réplica externa configurada nem disaster recovery completo.

## Produção e riscos residuais

Acesso HTTPS do IP 45.182.96.18 liberado a pedido, com nginx -t e reload específicos; usuário confirmou acesso. Gate permanece fechado aos demais endereços. A RECOVERY-7 foi construída/validada em checkout isolado e imagem local; produção não foi promovida automaticamente enquanto faltam os pré-requisitos externos.

Nenhum host real foi identificado/autorizado para instalar Agent. A presença ONLINE mostrada nas evidências é exclusivamente do laboratório. Não houve acesso a equipamento, SSH/Huawei ou servidor externo. A instalação e o timer do companion exigem autorização do host, staging de release root e backup externo verificado. Logs de erro livres do runtime não são enviados à Central; último erro de runtime aparece Indisponível, e erros de manutenção são códigos fechados. Relato do próprio Agent não é atestado criptográfico nem prova de saúde do systemd. Fingerprint precisa de revisão fora de banda. Collector externo/rotação/status log precisam ser aplicados no deploy autorizado com output no storage compartilhado correto.
