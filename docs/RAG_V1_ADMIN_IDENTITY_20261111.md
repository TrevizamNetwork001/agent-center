# RAG V1 — cadastro atualizado e registro administrativo mínimo

A organização beneficiada org-C2uAqILvlucSffzzVtuwCjuO está confirmada pelo operador. Não foi repetida a investigação da credencial anterior nem /v1/me.

Novo cadastro confirmado na Central, tenant 1: Provider b6cf9cf8-ff17-49ed-b1a4-6fb757158ed8, atualizado em 2026-11-11 17:26:34+01 (16:26:34 UTC). Evento llm.secret_updated ID 317, ator ID 1, com provider_id correspondente. O operador confirma cadastro pelo formulário HTTPS protegido; o evento nativo comprova atualização, mas não registra por si só o esquema HTTPS. Nenhum secret/ciphertext foi consultado na verificação operacional desta rodada.

Bloqueio atual: faltam project_id do Default project e Tracking ID administrativo da nova chave. Nenhum desses IDs consta na configuração do Provider ou no evento 317. Não foram inventados nem inferidos a partir de nome ou volume de chamadas. A organização está resolvida; não se exige nova confirmação de seu ID.

## Mecanismo mínimo preparado, ainda não publicado

Comando Laravel agent-center:register-llm-identity e serviço Llm/IdentityRegistry. Sem alteração de schema: usa exclusivamente config.administrative_identity do Provider existente e auditoria append-only. O comando recebe somente IDs administrativos, tenant, ator e ID do evento de atualização. Não recebe secret, não descriptografa credenciais, não chama OpenAI e não produz tasks/Skills/jobs.

O Gate manage-intelligence nativo exige administrador ativo e contexto correto. A seleção do Provider e do evento respeita tenant/RLS. Transação e row lock do Provider vinculam a declaração ao último evento llm.secret_updated daquele Provider; evento antigo ou de outro tenant é recusado. Organização/projeto/tracking têm validação de formato; o registro é explicitamente operator_attestation, não prova criptográfica automática de titularidade. Não transforma coincidência de nome/sufixo em validação. O operador deve declarar que os IDs pertencem à chave cadastrada no evento 317.

A auditoria llm.identity_registered registra organization_id, project_id, tracking_id, provider_id, secret_event_id, ator e horário nativos. O config contém também secret_updated_at, attested_by e attested_at. Atualização subsequente pelo formulário atual limpa o config, invalidando a declaração anterior; registrar novamente exige o novo evento. Nenhuma alteração na credencial ou nos cadastros do modelo, Prompt ou binding.

Execução futura, somente após receber os IDs e a declaração do operador e disponibilizar o código aprovado no backend: php artisan agent-center:register-llm-identity --tenant=1 --actor=1 --provider=b6cf9cf8-ff17-49ed-b1a4-6fb757158ed8 --organization=org-C2uAqILvlucSffzzVtuwCjuO --project=PROJECT_ID_CONFIRMADO --tracking=TRACKING_ID_CONFIRMADO --secret-event=317. Os placeholders não devem ser executados. O evento deve ser reconferido se houver nova rotação.

## Validação e publicação

19 testes, 145 assertions, PASS, em PostgreSQL de laboratório, sem chamadas pagas. Cobertura inclui identidade sem acesso à chave mestre, ciphertext sintético preservado, auditoria, rejeição de evento obsoleto, invalidação após rotação, usuário viewer proibido, isolamento entre tenants e validação sem gravação parcial. Também executadas regressões Knowledge/Prompt e adaptador Responses. Migrations up/down do banco descartável passaram e o banco de teste foi removido. Teste adicional do comando administrativo: 5 testes, 18 assertions, PASS; inclui execução permitida, tenant divergente recusado e limpeza do contexto. Pint PASS nos três arquivos novos. git diff --check executado.

Não houve chamada OpenAI, deploy, migration/grant operacional ou atualização de configuração em produção nesta rodada. O mecanismo está no candidato, não na imagem ativa. Não há rollback operacional a executar. Rollback de código: retirar somente esses arquivos e o evento de auditoria novo da aplicação; preservar qualquer config/registro auditado já produzido, sem apagar histórico. Não executar down de migration em produção.

Após os IDs serem recebidos e registrados, a inferência controlada de no máximo uma chamada desta etapa continua pendente, com captura segura de status, modelo, tokens e request ID; o adaptador atual não preserva request ID, portanto sua captura deve ser preparada antes dessa chamada, sem expor outros headers. Prechecks, backup/restauração e promoção RAG permanecem condicionados à identidade. Não se declara validação real ou deploy concluído.

Agent, Lifecycle, Observability, release 0.6.3, Hermes, equipamentos e R2 não foram alterados.

BLOCKED: project_id e Tracking ID administrativo da nova chave ainda não fornecidos.
