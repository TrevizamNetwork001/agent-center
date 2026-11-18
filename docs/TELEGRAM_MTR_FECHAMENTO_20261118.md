# Fechamento: Telegram, rota, confirmação e latência — 18/11/2026

## Resultado e escopo

Manutenção solicitada pelo usuário sobre o atendimento existente do Telegram. A sequência tratou silêncio na resposta, bloqueio da análise de rota, incompatibilidade Agent/helper, confirmação vencendo durante a interação e legibilidade da latência. Cada publicação operacional teve autorização, backup e rollback próprios. A publicação no GitHub consolida fonte, testes e documentação; não constitui novo deploy ou nova autorização de operação.

## Problemas e correções

| Sintoma | Causa verificada e correção |
| --- | --- |
| Resposta parava após a conversa inicial | Falhas intermitentes de DNS/conexão na saída para Telegram. IPv4 e repetição limitada somente quando há prova de que nenhum pedido foi enviado. Entrega ambígua continua UNKNOWN, sem reenvio automático. |
| Versão não permitia verificar autorização | Contrato da Central não aceitava o Agent 0.6.6. Aceite restrito às versões revisadas, com helper correspondente e prova autenticada. |
| Tracert não atendia ao pedido de ver rota | Pedido genérico pode selecionar MTR ICMP no Agent compatível, informando o método antes da confirmação. Exigência explícita de traceroute permanece respeitada. |
| Política negava 8.8.8.8 | Inclusão autorizada somente de 8.8.8.8/32 nas listas do Agent específico, preservando demais campos; revisão gerenciada 5. |
| Política ajustada, mas resultado UNKNOWN | Agent 0.6.6 com helper 0.6.5. Lifecycle assinado alinhou o helper para 0.6.6, sequência 11, operação COMPLETED/postcheck=true; preflight posterior ALLOWED. |
| Confirmação expirava antes de o usuário responder | Proposta humana de até 300 segundos, com nova prova operacional curta antes de criar tarefa. Consentimento vinculado a tenant, ator, Agent, política, destino, protocolo e plano; cancelamento e alterações impedem execução tardia. |
| “Isso pode fazer” caía na resposta genérica do LLM | Reconhecimento determinístico da confirmação; repetições não criam testes duplicados. LLM continua sem autoridade operacional. |
| Pedido técnico não devolvia “boa tarde” | Saudação inicial preservada na resposta ao pedido técnico. |
| Latência parecia 3000 ms | Apresentação de 3.115 ms como **3,1 ms**, uma casa decimal e vírgula. Latência, perda e respostas em linhas separadas no Telegram; duração total fica nos detalhes técnicos. Evidência original permanece íntegra. |

O teste que o usuário observou concluído foi ICMP/perda e latência, com média 3.115 ms, perda 0%, cinco respostas de cinco e duração total 4010.72 ms. Duração não é latência média. Não confundir esse resultado com prova de execução de MTR. Os procedimentos de manutenção não dispararam diagnóstico real nem mensagem sintética ao Telegram.

## Publicação operacional final

Web: `agent-center-central:confirmation-web-20261118`, digest `sha256:d2e9c3f3f9474574d5a8dd79b155f45b222b867baa67c8e96c34585dea6254a0`.

Worker e scheduler: `agent-center-central:confirmation-queue-20261118`, digest `sha256:13e7cdb6f3d13a71d48d955804724707b4f4ef948cb58499bd9f58fa38677b0c`.

Diagnostic-worker permanece `agent-center-central:route-simple-diagnostic-20261118`. O overlay [compose.telegram-repair.yaml](../deploy/compose.telegram-repair.yaml) é aplicado pelo [wrapper existente](../scripts/compose.sh). As imagens foram derivadas dos runtimes anteriores com classes selecionadas, sem promover a árvore inteira ou integrações LAB. As imagens são locais; publicar fonte no GitHub não publica imagens em registry nem os artefatos assinados do Lifecycle.

Central permanece Laravel/PostgreSQL. Preservados tenant, FORCE RLS, RBAC, Auth/CSRF, TLS, auditoria, allowlist, freeze e separação Central/Agent. Nenhuma migration ou ampliação de privilégios faz parte desta correção.

## Evidências e testes

| Validação executada | Resultado e limite |
| --- | --- |
| Classes candidatas da última publicação, PostgreSQL TLS isolado | 237 testes, 1823 asserções, zero falhas, dois testes SSH opcionais ignorados. |
| Segurança da confirmação | Expiração, mudança de política/escopo, revogação, tenant cruzado, helper inválido, resposta negativa e callback antigo bloqueiam execução; cancelamento e duplicatas cobertos. |
| Formatação | Caso 3.115 → 3,1 ms; perda e respostas em linhas separadas; igualdade da evidência persistida; nenhuma tarefa criada pelo teste. |
| Estilo e integridade | Pint aprovado nos oito arquivos da última correção; `git diff --check` aprovado. |
| Implantação | Hashes das cinco classes finais conferidos no web; imagem construída corresponde à candidata testada. |
| Saúde pós-publicação | Web/worker/diagnostic-worker healthy; scheduler running. Login e readiness HTTPS 200; TLS positivo/negativo, PDO verify-full, fila técnica e assets PASS. |
| Agent/helper | 202 testes Python e laboratório com artefatos assinados, rollback automático, replay/adulteração e downgrade recusados. Pré/pós-verificação reais do Lifecycle aprovadas na VM específica. |

Os resultados acima pertencem às execuções documentadas; não representam suíte completa universal, teste em equipamento físico ou auditoria atualizada de vulnerabilidades.

Repetição antes do commit, usando a fonte do checkout: **237 testes, 1823 asserções, zero falhas e dois skips opcionais**, exit 0. PostgreSQL separado, TLS e credenciais sintéticas; log local `/tmp/agent-center-github-final-tests.log`. Nenhum serviço da produção recriado para esta publicação GitHub.

Para reproduzir o recorte Laravel, provisionar PostgreSQL TLS descartável e os overrides de laboratório descritos em [scripts/test.sh](../scripts/test.sh), com assets frontend explícitos; nunca usar a base de produção. Filtro usado:

```sh
scripts/test.sh --filter 'TelegramClientTest|TelegramTest|PreflightRealFlowTest|OperationalCapabilitiesTest|DiagnosticIntentPlanningTest|ConversationTest|ConversationTone|NaturalConversationTest|ConversationContextTest|DiagnosticExperienceTest'
```

Na execução anterior às imagens finais foram montadas somente as cinco classes candidatas sobre o ambiente de testes. A repetição anterior ao commit usa as classes do checkout. Os testes usam transporte e respostas de Agent simulados para verificar o contrato, sem sondar destinos ou enviar mensagens reais.

Checagens da publicação GitHub: Pint em 17 arquivos aprovado; Compose config aprovado; 1351 links locais da documentação alterada conferidos, nenhum ausente; scan básico do índice aprovado. O scanner completo do histórico registrou 160 commits, 3004 blobs, uma correspondência e um caminho proibido, exit 1: ressalva histórica do bundle auxiliar já conhecida. Não afirmar scan histórico limpo nem remover referências antigas nesta publicação. A primeira tentativa não conseguiu ler a chave de comparação restrita; a repetição autorizada concluiu sem imprimir seu conteúdo.

## Backup, rollback e limites

Último backup completo anterior ao deploy da Central: `20261118T191153Z`. Rollback local restrito: `backups/confirmation-production-20261118/rollback.sh`, restaura o overlay route-simple e recria apenas web/worker/scheduler, seguido de precheck. Preserva banco, mensagens, resultados e filas; não reativa confirmações antigas nem reenvia UNKNOWN. Backups e logs privados permanecem fora do Git.

O rollback do Agent é distinto: a reversão automática durante falha transacional foi testada. Após commit, a proteção antirrebaixamento permanece; o pacote 0.6.5/seq12 foi somente fixture negativa recusada, não recuperação aprovada. Consultar o relatório do Lifecycle antes de qualquer intervenção.

A instabilidade externa de saída ainda pode ocorrer. A recuperação automática limita-se a cinco tentativas e falhas comprovadamente anteriores ao envio. Backup externo atual, renovação do LAB backup-ui e aceite físico de equipamentos mantêm suas pendências anteriores. A ressalva histórica do bundle nas branches auxiliares também permanece; esta publicação não altera branches antigas nem reescreve histórico.

## Relatórios para retomada

- [Entrega Telegram: análise e testes](TELEGRAM_DELIVERY_REPAIR_20261118.md).
- [Primeira correção de produção e restore isolado](TELEGRAM_REPAIR_PRODUCTION_20261118.md).
- [Seleção simplificada da ferramenta de rota](ROUTE_SIMPLE_PRODUCTION_20261118.md).
- [Ajuste específico de política 8.8.8.8](MTR_8_8_8_8_POLICY_20261118.md).
- [Alinhamento assinado Agent/helper 0.6.6](MTR_HELPER_ALIGNMENT_066_20261118.md).
- [Confirmação, saudação, números e rollback final](CONVERSATION_CONFIRMATION_REFRESH_20261118.md).
- [Pendências atuais](PENDENCIAS_20261118.md) e [guia de retomada](RETOMADA_DO_PROJETO_20261118.md).
