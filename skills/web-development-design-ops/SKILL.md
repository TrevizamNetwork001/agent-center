---
name: web-development-design-ops
description: Criar, revisar e melhorar layouts, páginas, dashboards e componentes do Agent Center com responsividade, acessibilidade, desempenho e validação visual, preservando Laravel e os controles existentes.
---

# Layout e interface do Agent Center

Adaptada das diretrizes de [web-development-design-ops, Hermes Tutor](https://github.com/clfigueiredo/hermes-infra-skills/blob/main/.hermes/skills/forumtelecom/web-development-design-ops/SKILL.md). Aplicar em todos os trabalhos de interface deste projeto. Esta skill orienta o desenvolvimento; não é uma Skill operacional do Agent.

## Contexto e limites

- Ler AGENTS.md da raiz e as regras locais dos arquivos alterados; essas regras prevalecem.
- Manter Laravel e PostgreSQL. Reutilizar Blade, componentes, CSS, JavaScript e React/Vite onde já adotados. Não introduzir outro runtime, servidor Python ou framework para aplicar estas dicas.
- Preservar Laravel Auth, Session, CSRF, Hash, Policies/Gates, RBAC, auditoria e FORCE RLS. Ocultar botões não substitui autorização no servidor.
- Preservar isolamento por tenant em dados, consultas, jobs e cache; alterações de domínio exigem testes contra acesso cruzado.
- Não expor secrets no frontend, ampliar autoridade do LLM, habilitar Skills operacionais ou adicionar integrações por meio de alterações visuais.
- Deploy, ações de device e alterações operacionais continuam sujeitos às autorizações específicas do AGENTS.md. Não executar shell arbitrário nem modificar serviços alheios ao projeto.

## Antes de implementar

- Identificar objetivo da tela, público, conteúdo, tarefa principal e estados relevantes. Inferir do pedido e do produto; esclarecer apenas lacunas que afetem materialmente o resultado.
- Inspecionar rotas, layouts base, componentes compartilhados, temas e scripts de build existentes. Preservar alterações locais de outras tarefas.
- Definir brevemente direção visual, hierarquia, paleta, tipografia e espaçamentos antes de mudanças amplas.
- Para requisitos globais, preferir componentes e tokens compartilhados. Evitar CSS repetido e reformulações fora do escopo.

## Padrão visual

- Tornar a tarefa e a ação principal claras. Dashboards devem priorizar informação útil à operação e explicitar estados e unidades.
- Reutilizar identidade e ícones existentes, com tipografia legível, paleta curta e espaçamentos consistentes. Usar a escala existente ou, na ausência, uma escala baseada em 4/8 px.
- Implementar mobile-first e conferir larguras intermediárias e desktop. Evitar overflow da página e conteúdo cortado; tabelas largas podem usar um contêiner de rolagem acessível.
- Preservar temas claro/escuro quando existentes, inclusive contraste em gráficos, badges, tabelas e formulários.
- Prever estados de carregamento, vazio, erro, sucesso e indisponibilidade quando aplicáveis. Não apresentar placeholders como dados operacionais reais.
- Usar rótulos claros em português conforme o produto. Evitar detalhes de implementação sem utilidade para o usuário.

## Acessibilidade

- Usar HTML semântico, títulos hierárquicos, botões para ações e links para navegação.
- Associar labels e erros aos campos; fornecer nomes acessíveis a controles com ícones e textos alternativos a imagens informativas.
- Garantir navegação por teclado, foco visível e ordem coerente, incluindo menus e diálogos.
- Buscar contraste WCAG AA, comunicar estados além da cor e respeitar prefers-reduced-motion. Evitar animações que atrapalhem leitura e operação.

## Desempenho e páginas públicas

- Minimizar JavaScript, bibliotecas, fontes externas e animações. Usar o pipeline de assets existente; evitar CDN e rastreadores novos sem escopo autorizado.
- Otimizar imagens, declarar dimensões e usar lazy loading abaixo da dobra quando adequado. Preferir WebP/AVIF quando compatíveis.
- Evitar consultas repetidas e polling excessivo. Mudanças de cache e atualização devem manter isolamento por tenant.
- Em páginas públicas, revisar title, description, viewport, headings e Open Graph; canonical, sitemap e robots devem refletir domínio e rotas reais. Não expor conteúdo autenticado para SEO.
- Formulários precisam de validação no servidor, CSRF e estados de sucesso/erro. Identificar interações demonstrativas; não adicionar WhatsApp ou outro canal sem escopo autorizado.

## Verificação e entrega

- Implementar em partes verificáveis e reutilizar componentes existentes.
- Consultar os scripts do projeto antes de validar. Executar PHP/Composer nos containers do projeto; seguir app/AGENTS.md e scripts/test.sh para Laravel/PostgreSQL.
- Executar build/lint pertinentes quando disponíveis, sem mascarar falhas. Alterações de comportamento, autorização ou domínio exigem testes correspondentes e de isolamento entre tenants. Ajustes puramente visuais não precisam de testes que apenas reproduzam CSS.
- Quando houver navegador disponível, revisar mobile, largura intermediária e desktop, temas existentes, console, links, formulários, teclado e estados relevantes. Registrar quando a revisão visual não puder ser realizada.
- Executar git diff --check e revisar o diff para excluir alterações alheias ao pedido.
- Relatar mudanças, arquivos relevantes, validações realmente executadas e limitações. Não declarar build, testes, revisão visual ou deploy sem evidência.
- Alterações operacionais autorizadas devem ter rollback documentado preservando dados. Para reverter somente esta orientação, remover a seção correspondente do AGENTS.md e esta pasta; nenhum dado ou serviço precisa mudar.
