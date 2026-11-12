# Homologação — demonstração administrativa

**DEVICE ANALYSIS V1 DEMO COMPATIBILITY PASS**

Base: `2186588` + `3c5dd48`. Correção exclusivamente do fluxo de demonstração, sem publicação em produção.

| Verificação | Resultado |
|---|---|
| Laravel completo em PostgreSQL/FORCE RLS | PASS — 552 testes, 4.963 asserções, sem warnings |
| APP_ENV=production: flag, tenant, admin, autenticação e CSRF | PASS |
| 34 cenários administrativos e snapshots dos demais estados protegidos | PASS — somente simulações/auditoria alteradas |
| Leitura por operator/viewer e após revogação da flag | PASS — evidência administrativa oculta |
| Dois tenants, idempotência, revisão e replay entre contextos | PASS |
| Web/Telegram operacionais sem simulação automática ou confirmação remota | PASS |
| Concorrência Task Engine, conversa e Preflight | PASS |
| Concorrência administrativa específica em production | PASS — quatro processos, uma simulação e uma auditoria, zero tarefas |
| Fixtures nas três imagens candidatas sem rede/banco | PASS — 34 por imagem |
| Pint | PASS — 345 arquivos |
| Build Vite | PASS |
| Interface na imagem candidata, sem código da aplicação montado | PASS — 12 combinações de equipamento/tamanho/tema, teclado, CSRF e formulário nativo |
| Rotas e usuário de execução dos três papéis | PASS — anteriores preservados; somente um POST administrativo novo na Web/queue |
| Rollback das migrations no banco descartável | PASS — nenhuma migration nova neste patch |
| Secret scan do índice e diff check | PASS antes do commit |

## Correção e limites

O bloqueio original em `Contracts::labEnabled()` continua protegendo conversas em production. A nova autorização administrativa exige feature flag própria, tenant habilitado e Policy administrativa. Contexto tipado `ADMINISTRATIVE_DEMO`, histórico imutável separado e recusa pelos consumidores conversacionais impedem promoção para operação real; não é somente um badge.

A descoberta das rotas rejeitou o primeiro empacotamento com o arquivo web completo do checkout, que continha referências a módulos não publicados. O artefato final registra somente a rota administrativa fechada sobre as rotas presentes na imagem operacional. Não incorpora Observability. A primeira regressão de 552 testes também passou; a regressão final foi repetida após acrescentar a proteção de leitura e ajustar o empacotamento.

Os 34 cenários são os mesmos homologados. Nenhum executor, consulta real, credencial, conexão a equipamento, alerta, métrica de Health ou tarefa foi criado. Alarmes Huawei continuam DOCUMENTED. A compatibilidade de firmware é uma declaração para fixtures, sem qualificação de coletor CLI real.

## Telas

| Equipamento | Desktop claro | Mobile escuro |
|---|---|---|
| Huawei NE8000 | [Screenshot](demo-ne8000-1440-light.png) | [Screenshot](demo-ne8000-390-dark.png) |
| MikroTik RouterOS 7 | [Screenshot](demo-routeros7-1440-light.png) | [Screenshot](demo-routeros7-390-dark.png) |

[Prova da interface](ui-proof.json), [fixtures offline](offline-proof.json), [rotas](route-proof.json), [preservação](preservation.json). O teste de interface utiliza HTTP de loopback com sessão nativa e CSRF, APP_ENV=production e a imagem final; não certifica o HTTPS do domínio operacional. Cookies temporários foram invalidados e removidos, fora do Git. Telegram utilizou transporte fake, sem mensagem a um bot real.

## Produção, publicação e rollback

As três imagens operacionais continuam as anteriores do Inventário NOC, com seus processos ativos. Os 220 arquivos preexistentes conferidos no checkout operacional mantêm seus hashes. Nenhuma publicação, atualização do Agent ou alteração operacional foi executada.

[Arquitetura, configurações, reprodução e plano de deploy/rollback](../../DEVICE_ANALYSIS_DEMO_COMPATIBILITY.md). As novas flags são desabilitadas por padrão. O rollback funcional revoga a flag, bloqueia geração/replay e oculta resultados administrativos, preservando dados. Uma futura reversão de imagem exige preservar o filtro de leitura administrativo no artefato de rollback; não realizar reversão cega nem apagar histórico.

## Reprodução

Suíte: `scripts/test.sh` com os overrides de PostgreSQL isolado descritos em `app/AGENTS.md`, habilitando `TEST_DIAGNOSTIC_CONCURRENCY=1` e `TEST_CONVERSATION_IDLE_CONCURRENCY=1`. Os testes novos estão em `DeviceAnalysisDemoTest` e no caso `test_production_admin_demo_flag_never_enables_telegram_simulation_or_real_device_tasks` de `TelegramTest`.

Concorrência administrativa: programa existente `tests/Support/device-analysis-concurrency.php`, no container tooling, com APP_ENV=production, `DEVICE_ANALYSIS_TEST_ADMIN_DEMO=1` e banco obrigatório `agent_center_device_analysis_lab`. O guard rejeita outros bancos antes de criar fixtures. Esse modo é exclusivo do programa de teste e não configura a aplicação operacional.
