# RECOVERY-5 — validação

Base de7d25f. Laravel Central; Agent 0.5.2 e Task Engine preservados. AGENTS.md inalterado. Sem FastAPI, tools operacionais, embeddings, pgvector, semantic search, Conversation ou devices.

## Entrega

Uma migration aditiva cria dez tabelas FORCE RLS e duas views security_invoker. Models: KnowledgeItem/Version, Prompt/Version, LlmProvider, LlmModel, LlmBinding, DiagnosticInterpretation e InterpretationSource; ciclo de vida em version_events append-only. Payloads versionados, interpretações e fontes têm grants SELECT/INSERT e triggers imutáveis.

Knowledge: criar/editar draft via novos snapshots, publicar, supersede, rollback criando nova versão, archive e histórico. Busca determinística por tenant/scope/tags/status/Profile. Global nunca cruza tenant. Operator/viewer leem apenas publicações; admins do contexto gerenciam.

Prompts: versões, publicação com expected_draft_id, preview literal local, variáveis fechadas e schema fixo. Provider/model/binding e secret ficam em área avançada. Defaults simples preparam prompt/binding, com consentimento administrativo para contexto externo.

Cliente HTTPS: hostname allowlist de implantação, validação de todos os IPs DNS, pinning por cURL, verificação TLS, proxies/redirects desativados, limites/retry controlado, output validado e redaction. Nenhum tool/function call é enviado ou aceito. Interpretação opcional só escreve sua própria evidência/proveniência; classificação oficial e tasks não são alteradas. Sem configuração ou em falha, mantém fallback técnico.

## Testes e checks

| Verificação | Resultado |
|---|---|
| Laravel/PostgreSQL suite | 102 testes / 880 assertions PASS |
| Migrate / rollback no DB de teste | PASS, oito migrations |
| Knowledge draft/publish/supersede/rollback/archive | PASS |
| Publicação de draft concorrente/stale | 409, sem publicação indevida |
| Imutabilidade e histórico | Grants/Models/triggers + view lifecycle PASS |
| Prompt preview e variável inválida | PASS, sem rede |
| RBAC / scopes / RLS / views entre tenants | PASS |
| AES-GCM / contexto provider+tenant / chave errada / serialização | PASS |
| Rotação preflight e apply com key ring | PASS |
| SSRF: loopback, metadata, privados, mixed DNS, rebinding | PASS |
| HTTPS fake real: TLS válido/inválido, timeout, recusa | PASS |
| HTTP 401/429/500, redirect, JSON inválido, tamanho | PASS |
| Fallback / classificação inalterada / nenhuma task nova | PASS |
| Prompt injection / tools e classification em output rejeitados | PASS |
| Proveniência: Knowledge/prompt/provider/model | PASS |
| Pint | 137 arquivos PASS |
| Composer validate --strict / audit --locked | PASS / nenhum advisory |
| Frontend build | PASS em Node24 efêmero |
| Compose config | PASS |
| Web / queue worker / diagnostic-worker / PG / Nginx | Healthy |
| /health/live e /health/ready | 200 / 200 |
| Secret scan e git diff --check | PASS no índice Git; scanner básico |
| Agent 0.5.2 / AGENTS.md | Sem alteração |

## Restore e master key

Lab exclusivo agent_center_lab recebeu Knowledge publicado, prompt publicado, provider com secret aleatório criptografado, model, binding, conclusão e interpretação com proveniência. Cliente fake local produziu texto de fixture, sem rede. Dump integral foi restaurado em agent_center_restore_check.

Restaurado: 23 tabelas FORCE RLS, duas views security_invoker, triggers e grants. Role de runtime não é owner e não vê dados sem contexto. A prova usou uma cópia da master key recuperada de backups/secrets e confirmou SHA-256 do secret descriptografado contra manifest privado, sem emitir valor. Dados publicados, model/binding e proveniência também foram verificados. Teste com chave errada é coberto na suíte.

A chave está fora do Git/context Docker, modo 0600, acessível somente por bind readonly ao web. Backup copia chave primária e key ring completo separadamente de configs/Git. Ver LLM.md para rotação e recuperação. Nenhum provider ou secret real foi cadastrado na produção.

## Operação

Imagem recovery5; rollback recovery5-rollback-de7d25f / deploy/rollback-recovery5.yaml. Schema e master key devem permanecer no rollback; nunca destruir versões ou restaurar estado antigo por cima de dados novos. PostgreSQL e serviços não relacionados não foram reiniciados. Chamada LLM não participa da conclusão do diagnóstico.

LLM_ALLOWED_HOSTS vazio por padrão; não há egress LLM até configuração autorizada. Não houve teste com conta de provider externo ou consumo real. A implementação do protocolo foi conferida com OpenAI Docs; domínio permanece genérico.

## Riscos e limites

Texto assistido pode ser semanticamente errado apesar do schema; permanece separado da classificação oficial. Redaction por padrões não garante eliminação de todo segredo em texto livre; Knowledge publicado deve ser revisado. O administrador autoriza o envio de contexto e deve avaliar a política do provider.

Timeout HTTP é limitado; DNS também depende do resolver/OS. Cancelamento local não garante interrupção/cobrança remota. Protocolos além de Chat Completions-compatible precisam de adaptador revisado. Budget conservador é em bytes, sem tokenizer ou RAG.

Model habilitado/desabilitado é definido na criação; provider/binding podem ser desabilitados para suspender uso. Seletores da UI administrativa são básicos. Source text, previews e saídas são escapados, sem editor executável.

Sem PKI/domínio final ou destino autenticado de backup externo. Chave perdida inviabiliza recuperação dos secrets; a cópia externa do key ring é responsabilidade operacional ainda não configurada. APP_KEY não substitui a master key dedicada. O template frontend continua sem package-lock versionado; nenhum pacote de produção foi adicionado.

## Resultado

RECOVERY-5 PASS. Lab temporário removido após restore. Serviços saudáveis após implantação; Agent e AGENTS.md preservados. Commit de entrega contém implementação, testes e evidências, sem credenciais.
