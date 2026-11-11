# AGENT CENTER RAG V1 PRODUCTION PASS

RAG V1 publicado na Central Laravel em 11/11/2026, no escopo operacional autorizado. Nenhuma pendência bloqueante de rollout identificada nas verificações desta execução.

## Identidade administrativa

| Campo | Registro |
|---|---|
| Organização confirmada pelo operador | org-C2uAqILvlucSffzzVtuwCjuO |
| Default project | proj_YZEtULKViFZmB80cyF1IuBiY |
| Chave agent-center-rag — Key/Tracking ID | key_X7ACjcF0283AK8sR |
| Provider existente, tenant 1 | b6cf9cf8-ff17-49ed-b1a4-6fb757158ed8 |
| Cadastro do secret | llm.secret_updated #317, 11/11/2026 17:26:34 CET |
| Vínculo administrativo | llm.identity_registered #318, 11/11/2026 17:45:50 CET, ator 1 |
| Tipo da comprovação | operator_attestation |

O operador forneceu os IDs no contexto do cadastro da nova chave pelo formulário HTTPS. O mecanismo cb0da04 registra essa declaração contra o último evento da credencial, com Gate/RBAC, tenant/RLS e auditoria append-only. Não houve comparação criptográfica do secret com IDs administrativos. A organização já estava confirmada; /v1/me e as três chamadas antigas não foram reinvestigadas. Não se afirma validação independente do saldo ou da baixa do grant no Billing.

A primeira execução do helper recusou o ator sem gravar: o escopo de tenant ocultava a própria linha do administrador de plataforma. Corrigida somente a consulta inicial da própria identidade sob RLS, antes de selecionar o tenant; nenhum bypass ou privilégio novo. Teste específico de administrador de plataforma acrescentado. Registro executado em processo CLI isolado da imagem candidata, com o backend e permissões runtime existentes, antes da promoção web.

## Git e imagem publicada

- Implementação RAG integrada: 81d1931; origem homologada 4637daf/61a9f44/c562122.
- Registro administrativo mínimo: cb0da04.
- Fechamento do código e referência de deploy: d1be577.
- Imagem ativa: agent-center-central:rag-v1-final-20261111.
- ID: sha256:fcfa83717242a742a5c51ae36999166c055e47aa0144f2aef18dc6874d98efda.
- Estado do container web: healthy.
- Base operacional preservada: agent-center-central:agent064-web-audit-20261110, sha256:7d00bfbe1a852f6b2823fec4ae2adcd8ced8fadf51f8973f805b2aa169df4dc3.

Imagem construída offline sobre o candidato RAG previamente escopado à base ativa. Somente os caminhos RAG/adaptador/registro administrativo foram acrescentados. O checkout inteiro, que contém trabalho local de outras frentes, não foi usado como imagem. Workers, nginx e Agent não foram recriados. O delta Git de Compose registra apenas a imagem web; alterações locais anteriores permanecem preservadas.

Contexto incremental sem secrets: artifacts/rag-v1-20261111/final-build-context.tar.gz, SHA-256 be0a3da13d2b30a5f24d02052eae96d6284cb73658b175dc01e5afdc5f23d84d. Contexto RAG original: artifacts/rag-v1-20261111/build-context.tar.gz. Os arquivos são artefatos locais, não uma publicação em registry externo.

## Backup, migration e rollback

Backup local geração 20261111T164314Z com integridade verificada. Restore completo em banco separado: PASS, incluindo triggers imutáveis, views security_invoker e RLS sem contexto. A execução inicial sem permissão para copiar a chave mestre foi interrompida; o mesmo backup autorizado foi concluído com permissão adequada, sem mudar permissões dos secrets ou acessar R2.

Migration única aplicada em produção: 2026_11_07_210000_create_rag_index, via Laravel com --path explícito. FORCE RLS confirmado em rag_documents e rag_chunks; runtime sem superuser e sem BYPASSRLS. Grants novos limitados a SELECT/INSERT/UPDATE/DELETE nessas duas tabelas. Nenhum down operacional.

Rollback pronto em deploy/rag-v1-rollback.yaml e docs/RAG_V1_ROLLOUT_20261111.md: restaurar somente a imagem web anterior e, se necessário, desabilitar Conversation no tenant 1 pelo fluxo nativo auditado. Preservar Knowledge, versões, índice derivado, identidade e histórico/auditoria. Backup confidencial permanece fora do Git. Nenhum rollback foi necessário nesta publicação.

## OpenAI real e RAG end-to-end

Uma única requisição real desta rodada, no ambiente separado agent_center_lab, usando o Provider/model/binding e credencial criptografada provenientes do backup posterior ao evento #317. O adaptador existente descriptografou a credencial somente em memória. Contexto enviado restrito à Knowledge sintética; não houve exportação de secret.

- Fluxo: publicação de documento → índice PostgreSQL → recuperação → Conversation Engine → Client OpenAI → resposta → revalidação da fonte.
- Modelo: gpt-6.1-sol, sem troca automática e sem retry.
- HTTP 200, 9846 ms, attempts=1.
- Tokens: entrada 521, saída 75, total 596.
- Request ID: req_c0a5c83291e84247b59bb81cab221dcf.
- Documento sintético: 94d06e89-8e84-4b2a-883d-cd4df5fb0d81.
- Versão: 4a9f437e-726a-4ee7-a827-566e63b2bb5b.
- Fonte recuperada e citada: 12fa62de-7636-4b51-af88-211f84b8031f.
- Resposta confirmou LAB-RAG-71 e não alegou executar ações.
- Revalidação de fonte, no_match sem provider, revogação, tenant distinto/RLS e ausência de novas tasks: PASS.

Duas preparações de container foram bloqueadas antes do envio por ausência da allowlist e da rede de egress existentes na Central. Ambas registram attempts=0/network_calls=0. Alinhadas à configuração já autorizada, sem alterar NetworkPolicy ou redes da produção. O teste seguinte fez exatamente uma chamada real. Sem repetição de teste pago ou custo monetário estimado.

## Produção e interface

HTTPS de login e readiness: HTTP 200, TLS verificado. Prechecks de produção antes e depois: PostgreSQL/TLS, fila, worker diagnóstico e assets PASS. Operations Health VERDE, também observado pelo painel.

Smoke de produção sem nova inferência paga: publicação/indexação/consulta de documento sintético RAGPROD71, fonte navegável, Conversation com ausência de conhecimento, contexto errado sem fonte e RLS sem visibilidade cruzada. Depois o documento sintético foi arquivado e a fonte retornou HTTP 404; a conversa foi encerrada, preservando histórico. Base inicial tinha zero Knowledge; o smoke não acrescentou conhecimento operacional real. Conversation foi habilitada somente no tenant 1, com retention_days=30 pelo controlador nativo e auditoria.

Navegador real em HTTPS: desktop 1440 e mobile 390, claro/escuro; pesquisa, documento/fonte e Conversation PASS; sem erros JavaScript ou overflow global. POST de pesquisa passou com CSRF nativo; acesso sem sessão redirecionou ao login. O primeiro runner externo recebeu 403 da restrição de origem; o teste foi feito pela origem local já autorizada na VPS, com o mesmo domínio/TLS e sem alterar ACLs.

Autenticação dos screenshots: sessão temporária nativa Laravel criada pelo smoke administrativo autorizado, com guard/session/encriptação/middlewares/Gates preservados. Não foi exercitado novamente o login de produção por senha. A sessão e seu arquivo privado foram removidos após a verificação; nenhum token/cookie foi registrado no Git. O formulário de login HTTPS e a autenticação/autorização possuem também cobertura de regressão. Não foi instalado endpoint de bypass.

Screenshots: docs/evidence/rag-v1-publication-20261111/browser/ (13 imagens). Inspeção visual desktop claro, mobile escuro e Operations Health concluída. Evidência real OpenAI em real-rag.json; screenshots de produção mostram o smoke local e não são apresentados como uma segunda inferência paga.

Tasks antes/depois: 97/97; quarentenas: 2/2. Agent observado antes/depois com a mesma versão 0.6.4 já preexistente e o mesmo estado; não foi atualizado por esta execução. Nenhuma operação de equipamento ou Skill executada. Agent, Lifecycle, Observability, Hermes, release 0.6.3 e R2 não foram modificados.

## Testes e limites das evidências

Suíte Laravel/PostgreSQL: 310 testes, 2286 assertions, PASS. Depois da correção específica do helper para o ator de plataforma: suíte focada de identidade/adaptador, 15 testes, 99 assertions, PASS. Migrations up/down e remoção do banco de teste PASS. Pint PASS; git diff --check PASS; secret scan básico do índice PASS. Os testes de fontes inventadas usam transporte determinístico; não exigem induzir uma resposta inventada em uma chamada paga. Teste real não é apresentado como prova universal de qualidade semântica.

O banco de homologação isolado agent_center_lab permanece sem serviço HTTP publicado, preservado para revisão. Backups locais e evidências estão preservados. Não houve upload ou mudança R2. Não há bloqueio operacional pendente identificado; contabilização monetária independente na OpenAI e novo login de produção por senha não foram atestados.

AGENT CENTER RAG V1 PRODUCTION PASS
RAG V1 ENCERRADO
