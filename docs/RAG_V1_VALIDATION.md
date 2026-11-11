# RAG V1 — validação em laboratório

Ambiente descartável `rag-v1-lab`, PostgreSQL 17 em container próprio `rag-v1-postgres`; dados e contas exclusivos. Central PHP 8.4.27 pelo tooling existente, Laravel real, role runtime não-owner/NOBYPASSRLS. A interface fica apenas em loopback do host: `127.0.0.1:18765`. Sem token, sessão ou secret de produção. Fuso Europe/Berlin alinhado entre aplicação/PostgreSQL e sessão criptografada conforme configuração do projeto.

## Cenários e evidências

`app/tests/Feature/RagTest.php`: 24 testes de integração HTTP/serviços sobre PostgreSQL real, com dois tenants. External DNS/provider é a única fronteira substituída; o Client, parser, SecretVault e validações da aplicação são reais. Não se afirma inferência de LLM comercial ao vivo.

| Requisito | Cobertura |
|---|---|
| Publicação, fragmentos/offsets, fonte e seção | publication_indexes_original_offsets |
| Busca português, termos DNS/TCP/HTTP, ranking | portuguese_technical_search_and_ranking |
| Resposta/fontes/configuração/auditoria reais | real_client_validates_sources |
| Citação fabricada rejeitada | invented_source_rejects_entire_answer |
| Revogação exclui busca/link | archive_removes_search_and_source |
| Draft/versão antiga ignorados, nova versão muda índice | draft_is_ignored_and_new_publication |
| Reindexação idempotente | publication_indexes_original_offsets |
| Tenant B não lê A, RLS sem filtro da aplicação | tenant_isolation_uses_force_rls |
| Runtime sem superuser/bypass, FORCE RLS efetivo | tenant_isolation_uses_force_rls |
| Escrita cruzada bloqueada por FK composta e RLS | cross_tenant_composite_fk_and_rls_write_check |
| Usuário desabilitado/administração restrita | forbidden_user_and_admin_only_reindex |
| Timeout/provider/auth/JSON inválido | provider_timeout_invalid_and_auth_failures |
| Knowledge vazia/sem correspondência não chama provider | empty_and_no_match_do_not_call_provider |
| Injection em dados, sem tools/tarefas/classificação alterada | injection_is_untrusted_data_and_has_no_operational_effect |
| Fragmentos/contexto grandes limitados | large_text_obeys_chunk_and_context_limits |
| Índice ausente/parcial/algoritmo antigo, reparo | incomplete_index_blocks_llm; partial_and_stale_index |
| Falha de índice reverte publicação e preserva versão anterior | index_failure_rolls_back_publication |
| Fonte revogada durante chamada LLM | source_revoked_during_provider_call |
| Consentimento/binding ausente não envia dados | missing_binding_and_external_consent |
| Tool calls/output inválido rejeitados | invalid_model_output_and_tool_calls |
| Evidência insuficiente/context budget | insufficient_evidence_and_context_budget |
| Histórico HTTP/idempotência/ownership/fontes/zero tarefas | conversation_http_history_idempotency |
| UI/auditoria administrativa | admin_ui_and_reindex_audit |
| Quotas separadas por tenant | rate_limit_is_controlled_and_tenant_scoped |
| Intenção de procedimento versus execução/cancelamento | procedure_questions_keep_operational_requests_deterministic |

`tests/Support/rag-concurrency.php`: dois processos e conexões independentes no banco de demonstração, seis ciclos reais de draft/publicação em paralelo a seis reindexações, com 26 snapshots consistentes; zero mistura de versão/índice parcial visível e zero tarefas. Resultado: [concurrency.json](evidence/rag-v1/concurrency.json).

## Navegador e demonstração

Chromium/Playwright em imagem existente `agent-center-browser:recovery4`; login real, sessão Laravel, POST com CSRF, dois tenants. `tests/Browser/rag-v1.cjs` testa busca sem LLM, conversa, fontes navegáveis, pergunta sem correspondência, isolamento da conversa e fonte (404) e POST sem CSRF (419). Respostas externas usam fixture determinística de transporte explicitamente marcada, sem acesso externo ou execução de sondagens.

Documento técnico de teste publicado: **Procedimento NOC - Lentidão Web**, seção **Testes iniciais**. Pergunta: **Como devo investigar um site lento?** Resposta referencia o UUID real do fragmento recuperado e apresenta versão/seção/posição. Pergunta sem resposta: **Como devo investigar astronomia galáxia?** retorna a mensagem de insuficiência de Knowledge. Tenant B vê base vazia e não acessa documento/conversa/fonte de A.

Desktop 1440 e mobile 390, claro/escuro: busca, conversa e fonte; sem erro JavaScript nem overflow global. Inspeção visual das telas mobile efetuada. Capturas não contêm tokens, cookies ou API keys; apenas dados de teste.

- [Busca desktop](evidence/rag-v1/search-1440-light.png) · [Busca mobile](evidence/rag-v1/search-390-dark.png)
- [Conversa desktop](evidence/rag-v1/conversation-1440-light.png) · [Conversa mobile](evidence/rag-v1/conversation-390-dark.png)
- [Origem](evidence/rag-v1/source-1440-light.png) · [Sem correspondência](evidence/rag-v1/no-match.png)
- [Tenant B](evidence/rag-v1/tenant-b-empty.png) · [Resultado automatizado](evidence/rag-v1/browser-results.json)

## Execução e limites

Comando de regressão: `scripts/test.sh` com TEST_PG_CONTAINER/TEST_NETWORK/TEST_OWNER_ENV/TEST_RUNTIME_ENV apontando ao laboratório. Cria apenas agent_center_test, aplica migrations/grants, testa sob runtime, verifica down e remove o banco de teste. O banco separado da demonstração é rag_lab.

Resultado final: **292 testes Laravel, 2.154 assertions, PASS** (24 testes RAG incluídos), 127,407 segundos. Rollback completo das migrations no banco de teste e remoção desse banco: PASS. Build Vite: PASS. Pint: PASS. PHP lint: PASS. Secret scan do índice Git: PASS. Git diff --check: PASS. Navegador: PASS (14 screenshots). Concorrência: PASS. Python não executado porque nenhum código Python foi modificado. As primeiras falhas da suíte completa foram de ambiente (timezone PostgreSQL UTC versus PHP Europe/Berlin, SESSION_ENCRYPT ausente), corrigidas somente no laboratório. Nenhuma alteração de contrato operacional foi usada para ocultá-las.

Limitações reais e plano de publicação/rollback: [RAG_V1_IMPLEMENTATION.md](RAG_V1_IMPLEMENTATION.md). Sem embeddings, OCR, PDFs, novos canais, Agent, Lifecycle, Observability, R2, equipamentos ou produção. Sem alteração da quarentena 0.6.3. A fixture comprova contratos e integração, não qualidade semântica de um provider externo real. A verificação determinística garante origem/autorização dos IDs, não prova que cada frase gerada decorre semanticamente do trecho.

## Entrega final

**AGENT CENTER RAG V1 LAB PASS**
**PRODUCTION DEPLOY PENDING**

Código e testes: commit `4637daf` na branch local `rag-v1-lab`, no clone isolado `/root/agent-center-rag-v1`. Evidências e este registro estão no commit de validação subsequente. O checkout `/opt/agent-center` não recebeu alterações; seus arquivos preexistentes não rastreados foram preservados.

A migration nova foi aplicada e revertida no banco de teste; as duas tabelas permanecem somente no banco de demonstração do laboratório. O laboratório fica preservado em loopback, com dados/credenciais descartáveis fora do Git em diretório restrito. Não há provider externo real chamado por esse cenário. Credenciais e PKI de produção nunca foram copiadas. Nenhum deploy ou push realizado. Agent, Lifecycle, Observability e quarentena 0.6.3 não alterados.

Para publicação futura, é obrigatório revisar o delta em relação à imagem ativa: o HEAD também contém Observability preexistente ainda não publicada, que deve continuar fora de qualquer imagem promovida nesta missão. Plano de backup, grants, migração, ativação por tenant, smoke e rollback preservando dados no documento de implementação. Publicação exige autorização operacional específica.
