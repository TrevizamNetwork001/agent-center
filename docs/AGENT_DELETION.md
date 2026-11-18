# Apagar Agent revogado

Disponível na lista e nos detalhes, com confirmação nativa da interface. Somente administradores do tenant e da plataforma com contexto de tenant podem apagar; exige status revoked e revoked_at preenchido, conferidos novamente sob lock de linha. Operadores, viewers e usuários de outro tenant não podem usar a ação.

DELETE /agents/{uuid} usa autenticação, sessão, CSRF, política e RLS existentes. A remoção usa SoftDeletes do Laravel e registra agent.deleted na auditoria. O registro, enrollments, diagnósticos, tasks e operações são preservados; desaparece da lista e seletores comuns. Histórico de diagnóstico inclui o Agent apagado via withTrashed, mantendo o escopo do tenant. Credenciais permanecem revogadas. Não há forceDelete nem exclusão em cascata, DELETE no banco ou ampliação de grants/RLS.

Migration aditiva: agents.deleted_at timestamptz nullable. Não modifica registros existentes ou status. A implantação não apaga nenhum Agent: o operador usa o botão no cadastro escolhido.

Antes da migration: backup local completo, privado e verificado, BACKUP_PRUNE_ENABLED=false. Snapshot específico guarda código e compose anteriores. Migrar com o papel proprietário pela unidade migrator; runtime continua sem DDL/bypass RLS. Atualizar apenas web/worker/diagnostic-worker da Central.

Rollback: restaurar arquivos e compose do snapshot /opt/agent-center/backups/agent-delete-<timestamp>/ e executar scripts/compose.sh up -d --no-deps web worker diagnostic-worker e php artisan view:clear no web. Preservar volumes e a coluna aditiva, sem migrations down. Na imagem anterior, registros apagados podem reaparecer como revogados, sem recuperar acesso; não limpar deleted_at ou reativar tokens. Código anterior permanece compatível com o schema aditivo.

Validação: suíte Laravel/PostgreSQL completa, incluindo remoção de cadastro manual revogado, recusa de cadastro não revogado, operadores e acesso cruzado; preservação do registro/auditoria e leitura de diagnóstico histórico após remoção. Pint e git diff --check. Nenhum Agent real removido durante a implantação.
