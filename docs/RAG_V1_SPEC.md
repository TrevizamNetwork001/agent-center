# RAG V1 — contrato da missão

Implementação autorizada apenas no workspace e laboratório; publicação em produção depende de autorização específica. Reutilizar Laravel Central, PostgreSQL, Knowledge versionada, Prompts, Providers/Models/Bindings, Conversation, autenticação nativa, RBAC e auditoria. Não criar outro backend, Knowledge ou Conversation Engine.

## Contratos encontrados e reutilizados

KnowledgeItem / knowledge_items contém tenant, título, escopo e current_version_id. KnowledgeVersion é a view security_invoker knowledge_versions sobre payloads imutáveis knowledge_version_contents e version_events. Versioning publica, substitui, faz rollback por nova versão e arquiva sob row lock. read-knowledge permite leitura publicada; manage-intelligence restringe administração ao administrador do contexto. Platform admin precisa selecionar tenant. Os escopos de Knowledge não são ACLs por documento: todos os itens publicados do tenant são legíveis conforme a autorização existente.

Prompt e PromptVersion usam as mesmas regras de versão. LlmBinding já admite purpose knowledge_assistance, provider_id, model_id, prompt_id e config.allow_external_context. Client mantém SecretVault, NetworkPolicy, DNS pin, TLS, timeout, limites de contexto/saída e retries existentes. Nenhum novo cadastro de provider. PromptRenderer mantém variáveis existentes: evidence carrega a pergunta, knowledge carrega os trechos; classificação NOT_APPLICABLE não altera diagnósticos.

Conversation Engine mantém identidade, estados, ownership, cancelamento, histórico append-only, idempotência, locks e Task Engine determinístico. Interface existente é Blade com assets Vite/React do dashboard; as páginas novas usam o layout Blade atual sem redesenho.

## Funcionalidade e integridade

Indexação incremental síncrona PostgreSQL apenas de texto já suportado. Fragmentos com tenant, documento, versão, título/seção, posição, offsets Unicode, texto e ID estável enquanto não muda a versão/algoritmo. Índice atualizado atomicamente com publicação/arquivamento; reindexação administrativa dos itens existentes. Sem OCR, PDFs, embeddings obrigatórios ou banco vetorial.

FTS português e simple para termos técnicos, ranking ponderado e GIN. Busca autorizada exclusivamente no tenant, somente current_version publicada, máximo 8 fragmentos, 2 por documento, 12 KB; padrão 6 / 10 KB. Indexação incompleta bloqueia resposta LLM. Busca vazia não chama provider.

Resposta contextualizada com IDs de fontes retornados pelo modelo validados como subconjunto não vazio dos trechos enviados e novamente autorizados após a chamada. Referências inventadas rejeitam a resposta inteira. Fontes mostram documento real, versão, seção, posição e trecho navegável; fonte retirada fica indisponível. Ausência de evidência retorna exatamente: “Não encontrei informações suficientes na Knowledge disponível para responder com segurança a essa pergunta.”

Binding knowledge_assistance explicitamente habilitado com autorização de contexto externo e Prompt publicado. Reutilizar limites existentes de custo (budget por contexto e saída) e chamadas (6/min usuário, 30/min tenant); não há contabilidade monetária existente. Falhas do provider, timeout, autenticação, output inválido, rate limit, fontes revogadas e configuração ausente são distintos de ausência de informação.

Documentos, templates e perguntas são dados não confiáveis. System boundary fixo, JSON fechado, sem tools/function calling, sem execução de Skills, comandos, sondagens ou alterações de classificações/políticas. Conteúdo escapado na interface. Auditoria apenas metadados fechados e métricas, sem perguntas, documentos, credenciais ou mensagens de exceção.

## Conversa e interface

Perguntas de procedimento (ex.: “Como devo investigar um site lento?” e “Qual é nosso procedimento…?”) ou consultas explícitas à Knowledge usam RAG sem transição operacional. Pedidos de execução continuam no fluxo determinístico existente. Integração modular em App/Rag; sem Telegram/WhatsApp. Administração mostra índice, contagens, atualização, estado por documento, reindexação e consulta de teste sem LLM. Histórico exibe fontes e distingue falta de dados de erro LLM. Desktop/mobile e claro/escuro preservados.

## Segurança, testes e aceite

FORCE RLS nas novas tabelas e FKs compostas tenant/documento/versão, além de Gates e filtros. CSRF, autenticação, SSRF, criptografia e isolamento existentes preservados. Dados e cache sem compartilhamento entre tenants. Testes PostgreSQL sob role runtime sem bypass e E2E com dois tenants devem cobrir publicação, ranking, proveniência, citações inventadas, revogação, versões, reindexação idempotente, acesso cruzado, autorização, erros provider/timeout, base vazia, injection, contexto grande, histórico, diagnósticos e auditoria. Executar regressões Laravel, Pint, build, interface, secret scan e diff check. Python fora do escopo, pois não modificado.

Demonstração isolada com documento técnico de teste NOC, pergunta sobre site lento e pergunta sem correspondência; registrar interface, fontes reais, isolamento e zero tarefas. Entregar implementação, validação, screenshots, commits auditáveis, limitações e plano de publicação/rollback que preserve dados. Nenhuma mudança no Agent, Lifecycle, Observability, systemd da VM, quarentena 0.6.3, equipamentos, R2 ou produção. Sem updater, tcpdump ou Nmap. PASS somente com todos os critérios comprovados; caso contrário relatar impedimento concreto e parar.
