# RECOVERY-9 — identidade visual

Agent Center — Network Operations Workspace. Sidebar azul escura, marca agentcenter, estados compactos e linguagem comum aos componentes de todas as telas. Descrição histórica usada como referência: as três capturas antigas não foram fornecidas; nenhuma comparação pixel a pixel foi alegada.

| Área | Paridade visual / refinamento |
|---|---|
| Sidebar | OBSERVAR, INVESTIGAR, ENSINAR, AVANÇADO, GOVERNAR, OPERAÇÕES; Backups R2 administrativo existente em PROTEGER |
| Contexto | PLATFORM CONTEXT / TENANT CONTEXT e tenant ativo, usuário/papel; gates mantidos |
| Claro/escuro | Sistema como padrão; botão alterna sistema → claro → escuro. localStorage separado por ID de usuário e origem do navegador; não sincroniza entre dispositivos |
| Dashboard | Métricas reais, atividade, diagnósticos recentes e aviso explícito de ausência de Agent; sem gráfico artificial |
| Diagnostics | Entrada existente, progresso DNS A/AAAA agrupado, TCP/HTTPS, evidências e detalhes; Latência/Rota dispensados quando não suportados; NORMAL conserva ressalva do escopo |
| Conversation | Histórico, mensagens e estados preservados; estilo compartilhado, funcionamento sem provider externo; rollout por tenant intacto |
| Network Studio | Profiles/Skills, Knowledge, Prompts e LLM usam superfícies, formulários, tabelas e estados consistentes; versões/publicação e opções avançadas existentes preservadas |
| Agents | Inventário, onboarding, aprovação e operações existentes; nenhum self-update real ou presença simulada anunciado |
| Governança / Operations | Auditoria, usuários, tenants, backups e saúde recebem os mesmos tokens de tema, contraste e foco; gates técnicos sem suavização |
| Responsividade | 1366×768, 1920×1080, 768×1024, 390×844; tabelas reorganizadas/navegáveis e sidebar recolhível |

Screenshots reais: evidence/recovery9/screenshots/. Dados sintéticos claramente identificados como laboratório. Incluem desktop/mobile, claro/escuro, resultados, formulários, modais, contexto plataforma, tenants e backups. Não representam presença de Agents reais em produção.

Contraste calculado em Chromium em superfícies opacas: primeira rodada 20 ocorrências (cabeçalhos claros e controles escuros); correção e repetição: zero ocorrências nas cinco telas auditadas por tema. Isso não equivale a certificação WCAG integral. Foco, skip link, Escape, feedback de erro, loading e modal são verificados no navegador. Sem dependência de UI nova; build Vite existente agora tem package-lock reproduzível.
