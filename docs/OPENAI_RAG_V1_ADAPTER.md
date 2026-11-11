# OpenAI + RAG V1 — adaptação e gate de publicação

## Configuração real encontrada

Precheck somente leitura dentro do container Laravel operacional: tenant ID 1, ativo; zero Providers, Models, Prompts e Bindings; nenhuma configuração de Conversation. `api.openai.com` não consta na allowlist LLM. O arquivo de chave mestre existe, mas não há registro de provider cuja credencial possa ser validada/descriptografada. Não foram criadas credenciais nem alteradas allowlists, políticas, privilégios, banco ou aplicação operacional.

É necessário cadastrar através do fluxo existente: provider OpenAI habilitado com credencial criptografada; model habilitado com ID exato `gpt-6.1-sol` (sujeito à disponibilidade da conta); Prompt publicado com purpose `knowledge_assistance` e variáveis existentes `{{evidence}}`/`{{knowledge}}`; binding habilitado coerente no mesmo tenant, com consentimento explícito `allow_external_context`. A autorização de egress/allowlist para `api.openai.com` também precisa existir antes de qualquer chamada. A ausência não foi contornada pela adaptação.

## Documentação oficial consultada

- [GPT-6.1 Sol](https://developers.openai.com/api/docs/models/gpt-6.1-sol): lista Chat Completions e Responses; reasoning effort admite low/medium/high/xhigh/max. A documentação não comprova acesso da conta operacional.
- [Migração para Responses](https://developers.openai.com/api/docs/guides/migrate-to-responses): payload input, saída output e text.format.
- [Responses create](https://developers.openai.com/api/reference/cli/resources/responses/methods/create): max_output_tokens e store.

A consulta foi feita com a skill OpenAI Docs. Nenhum SDK novo ou cadastro paralelo foi adicionado.

## Correção mínima no adaptador existente

`Llm/Client` usa Responses somente para a base canônica `https://api.openai.com/v1` (trailing slash aceito). Outros providers continuam no Chat Completions existente. O NetworkPolicy original executa allowlist, resolução e validação de todos os IPs; apenas depois o Client seleciona `/v1/responses`, mantendo o endpoint validado, pin de DNS, TLS e transporte originais. Nenhuma alteração em NetworkPolicy, allowlists ou políticas do Agent.

Payload Responses: model existente, input com mensagens sanitizadas, stream=false, store=false, max_output_tokens limitado a 1024 (inclui reasoning) e text.format.type=json_object. Para IDs gpt-6.*, reasoning.effort=low. Sem temperature/top_p, tools, function calling, web search, code execution, histórico remoto ou previous_response_id. Somente texto JSON dentro do contrato já utilizado pelo aplicativo; o envelope externo Responses é normalizado para o parser diagnóstico ou RAG existente.

Parser exige status completed, ausência de error/incomplete_details, exatamente um output_text de mensagem assistant completed e sem annotations. Itens de reasoning não são expostos. Tool calls, recusa, saída truncada/incompleta, conteúdo inválido ou múltiplas respostas são rejeitados. RAG continua verificando os UUIDs como subconjunto das fontes recuperadas e revalidando publicação/tenant após a chamada.

Erros diferenciados: 400 invalid_payload; 401 unauthorized; 403 permission_denied; 404 model_or_endpoint_unavailable; 429 rate_limited; 5xx provider_unavailable. Não há propagação de body/headers externos em erros. Client retorna metadados seguros: status HTTP, duração monotônica, número de tentativas e contadores inteiros de tokens, se presentes. As categorias novas são preservadas no status/auditoria RAG.

OpenAI tem **uma única tentativa por invocação**, sem retry automático de 429/5xx, reduzindo o teto de custo e duração. Provider conserva timeout validado de 1–15 segundos, limites de entrada/saída, SSRF, cifra e redaction. Outros providers conservam a política preexistente de no máximo duas tentativas. Não há chamadas pagas na suíte automatizada.

## Integração restrita e diferenças com a imagem ativa

A base operacional continua em `a3b091b`, imagem `agent-center-central:access-maintenance-2a08663-20261107`, ID `fc4e7485...`. O código-fonte contém Observability ainda não publicada. A preparação offline extraiu somente código da imagem ativa, sem env/storage/secrets, e compôs uma camada de **19 caminhos de aplicação**, exclusivamente RAG/adaptador. Rotas e Audit foram reconciliados a partir da versão efetivamente ativa, acrescentando apenas os eventos/rotas/metadados RAG. Não há arquivos Agent/Lifecycle/Observability ou fixtures/artefatos de laboratório na camada.

Migration RAG permanece `2026_11_07_210000_create_rag_index`: duas tabelas derivadas, GIN, FKs compostas tenant/documento/versão, ENABLE/FORCE RLS. Não foi aplicada em produção. A suíte PostgreSQL usa o banco de teste descartável e role runtime sem bypass. Não há alteração adicional de schema.

## Testes e gate

`OpenAiResponsesTest` cobre payload, endpoint, modelo exato, limites, ausência de tools/sampling, metadados, fontes RAG, 400/401/403/404/429/503, teto de uma tentativa, refusal/incomplete/tool outputs, JSON inválido, timeout/cancelamento, SSRF e redaction. São doubles determinísticos de transporte, explicitamente não uma validação da API real.

Sem registros e allowlist autorizados, não foram realizadas autenticação real, consulta de disponibilidade do modelo, inferência, E2E real de RAG ou medição de tokens/custo real. Zero chamadas reais à OpenAI. Os cenários reais A/B/C/D e o deploy permanecem bloqueados. Não substituir esse resultado por mock.

Backup e rollback de publicação só devem ser executados quando o gate de configuração/chamada real permitir avançar. O plano de rollback preserva as tabelas derivadas/Knowledge/histórico, restaura a imagem anterior e não toca Agent. Nenhuma imagem candidata foi publicada, nenhuma aplicação reiniciada, nenhuma migração/grant operacional aplicado e nenhum dado sintético inserido em produção nesta rodada.
