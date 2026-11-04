# RECOVERY-3 — validação

Base: ada5edb (RECOVERY-2). Escopo: identidade preservada, Task Engine, Diagnostics, Profiles/Skills; sem FastAPI, LLM, device access ou instalação de Agent em produção.

## Resultado das verificações

| Verificação | Resultado |
|---|---|
| Laravel + PostgreSQL isolado, runtime não owner | 63 testes / 574 assertions PASS |
| Migrate + rollback no banco de teste | PASS |
| Python 3.13, subprocesso, journal, rede, timing, update/rollback | 40 testes PASS |
| Golden fixtures | 18 cenários, 12 SITE_LENTO, seis Profiles PASS |
| Claims concorrentes em processos separados | PASS |
| Workers concorrentes / materialização única | PASS |
| Resultados simultâneos / confirmação idempotente | PASS |
| Python real ↔ Laravel HTTP, oito endpoints | PASS |
| DNS A/AAAA localhost no lab | COMPLETED / NORMAL, evidências persistidas |
| FORCE RLS | 13 tabelas, testes explícitos de acesso cruzado PASS |
| Planos / conclusões append-only | Grants, models, triggers PASS |
| Health worker monotônico / marker obsoleto / contexto limpo | PASS |
| Compose config | PASS |
| Web / queue worker / diagnostic-worker / PG / Nginx | Healthy |
| /health/live e /health/ready via Nginx | 200 / 200 |
| Composer validate --strict / audit --locked | PASS / nenhum advisory |
| Pint | 95 arquivos PASS |
| Ruff / format / compileall / pip check | PASS |
| Frontend Vite | PASS em Node24 efêmero; sem alterar dependências do projeto |
| systemd-analyze verify | PASS; unit não instalada globalmente |
| Self-check do artefato 0.5.2 | Quatro capabilities disponíveis, freeze true |
| Backup/restore lab com dados | PASS, todas as tabelas novas incluídas |
| Backup/restore da produção com schema atual | PASS, seis migrations / FORCE RLS / runtime sem owner |
| Long-run adicional | 900,22s PASS — heartbeat gap 20,28s; poll gap 15,75s; journal íntegro |
| Git diff / secret scan | PASS — índice completo, sem secrets encontrados |

As seis capabilities comprovadas são o teto do catálogo. O lab não contém binários ping/traceroute; portanto apenas quatro são anunciadas. Nenhuma capability adicional foi criada. Fixtures de parser/argv cobrem probes opcionais; conectividade ICMP/traceroute em hardware não foi testada.

## Evidências e operação

Ver docs/evidence/recovery3-concurrency.json e recovery3-packaged-selfcheck.json. Dois diagnósticos DNS de localhost foram executados no lab com Agent real e banco separado. Reinício controlado de Laravel/worker do lab durante observação verificou recuperação de polling, sem parar a thread heartbeat.

Produção: imagem agent-center-central:recovery3. Imagem anterior preservada em agent-center-central:recovery3-rollback-ada5edb; overlay deploy/rollback-recovery3.yaml e procedimento em DEPLOYMENT.md. Migration aditiva e grants aplicados antes da troca de serviços. Nginx do projeto recarregado; PostgreSQL e serviços não relacionados não foram reiniciados. Nenhum usuário/Agent fictício inserido em produção.

A primeira seleção de restore por ordenação do nome pegou um backup anterior, detectado pela checagem de schema. O backup exato criado nesta fase foi então restaurado e validado; não houve alteração do banco de produção pelo restore.

## Limites residuais

Sem domínio/PKI final e destino externo autenticado para backup. Exposição web continua apenas 127.0.0.1:18083; TLS PostgreSQL prefer não foi enfraquecido, verify-full/HOSTSSL continuam preparados. Backup local não protege contra perda da VPS; configurar exportação externa privada/criptografada.

At-most-once favorece segurança sobre disponibilidade: START com outcome desconhecido ou lease expirada em RUNNING não dispara nova execução automática. Novo diagnóstico autorizado é necessário. Observação de 15 minutos não comprova ausência de leaks em prazos indefinidos.

Agent permanece 0.5.2 com negociação aditiva task_protocol:1 e journal schema1. Produção não possui Agent instalado; alvos reais externos e todos os vendors continuam bloqueados. Roadmap documenta 29 referências desativadas, sem importar código Hermes.

Frontend foi compilado em container temporário com resolução das versões já declaradas no package.json; o template não possui package-lock versionado. A UI desta fase usa Blade/CSS existente e não depende do bundle gerado.

## Observação contínua final

Evidência: docs/evidence/recovery3-longrun.json. 60 amostras, RSS 29848–30240 KiB (+392 KiB), FDs 7–9, até quatro threads, zero filhos nas amostras e nenhum filho restante, CPU 1,62s. Journal íntegro com 16 eventos de quatro execuções DNS; nenhuma reexecução por replay. Contadores de heartbeat/poll são cumulativos desde o início do runtime, anterior ao primeiro sample.

Lab encerrado após validação; state/config/journal arquivados em backups/secrets com modo 0600, fora do Git. Database de laboratório removido; nenhuma fixture ficou em produção. Somente os cinco serviços de produção do Agent Center permanecem ativos.

**RECOVERY-3 PASS**
