# Confirmação, saudação e leitura das métricas — 18/11/2026

Correção da Central no escopo autorizado da conversa de suporte do Telegram.

- A proposta permanece confirmável por até 300 segundos; a prova operacional continua curta e precisa estar válida antes da criação da tarefa.
- Uma confirmação recebida após expirar a prova solicita nova verificação do mesmo escopo. Só a resposta autenticada e autorizada permite criar o diagnóstico. Política, Agent, destino, protocolo e digest do plano permanecem vinculados à proposta.
- Cancelamento, mudança de contexto, expiração, política alterada e resposta negativa impedem o início. Confirmações e callbacks repetidos não duplicam tarefas. Permanecem RBAC, isolamento por tenant e validações determinísticas.
- “Isso pode fazer” é reconhecido como confirmação. A saudação inicial é respondida também quando a mensagem contém pedido técnico.
- A apresentação da latência usa vírgula e arredondamento para uma casa decimal: 3.115 ms passa a 3,1 ms. Evidência original preservada. No resultado ICMP do Telegram, latência, perda e respostas ficam em linhas separadas; a duração total da sondagem permanece nos detalhes técnicos.

Publicação restrita às classes Conversation/Engine, IntentDetector, ConversationToneDetector, DiagnosticExperience e Telegram/Deliver, sobre as imagens já publicadas. Não inclui módulos de laboratório existentes apenas no código-fonte. Atualiza somente web, worker e telegram-scheduler. Diagnostic-worker e Agent preservados; nenhuma migração, mudança de política, teste real ou mensagem sintética para Telegram.

Backup completo anterior: `20261118T191153Z`. Overlay anterior e rollback local: `backups/confirmation-production-20261118/rollback.sh`. Reverte as três imagens para route-simple e executa as verificações existentes, sem restaurar banco nem remover mensagens ou resultados. Não reenvia entregas antigas e não reativa confirmações vencidas.

Validação: suíte PostgreSQL isolada executa as classes candidatas de produção; inclui confirmação tardia, revalidação autenticada, duplicatas, cancelamento, expiração, política, escopo, revogação, tenant e formatação com preservação da evidência. Logs locais em `/tmp/agent-center-confirmation-production-tests.log`. Pint e `git diff --check` obrigatórios. Verificações existentes de TLS, filas, saúde e assets antes/depois da publicação.

Resultado: 237 testes, 1823 asserções, nenhuma falha e dois testes SSH opcionais ignorados. Pint aprovado nos oito arquivos alterados desta correção. Imagens: web `sha256:d2e9c3f3f9474574d5a8dd79b155f45b222b867baa67c8e96c34585dea6254a0`; queue `sha256:13e7cdb6f3d13a71d48d955804724707b4f4ef948cb58499bd9f58fa38677b0c`. Classes da imagem comparadas por SHA-256 com a candidata testada.
