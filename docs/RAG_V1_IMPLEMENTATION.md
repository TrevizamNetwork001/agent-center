# RAG V1 — implementação

Candidato isolado em `/root/agent-center-rag-v1`, baseado em `a3b091b`. Nenhum arquivo de produção foi substituído. AGENTS.md raiz e app preservados. Contrato: [RAG_V1_SPEC.md](RAG_V1_SPEC.md).

## Componentes reutilizados

- `Knowledge/Versioning`: draft/publicação/supersede/rollback/arquivo imutáveis. Integração em publicação e arquivo, sob o mesmo row lock/transação.
- `KnowledgeItem`, `KnowledgeVersion`, `Prompt`, `PromptVersion`, `LlmBinding`, `LlmProvider`, `LlmModel`: nenhum cadastro paralelo.
- `Llm/Client`: a opção interna `purpose=knowledge` seleciona parser JSON fechado de resposta com fontes. Default diagnóstico preservado. SecretVault AES-GCM, NetworkPolicy/SSRF/DNS pin/TLS, transporte, timeout, retries, limites de entrada/saída e redaction existentes são reutilizados.
- `Conversation/Engine`: nova intenção determinística `KNOWLEDGE_QUESTION` para procedimentos e consulta explícita à Knowledge. RAG não muda o estado, alvo, Profile, classificação, contexto operacional ou tarefas. Locks, request_key, histórico append-only e ownership existentes preservados. Solicitações de execução seguem o detector/Task Engine original.
- Gates `read-knowledge` e `manage-intelligence`, TenantContext, FORCE RLS, sessão, CSRF e serviço Audit existentes.
- Layout Blade e assets existentes; React/Vite do dashboard não foi substituído.

## Schema e fluxo

Migration `2026_11_07_210000_create_rag_index` cria duas tabelas derivadas: `rag_documents` (documento, versão atual indexada, SHA-256 de conteúdo/título/algoritmo, contagem e data) e `rag_chunks` (UUID de fonte, tenant/documento/versão, posição, offsets Unicode, título/seção e texto). Ambas com FORCE RLS por app.tenant_id e FKs compostas; índice GIN no tsvector gerado. Grants runtime SELECT/INSERT/UPDATE/DELETE limitados a essas tabelas reconstruíveis. Payloads originais e eventos imutáveis não mudam.

`Rag/Index` divide texto em até 1000 caracteres por trecho, sem overlap. Títulos Markdown simples fornecem seção; demais formatos textuais usam o título do documento. A mesma versão/digest/algoritmo com contagem íntegra é no-op, preservando UUIDs. Mudança/reparo substitui os fragmentos atomicamente. Arquivar exclui o índice derivado. Reindexação administrativa percorre itens em lotes de 50, transação e lock por item. Erro retorna `INDEX_UNAVAILABLE` sanitizado na UI; versões já válidas permanecem válidas. Falha durante publicação reverte também a publicação.

`Rag/Retrieval` faz FTS português (stems) e simple (DNS/TCP/HTTP, siglas/termos técnicos), OR de termos com stopwords locais e equivalências mínimas site/web e lento/lentidão. Título tem peso A, conteúdo português B e simple C, ranking ts_rank_cd. As consultas usam bindings SQL, tenant explícito e RLS; joins exigem item e versão publicados e current_version_id coincidente. Algoritmo inválido, manifesto ausente/antigo ou contagem parcial sinaliza índice incompleto. Máximo 40 candidatos, 8 fontes, 2 por documento e 12 KB de conteúdo; padrões 6 fontes e 10 KB. Não é busca semântica.

`Rag/Answer` aplica autorização e quotas 6/min por usuário e 30/min por tenant, antes do envio. Zero resultados não chama o provider. Índice incompleto bloqueia geração. Usa binding `knowledge_assistance` habilitado e consentimento `allow_external_context`, Prompt publicado do mesmo purpose e provider/model existentes. A pergunta é `evidence` e os trechos são `knowledge` na gramática existente do PromptRenderer; o transcript inteiro não é enviado.

System boundary fixo declara documentos/pergunta/templates como dados não confiáveis e proíbe execução. Prompt administrativo fica no payload de usuário. JSON de saída aceita somente `answer` e lista de UUIDs `sources`; referências ausentes, fora da seleção, revogadas durante a chamada, tool calls e output inválido rejeitam a resposta. Fontes retornadas são montadas pelo backend, com revalidação após a chamada, e não aceitam título/URL fornecido pelo modelo.

ConversationMessage mantém referências mínimas de origem (UUIDs, versão, posição, offsets), IDs do binding/provider/model/Prompt version e status RAG. Não duplica payload integral dos documentos no metadata. Fontes são reautorizadas na leitura; ao substituir ou retirar a versão, o link anterior fica indisponível com aviso, sem expor o trecho retirado. Histórico da resposta é preservado conforme retenção/privacidade já existentes.

## Interface e administração

Knowledge → **Busca e indexação**: estado por documento, contagens, última atualização, reindexação e busca de teste com ranking/trechos. Busca de teste via POST/CSRF, evitando pergunta na URL/log de acesso; sem LLM. Páginas com Cache-Control privado/no-store. Fontes em `/knowledge/sources/{UUID}` exigem read-knowledge e publicação atual.

Conversa exibe resposta assistida separada de diagnóstico, fontes abertas com documento/versão/seção/posição e link verificável. Estados no_match, index_incomplete e falhas LLM são distintos. Viewer pode ler Knowledge publicada e fontes, mas não administrar índice nem enviar conversa, conforme permissões existentes.

## Auditoria, falhas e limites

Eventos `rag.reindexed`, `rag.searched`, `rag.answered` têm allowlist e metadata fechada. Resposta registra status, duração monotônica em ms, contagem de fontes e UUIDs de configuração. Sem pergunta, texto recuperado, headers, URL de usuário, API key ou exceção livre. Busca e reindexação não executam sondagens.

Estados controlados: no_match, insufficient_evidence, index_incomplete, not_configured, source_revoked, rate_limited, timeout, invalid_json, invalid_response, invalid_output, invalid_sources, input_too_large, secret_unavailable, unauthorized e provider_unavailable. Falha LLM não modifica índice/Knowledge. Erro de autorização continua 403/404 nativo; CSRF 419.

Limitações: lexical, sem embeddings/OCR/PDF; trechos de tamanho fixo podem interromper parágrafo; recorte considera no máximo dois trechos por documento. Referência válida comprova proveniência, não entailment semântico: o LLM ainda pode interpretar mal ou produzir afirmações incorretas, portanto a UI oferece os trechos para conferência. Não existe garantia absoluta de imunidade semântica a injection; as barreiras determinísticas impedem tools, execução e alterações operacionais. Sem contabilidade monetária nova: budgets de entrada/saída e chamadas existentes limitam consumo. Grandes bases podem exigir jobs dedicados no futuro; V1 é síncrona, incremental e limitada ao texto existente.

## Publicação futura e rollback (não executados)

1. Após autorização específica, backup de PostgreSQL/storage e registro de imagem, catálogo de releases e readiness existentes. Não usar credenciais do laboratório nem fixtures de provider.
2. Comparar o candidato com a imagem ativa e preparar somente o delta RAG aprovado. O HEAD contém código preexistente de Observability ainda não publicado: não promover o checkout inteiro sem essa revisão nem incluir Observability na imagem. Construir a imagem escopada pelo processo existente e executar migrations com migrator; atualizar grants pelo procedimento existente. Sem expor banco/FPM/worker ou alterar RLS.
3. Publicar apenas Central aprovada e assets; nenhuma release de Agent, operação Lifecycle ou Observability.
4. No tenant autorizado, reindexar Knowledge existente pela UI e verificar contagens/consulta sem LLM. Configurar Prompt publicado purpose knowledge_assistance, binding e consentimento explícito de contexto externo. Habilitar Conversation apenas no tenant escolhido conforme rollout existente.
5. Smoke com documento autorizado, fontes, pergunta vazia e isolamento; monitorar eventos rag.answered. Provider real exige allowlist e secrets já administrados pelo fluxo existente.
6. Rollback: restaurar imagem anterior da Central e configuração anterior do binding/rollout, preservando tabelas RAG derivadas e todo Knowledge/histórico. Não usar down destrutivo nem apagar dados para reverter a UI. O código anterior ignora o índice. Ao reaplicar RAG, reindexar publicações ocorridas enquanto o código antigo estava ativo.

Quarentena da release 0.6.3, Agent operacional, Lifecycle, Observability, R2 e equipamentos permanecem fora da implantação.

## Reproduzir o laboratório

`scripts/rag-lab.sh` gera credenciais exclusivamente descartáveis fora do Git, PostgreSQL independente, duas contas de teste, Knowledge/Prompt/binding e Central em loopback. `tests/Fixtures/rag-lab-router.php` e `rag-lab-seed.php` têm guard explícito RAG_LAB_FIXTURE=1; apenas substituem a fronteira externa de DNS/provider por fixture. O script recusa nomes/diretórios já existentes e não lê produção. As etapas equivalentes foram executadas no laboratório desta entrega; o guard de colisão e a sintaxe do runner foram verificados. Em uma nova máquina, disponibilizar as imagens existentes antes de executar. Não executar o runner no host com laboratório de mesmo nome já ativo.
