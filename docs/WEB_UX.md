# Web UX — Agent Center

## Identidade e navegação

Interface clara, operacional, com azul institucional, superfícies neutras e cores semânticas. CSS e JavaScript locais, sem CDN, Bootstrap, template admin ou framework novo. Tipografia de sistema; continuidade visual com AI Workspace / Network Studio / NOC Intelligence é expressa pela marca e hierarquia, sem ativar outros produtos.

Sidebar: OBSERVAR (Visão geral, Agents); INVESTIGAR (Diagnósticos, Histórico); ENSINAR (Perfis, Skills); GOVERNAR (Auditoria, Usuários, Tenants); OPERAÇÕES (Saúde do sistema). Links respeitam Gates existentes. Platform admin precisa selecionar tenant para dados operacionais. Cabeçalho identifica o contexto, inclusive no mobile.

## Fontes do dashboard

OperationalSnapshot usa consultas PostgreSQL sob TenantScope/FORCE RLS. Sem métricas geradas ou valores de demonstração em produção.

- Agents online / total: ativos e não revogados; ONLINE exige heartbeat <60s.
- Diagnósticos hoje: created_at desde o início do dia no timezone da aplicação.
- Indícios de falha: conclusões INDICIO_DE_FALHA dos diagnósticos criados hoje.
- Tasks ativas: CLAIMED/RUNNING do tenant.
- Worker: marker monotônico existente, idade <15s; PostgreSQL: SELECT 1.
- Heartbeat/poll agregado: maior idade entre Agents ativos. Registros ausentes são apresentados como ausentes, nunca como 0s.
- Recentes: seis diagnósticos, com Profile/Agent/conclusão carregados em lote; atividade limitada a cinco eventos. Operator/viewer veem somente sua própria atividade de auditoria no dashboard.

Sem tenant selecionado, a plataforma recebe um empty state explícito. Sem Agents, o estado é ATENÇÃO, não VERDE. A duração vem de started_at/finished_at; investigação ainda não iniciada não recebe duração inventada.

## Saúde operacional

VERDE: banco e worker disponíveis, sem lease ativa vencida, todos os Agents ativos online e poll fresco, sem alertas do dia.

ATENÇÃO: nenhum Agent, Agent offline/sem heartbeat ou poll, poll agregado >30s, indício de falha ou attempt FAILED/EXPIRED criado hoje.

NO-GO: banco indisponível, worker obsoleto/indisponível ou lease ativa vencida.

Detalhe dedicado mostra web, worker, PostgreSQL, engine, Agents, tasks, leases e attempts. Engine combina disponibilidade do loop e ausência de leases vencidas; não representa um teste sintético de diagnóstico completo. Health HTTP oferece os endpoints reais live/ready; monitor externo não foi inventado. Autenticação/sessão usa PostgreSQL: em indisponibilidade total do banco, a tela autenticada pode não abrir; /health/ready continua sendo o endpoint próprio para monitoramento.

## Fluxos e componentes

Novo diagnóstico: Perfil → Target → Agent → Executar. Um único Agent elegível é selecionado automaticamente. Offline é indicado no seletor; seleção mantém o comportamento de fila do backend. A UI não mostra provider, prompt, binding ou fencing.

Progresso agrupa A/AAAA em DNS e mantém as evidências separadas. Etapas lógicas DNS, TCP443, HTTPS, Latência e Rota mostram estados, tempos e erros. Uma etapa BLOCKED ou EXPIRED permanece identificada; não é transformada em sucesso.

Conclusão e resumo do classifier recebem destaque. NORMAL mantém exatamente: “Não foram encontradas anormalidades no escopo testado.” Próximos passos são os persistidos pelo backend. Detalhes JSON exibem apenas os resultados já normalizados; digest e credenciais de lease não são expostos.

Histórico: período inclusivo por dia, Profile, Agent, status, classificação e target. Target usa correspondência literal, escapando curingas SQL. Paginação de 25; relações eager-loaded. Quatro índices de leitura cobrem ordenação por tenant/data, Profile, Agent e classificação. Busca substring pode examinar registros do tenant; não foi adicionado pg_trgm nesta fase. Seletores auxiliares limitam Agents/usuários a 100 opções para evitar carregamento ilimitado.

Perfis têm composição, thresholds, revisão e teste de fixtures offline. Skills mostram capability, revisão, timeout, enabled e safe/read-only. Não existe editor de comandos. Auditoria apresenta usuário/tenant/evento/entidade e resumo por allowlist de campos; metadata desconhecida não é renderizada como texto livre.

## Atualização leve

Native fetch, sem websocket: 10s em dashboard/Agents/health e 4s no progresso; timeout 8s e backoff até 60s. Fragmentos HTML passam por Auth/Policies/RLS e Cache-Control no-store/private. Pausa com aba oculta, foco dentro do painel, texto selecionado ou confirmação aberta. Preserva details abertos; não navega o documento nem troca formulários externos ao painel. Paginação nunca conserva fragment=1.

Resposta contém X-AC-Fragment e X-AC-Tenant. O browser valida ambos antes da substituição: mudança de contexto em outra aba, sessão expirada ou autorização removida interrompe atualização e pede recarga. Resultado terminal interrompe polling. Falha de rede mantém os últimos dados visíveis e informa tentativa posterior.

## Acessibilidade e responsividade

Labels nativos, skip link, foco visível, menu acessível por teclado/Escape, badges com texto (sem depender da cor), role=status para feedback e aria-busy/progressbar. Confirmações destrutivas usam dialog, foco inicial na opção Voltar e reset do resultado para cancelar; Escape não confirma. O polling pausa enquanto o diálogo estiver aberto.

Em <=1100px os grids reduzem colunas; em <=760px a sidebar vira drawer e as tabelas principais viram cartões rotulados, mantendo os dados. O tenant continua no cabeçalho. Navegação fechada fica fora da ordem de foco. Respeita prefers-reduced-motion. Paleta essencial verificada com contraste >=4,5:1; isso não substitui uma auditoria de acessibilidade com tecnologias assistivas.

## Evidências

Galeria em evidence/recovery4/README.md. Capturas reais Chromium de Laravel/PostgreSQL no laboratório isolado, com fixtures sintéticas identificadas pelo nome do tenant. Não são dados de produção nem sondagens reais a destinos externos. Viewports 1366x768 e 390x844; PNGs full-page. Verificações adicionais em 1024x700, 768x1024 e 360x740.

Harness scripts/lab/web-ux-browser.cjs lê credenciais somente de /lab/credentials.json privado, restringe navegação ao localhost:18084 e grava evidências sem cookies/tokens. Fixture PHP recusa database diferente de agent_center_lab. Tooling usa Chromium/Playwright já disponível, em container efêmero; orientação oficial: https://playwright.dev/docs/docker . Não há dependência de browser na produção.
