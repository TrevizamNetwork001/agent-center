# RAG V1 — verificação complementar de 11/11/2026

AGENT CENTER RAG V1 BLOCKED: não foi localizada autorização operacional específica para promover a imagem candidata e aplicar a migration na Central. A organização beneficiada pelos créditos também não pôde ser comprovada pelo cadastro existente: não há metadados de organização no provider. HTTP 200 confirma inferência, não o vínculo contábil dos créditos.

## Chamada desta execução

Uma requisição real, sem retry, pelo Client Laravel candidato já existente, carregado em processo CLI isolado na Central. Binding knowledge_assistance existente, tenant 1, provider habilitado, modelo exato gpt-6.1-sol, Prompt publicado e consentimento externo conferidos. Nenhum cadastro criado ou alterado. Credencial descriptografada somente em memória pelo SecretVault. As duas classes PHP temporárias foram removidas depois da execução; aplicação ativa não substituída.

Resultado em `docs/evidence/rag-v1-resumption-20261111/openai-probe.json`: HTTP 200; 9313 ms; entrada 336 tokens; saída 44; total 380; attempts=1; resposta correta LAB-RAG-71 e UUID sintético esperado. Nenhum erro. Custo monetário não calculado. O saldo US$ 62 e vencimento 30/11/2026 são informações do operador, sem confirmação contábil independente nesta execução. Não houve consulta adicional de billing nem nova chave.

O trecho e UUID foram fornecidos em memória. Este teste comprova adaptador/contrato de resposta real, não recuperação E2E da Knowledge operacional. Os testes de recuperação, Conversation, fontes inventadas, revogação e isolamento usam PostgreSQL e transporte determinístico, conforme evidências do candidato. Nenhuma chamada paga adicional foi executada nesta verificação complementar. Há registro separado de outras chamadas em RAG_V1_FINAL_20261111.md; não são contabilizadas como pertencentes a esta execução.

## Candidato e validações disponíveis

Origem isolada confirmada pelo Git: 4637daf (implementação), 61a9f44 (evidências), a2b1713 (gate histórico), c562122 (adaptador Responses). Checkout operacional tem delta RAG preparado junto de alterações locais de outras frentes, que foram preservadas.

Imagem candidata local existente: agent-center-central:rag-v1-candidate-20261111, sha256:0ae5e0bf183aab63f43042754636d51965eba84d6200d4b7a92fdf5ceba1036f. Contexto: artifacts/rag-v1-20261111/build-context.tar.gz. Camada de 19 caminhos sobre a imagem ativa, sem publicar o checkout inteiro.

Migration: app/database/migrations/2026_11_07_210000_create_rag_index.php. Nenhuma migration/grant aplicada em produção. Rollback previsto em RAG_V1_IMPLEMENTATION.md: restaurar imagem/configuração anterior e preservar Knowledge, versões, histórico e tabelas RAG; não executar down destrutivo em produção.

Log focado existente nesta rodada: 66 testes e 388 assertions, PASS. Browser existente: desktop/mobile, claro/escuro, fontes, ausência de conhecimento, isolamento 404 e CSRF 419, PASS, com transporte determinístico. Reexecução completa encerrada: 304 testes, 2264 assertions, PASS, 118,059 segundos; log preservado em docs/evidence/rag-v1-resumption-20261111/laravel-tests.log. Não usar o primeiro log com falha de AuditTimezoneTest como PASS.

Snapshot somente leitura da Central: Operations Health VERDE, PostgreSQL/TLS, workers e probes independentes VERDE. Evidência em docs/evidence/rag-v1-resumption-20261111/health.json. A razão authenticated_request_completed é o rótulo interno do serviço: a coleta foi CLI e não comprova login HTTP novo. O estado de backup/R2 é leitura do monitor existente; nenhuma operação R2 foi disparada.

Não houve deploy, restart, migration operacional, smoke RAG publicado, mudança em Agent/Lifecycle/Observability/quarentena 0.6.3, R2 ou equipamentos. Para publicação continuam necessários autorização específica, confirmação segura da organização, backup/restauração verificados no momento do rollout e smoke após promoção. Não se emite PRODUCTION PASS.
