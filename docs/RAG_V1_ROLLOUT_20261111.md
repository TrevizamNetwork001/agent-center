# RAG V1 — rollout e rollback restritos

Autorização operacional de publicação RAG e cadastro administrativo registrada na conversa com o operador. IDs fornecidos pelo operador para agent-center-rag: organização org-C2uAqILvlucSffzzVtuwCjuO; projeto proj_YZEtULKViFZmB80cyF1IuBiY; Tracking/Key ID key_X7ACjcF0283AK8sR. Declaração administrativa vinculada ao Provider b6cf9cf8-ff17-49ed-b1a4-6fb757158ed8, tenant 1, evento llm.secret_updated #317. Não houve comparação criptográfica do secret com IDs administrativos.

Backup pré-deploy local: geração 20261111T164314Z, integridade verificada e restauração em banco separado PASS. Arquivos confidenciais restritos em backups/, fora do Git. O primeiro processo de backup encontrou permissão de leitura negada; a execução autorizada com permissões do backup concluiu sem alterar permissões dos secrets nem acessar R2.

Imagem anterior exata: agent-center-central:agent064-web-audit-20261110, sha256:7d00bfbe1a852f6b2823fec4ae2adcd8ced8fadf51f8973f805b2aa169df4dc3. Hash Compose do serviço em execução coincide com o arquivo operacional antes do rollout: 84fd759266a2d8325fd65f7391970cd88749e2c23cf66abe04b4383dbcf884a9.

Candidato final: agent-center-central:rag-v1-final-20261111, construído offline sobre o candidato RAG aprovado, que herda a imagem operacional. Acréscimos limitados a registro administrativo e request ID sanitizado. Nenhum arquivo Agent, Lifecycle, Observability ou assets de outras frentes promovido. Somente web será recriado, com --no-deps e --no-build. Workers e nginx não serão recriados.

Migration única aprovada: database/migrations/2026_11_07_210000_create_rag_index.php. Aplicar pelo migrator com --path explícito; conceder runtime SELECT/INSERT/UPDATE/DELETE somente em rag_documents e rag_chunks. Nenhum down em produção.

Rollback da aplicação:

    docker compose --env-file .env -f deploy/compose.yaml -f deploy/rag-v1-rollback.yaml up -d --no-deps --no-build web

Após rollback: verificar readiness, HTTPS/login e Operations Health; preservar Knowledge, histórico, identidade administrativa e auditoria, e todas as tabelas RAG. Não restaurar banco completo sobre produção para reverter apenas interface/código. Antes de um novo rollout, reconciliar novamente a imagem/base e o índice. O rollback não apaga declarações nem respostas. Se Conversation tiver sido habilitada nesta publicação e exigir desativação, aplicar o estado anterior (sem rollout habilitado no tenant 1) pelo controlador nativo, com evento de auditoria; não apagar histórico. Não modificar outros tenants.

Teste real limitado a uma requisição em homologação isolada agent_center_lab: Knowledge sintética recuperada, Conversation Engine, OpenAI HTTP 200, fontes revalidadas, no_match, revogação, isolamento RLS e ausência de tasks PASS. 521 tokens entrada, 75 saída, 596 total; 9846 ms; request ID req_c0a5c83291e84247b59bb81cab221dcf. Duas preparações anteriores foram bloqueadas antes de requisição externa por configuração de allowlist/rede do container temporário. Foram alinhadas às duas redes e à allowlist já existentes na Central; políticas operacionais não foram alteradas. A evidência identifica attempts=0/network_calls=0 nesses prechecks. Não houve retry pago.

A validação depois do rollout não repetirá inferência paga. Distinguir evidência de inferência real em homologação de smoke local de produção. Nunca apresentar fixture/mocks como OpenAI real.

Estado: rollout concluído; resultado final em docs/RAG_V1_PRODUCTION_PASS_20261111.md. Imagem ativa sha256:fcfa83717242a742a5c51ae36999166c055e47aa0144f2aef18dc6874d98efda.
