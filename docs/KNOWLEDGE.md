# Knowledge versionado

Todos os itens pertencem a um tenant, inclusive scope global: “global” cobre o contexto deste tenant, nunca outros tenants. Scopes: global, diagnostic, profile, operations, tenant; profile exige FK para Profile do mesmo tenant. Não há embeddings, pgvector ou pesquisa semântica.

knowledge_items guarda metadados, current_version_id e draft_version_id. Payloads ficam em knowledge_version_contents, append-only. knowledge_versions é uma view PostgreSQL security_invoker que projeta o payload e o status mais recente de version_events. Conteúdo e eventos nunca recebem UPDATE/DELETE: Models, grants e triggers impõem imutabilidade, inclusive em draft.

Editar draft cria um novo snapshot, com version_number crescente sob row lock no item; o draft anterior recebe evento SUPERSEDED. Publish mantém o mesmo UUID do snapshot, acrescenta evento PUBLISHED e troca o ponteiro do item; a versão anteriormente publicada recebe SUPERSEDED. O formulário envia expected_draft_id: edição concorrente obriga revisão antes de publicar.

Rollback de versão previamente publicada copia o conteúdo para NOVA versão e publica atomicamente, preservando os IDs e o histórico anteriores. Archive preserva dados e proveniência e retira o item da seleção. Item usa DRAFT/PUBLISHED/ARCHIVED; versões projetam DRAFT/PUBLISHED/SUPERSEDED/ARCHIVED. Uma nova versão em preparação não retira a publicação atual.

Admins do contexto gerenciam versões; operator/viewer leem apenas itens PUBLISHED. Histórico administrativo é paginado. Conteúdo é texto escapado, sem execução de HTML, Blade ou comandos. Não inclua credenciais.

Seleção V1 para diagnóstico: tenant atual; item PUBLISHED; scope global/diagnostic/tenant ou profile correspondente; tags vazias ou contendo diagnostic/Profile key. Ordem por key; máximo cinco itens e 12000 bytes de conteúdo limpo. operations fica fora deste fluxo. Versões usadas são persistidas como citations em interpretation_sources; item arquivado depois não apaga proveniência.

Auditoria: knowledge.created, version_created, published, superseded, rollback_created e archived. Metadata somente UUIDs; sem conteúdo. FORCE RLS no item, payload e eventos, FKs compostas e security_invoker nas views. Sem bypass de platform_admin: selecionar tenant antes.

RAG vetorial futuro poderá usar os mesmos IDs/versões e proveniência; esta fase não possui índice vetorial, tokenizer externo, scraping ou ingestão automática.
