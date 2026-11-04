# RECOVERY-4 — validação

Base: 1f36c51. UI e read models operacionais; Task Engine, classifier, leases, Agent API e Python 0.5.2 preservados. AGENTS.md não foi alterado. Nenhum device, FastAPI, LLM, Knowledge, RAG ou websocket implementado.

## Entrega

Sidebar agrupada com autorização nativa; dashboard com fontes reais e empty state explícito; Agents com presença/capabilities/auditoria; novo diagnóstico Perfil → Target → Agent; progresso lógico e conclusão; histórico filtrado/paginado; Perfis e Skills separados; auditoria legível; Operations Health dedicado. CSS/JS próprios, sem framework admin ou dependências de produção novas.

Migration 2026_11_04_190000 adiciona somente quatro índices de leitura; relações Eloquent/eager loading evitam N+1. Filters usam FormRequests, Policies e scopes/RLS existentes. X-AC-Tenant evita misturar dados de um novo contexto com o cabeçalho antigo durante polling.

## Verificações

| Verificação | Resultado |
|---|---|
| Laravel / PostgreSQL runtime não owner | 80 testes / 713 assertions PASS |
| Auth, RBAC e isolamento entre tenants | PASS, incluindo fragments e páginas novas |
| Migrate / rollback no banco de teste | PASS, sete migrations |
| Filtros período/Profile/Agent/status/classificação/target | PASS |
| Paginação de fragments / consultas sem N+1 | PASS |
| Chromium end-to-end | 96 verificações PASS |
| Capturas reais | 22 PNGs, desktop e mobile |
| Viewports | 1366x768, 1024x700, 768x1024, 390x844, 360x740 |
| Overflow de página nos layouts verificados | Nenhum |
| Atualização sem navegação / details preservados | PASS |
| Confirmação pausando polling / Escape cancela | PASS |
| Tenant alterado interrompe substituição do painel | PASS |
| Contraste da paleta essencial >=4,5:1 | PASS |
| Pint | 101 arquivos PASS |
| Composer validate --strict / audit --locked | PASS / nenhum advisory |
| Frontend Vite em Node24 efêmero | PASS |
| Docker Compose config | PASS |
| Web, queue worker, diagnostic-worker, PG, Nginx | Healthy |
| /health/live e /health/ready via Nginx | 200 / 200 |
| CSS e JavaScript locais via Nginx | 200 / 200 |
| git diff --check / secret scan do índice | PASS |

## Evidências reais

[Galeria](evidence/recovery4/README.md), [validação do navegador](evidence/recovery4/browser-validation.json), [guia de UX](WEB_UX.md).

Capturas renderizadas pelo Chromium contra Laravel/PostgreSQL em agent_center_lab, sem tocar dados de produção. Tenant e Agents são identificados como LAB/fixtures. As evidências de diagnóstico são sintéticas para testar apresentação; não representam sondagens a destinos externos. Heartbeat/poll do harness usam os endpoints autenticados reais; o scheduler e as leases também são reais do laboratório. As imagens não são mockups desenhados ou imagens geradas.

Chromium/Playwright 1.56.1 já disponível foi reutilizado em container efêmero próprio agent-center-browser:recovery4; imagem sha256:3831cf307207f00f34be74716420f5372853c24e9c89b843421f2a560756233d. Navegação restrita a localhost:18084. Nenhuma biblioteca de browser entrou na aplicação.

## Deploy e rollback

Imagem ativa: agent-center-central:recovery4. A migration aditiva foi aplicada pelo migrator antes de recriar somente web/worker/diagnostic-worker. Nginx do Agent Center foi recarregado; PostgreSQL e serviços não relacionados não foram reiniciados. Nenhuma fixture, usuário ou Agent novo foi inserido na produção.

Rollback: imagem recovery4-rollback-1f36c51 e overlay deploy/rollback-recovery4.yaml, preservando dados e índices. Backup anterior preservado; backup adicional após commit faz parte do encerramento. Laboratório encerrado, volume temporário e database removidos; credenciais efêmeras eliminadas após salvar as evidências.

## Limites residuais

Sem auditoria manual por leitor de tela ou teste em dispositivos físicos; responsividade e interações foram verificadas em Chromium headless. Paleta validada não constitui certificação integral de acessibilidade.

Seletores auxiliares de Agent/usuário limitados a 100 opções; target substring pode examinar os registros do tenant. Sem browser/JavaScript, páginas e filtros permanecem server-rendered, mas atualização automática e diálogo dependem de JS. A UI depende de sessão/banco; monitoramento externo usa live/ready.

O template mantém package.json sem package-lock versionado. Build foi realizado isoladamente, com as versões declaradas; UI desta fase usa assets locais próprios sem depender do bundle Vite.

Agent real em produção, domínio/PKI final e exportação externa de backup continuam pendências da infraestrutura, fora do escopo UX. Nenhuma métrica foi preenchida artificialmente para esconder ausência de dados.

**RECOVERY-4 PASS**
