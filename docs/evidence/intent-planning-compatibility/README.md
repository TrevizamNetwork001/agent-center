# DIAGNOSTIC INTENT PLANNING COMPATIBILITY PASS

Implementação: `8c6e4c8` e `a66a8b6`.
Branch: `lab/diagnostic-intent-planning-compatibility`.

| Verificação | Resultado |
| --- | --- |
| Laravel/PostgreSQL completo | 495 testes, 3.775 asserções; zero falhas/erros/skips |
| Casos específicos de HTTP/TLS e planejamento | 23 testes, 292 asserções |
| Task Engine concorrente | PASS: claims, corrida worker/result, fencing, idempotência e DNS |
| Conversas concorrentes | PASS: encerramento único e mensagem versus encerramento |
| PostgreSQL/FORCE RLS, RBAC, CSRF, Telegram e RAG/OpenAI | PASS na suíte completa |
| Pint | 17 arquivos, PASS |
| Frontend Vite | PASS |
| Imagens incrementais e hashes de código | PASS nas duas bases operacionais |
| API nativa nas imagens, sem Observability | Capabilities e heartbeat autenticados: HTTP 200 |
| Rota 8.8.8.8 nas imagens | MTR_ICMP apenas, autorização UNKNOWN, zero tarefas/sondas |
| Transporte TLS na imagem, sem rede externa | Certificado confiável aceito, não confiável rejeitado em TLS, porta fechada recusada em TCP |
| Repetição da prova de transporte | Oito execuções consecutivas PASS |
| Navegador | 30 verificações de página/largura/tema, sem erros JS/overflow; outro tenant recebe 404 |
| Secret scan do índice e diff check | PASS |
| Publicação / sondagens reais | Nenhuma |

## Evidências

- [Resumo estruturado](validation.json)
- [JUnit completo](final-junit.xml)
- [Manifesto de imagens e hashes dos arquivos](image-manifest.json)
- [Contratos nativos da Web](compatibility-web.json)
- [Contratos nativos do diagnostic-worker](compatibility-diagnostic.json)
- [Prova da corrida na fixture antiga](http-race-proof.json)
- [Detalhes TCP/TLS da imagem candidata](tls-image.json)
- [Oito repetições de transporte](tls-repeat.log)
- [QA do navegador](browser.json)
- [Escopo de rota e helper indisponível — fixture sintética](blocked-390-dark.png)
- [Classificação técnica versus cobertura — fixture histórica sintética](mismatch-1440-light.png)

Os testes específicos repetem parte da suíte completa; suas contagens não são
somadas como testes distintos. As capturas de interface usam fixtures sintéticas,
incluindo um adaptador fake de permissão para cobrir respostas conhecidas de helper
e execução. Já as provas `compatibility-web.json` e `compatibility-diagnostic.json`
usam o adaptador real, sem fake de autorização: a API atual não comprova permissão
por destino e a execução permanece bloqueada. Não há Observability nessas imagens.

O secret scan utilizado é o scanner básico existente do índice Git, sem expor
valores encontrados; não representa auditoria especializada de todo segredo possível.

## Rebuild de referência

Os [Dockerfiles Web](Dockerfile.web) e [worker](Dockerfile.diagnostic) recebem o
repositório como contexto, copiam somente os arquivos listados e preservam suas
bases diferentes. Eles produzem imagens equivalentes por conteúdo; os digests do
manifesto identificam especificamente os builds efetivamente verificados, criados
a partir de staging com os mesmos arquivos. Compare os IDs das bases e os hashes
antes de qualquer publicação futura.

Execute o build do frontend antes de `scripts/test.sh` no checkout novo. Para os
testes, use exclusivamente PostgreSQL de laboratório com os overrides
`TEST_PG_CONTAINER=rag-v1-postgres`, `TEST_NETWORK=rag-v1-lab`, envs privados de
laboratório e `TEST_CA_FILE=/root/rag-v1-lab/no-ca`. Os dois testes de concorrência
são habilitados por `TEST_DIAGNOSTIC_CONCURRENCY=1` e
`TEST_CONVERSATION_IDLE_CONCURRENCY=1`.

[Causa raiz, contrato, limitações e rollout/rollback](../../DIAGNOSTIC_INTENT_PLANNING_COMPATIBILITY.md).
