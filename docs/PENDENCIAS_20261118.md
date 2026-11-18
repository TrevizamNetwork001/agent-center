# Pendências verificadas — 18/11/2026

**Atualização posterior:** correções autorizadas de Telegram, rota, confirmação, saudação e latência publicadas na Central. Estado final, testes, imagens, backups, rollback e limites no [fechamento desta manutenção](TELEGRAM_MTR_FECHAMENTO_20261118.md). Os parágrafos de inspeção inicial abaixo são históricos; as imagens atuais constam no fechamento. Backup externo e demais pendências não foram encerrados por essa correção.

Inspeção inicial somente de leitura do runtime, após a publicação de fonte `2530cca`, seguida de correção autorizada exclusivamente no laboratório `backup-ui`. Não houve deploy da Central, migration ou acesso a Agent/equipamento.

## Central e diferença de implantação

Web, worker, diagnostic-worker, PostgreSQL e nginx estavam healthy na consulta.
O telegram-scheduler estava running, sem healthcheck; isso não comprova entrega de mensagens.
As imagens da Central continuam `panel-updater-*-20261116`.

A comparação dos diretórios app, resources, routes e config extraídos do container web com o checkout confirmou diferenças. Há mudanças em conversa, diagnósticos, Preflight, protocolo, auditoria, entrega Telegram e views, além de arquivos exclusivos de observabilidade e do laboratório RouterOS. A comparação não abrange banco, dependências, assets compilados ou os arquivos internos dos workers. Diferença de arquivo não demonstra defeito nem autoriza substituir o runtime.

A próxima implantação deve selecionar os ajustes de manutenção, revisar seus contratos e dependências e manter desabilitados os recursos de laboratório. Não promover o checkout inteiro apenas para eliminar a divergência. A validação histórica da publicação está em [Publicação GitHub](PUBLICACAO_GITHUB_20261118.md); os testes não foram reexecutados nesta inspeção.

## Falha inicial do laboratório backup-ui — corrigida

Os containers `agent-center-backup-ui-*` pertencem ao projeto Compose de recuperação antigo, com configuração de laboratório separada. Não são prova de falha do backup externo de produção.

- PostgreSQL: unhealthy; log registra `sslv3 alert certificate expired`.
- Certificado público do servidor desse laboratório: validade encerrada em **06/11/2026 21:10:14 UTC**.
- Queue worker e diagnostic-worker: reiniciando; queue worker registra SQLSTATE `08006`, compatível com falha de conexão. Não foi inspecionado o detalhe confidencial da conexão.
- Web e nginx do laboratório: healthy, o que não demonstra saúde funcional do conjunto.
- Certificado público PostgreSQL principal: válido até **04/11/2027 21:15:51 UTC**; não há motivo nesta evidência para substituí-lo.

Correção operacional proposta, restrita ao laboratório: inventariar certificados e mounts, conferir a cadeia e sua validade, preservar configuração e dados, emitir certificados conforme o procedimento existente e atualizar somente os mounts necessários. Manter verificação TLS; não desativar validação ou copiar secrets da produção. Recarregar/recriar apenas os serviços que precisarem carregar o certificado e comprovar conexão autenticada e progresso dos workers. Se houver falha, restaurar os arquivos/configuração anteriores preservando volumes e banco; como o certificado anterior está expirado, esse rollback não garante saúde e não deve ser apresentado como solução. A intervenção depende de autorização específica para esse ambiente separado.

### Execução autorizada e resultado

O usuário autorizou explicitamente a correção desse laboratório após apresentação do diagnóstico e escopo. Em 18/11/2026 foram renovados somente os certificados públicos CA e servidor da PKI de laboratório, mantendo as mesmas chaves privadas, subject, SAN `DNS:postgres`, mounts e `pg_hba.conf`. A validade nova termina em **18/12/2026 16:53:49 UTC**. Essa PKI continua exclusiva do laboratório; não foi instalada em produção.

Os dois arquivos públicos foram escritos preservando seus inodes para conservar os bind mounts individuais dos clientes. PostgreSQL recebeu `pg_reload_conf()` com retorno verdadeiro, sem restart. Foram reiniciados somente queue worker e diagnostic-worker do laboratório. Não houve alteração de imagens, volumes, schema, roles, FORCE RLS ou dados por restauração.

Validações executadas:

- Backup lógico custom do banco antes da mudança; inventário `pg_restore --list` aprovado. Isso não equivale a novo teste de restore completo.
- Cadeia renovada e hostname `postgres`: `openssl verify` aprovado.
- Conexão autenticada PostgreSQL com `sslmode=verify-full` e CA montada: `pg_stat_ssl.ssl = true`.
- Cliente web enxerga o novo certificado CA pelo mount existente.
- Cinco containers do laboratório healthy, incluindo os dois workers; comandos `agent-center:queue-health` e `agent-center:worker-health` com exit 0. Queue registra conclusão de jobs OperationsPulse.
- Inventário após correção: 36 tabelas públicas, 28 com FORCE RLS; total de tabelas igual ao observado antes. Chaves privadas e `pg_hba.conf` comparados com backup, sem diferenças.

Backup restrito e ignorado pelo Git: `backups/backup-ui-tls-repair-20261118/`, diretório 0700, dump 0600. Não adicionar seu conteúdo ao versionamento. A cópia temporária também permanece restrita. Nenhuma chave ou credencial integra este relatório.

Rollback específico: em caso de necessidade autorizada, restaurar somente o conteúdo dos certificados públicos `original/ca.crt` e `original/server.crt` do backup nos arquivos existentes do laboratório, preservando inodes e modo 0644; executar reload do PostgreSQL e reiniciar somente os dois workers se necessário. Não restaurar o dump nem substituir chaves, mounts ou volumes. Os certificados antigos estão expirados: esse procedimento preserva o estado anterior, mas reintroduz a indisponibilidade, portanto requer decisão operacional explícita.

Manutenção futura: renovar a PKI de laboratório antes da data acima ou encerrar o laboratório mediante autorização própria. Não foi instalada automação nem ampliado o escopo do backup de produção.

## Pontos que permanecem separados

- Backup externo: não verificado. A consulta de timers não pôde conectar ao bus systemd; isso não prova timers inativos nem backup ausente.
- Agent 0.6.6: implantação histórica comprovada somente na VM teste; nenhuma consulta nova à VM foi feita.
- MikroTik: bloqueios antigos do candidato foram resolvidos no [aceite LAB](MIKROTIK_READONLY_066_LAB_COMPLETION.md), seguido pelo [rollout limitado](AGENT_PANEL_ROLLOUT_066_20261116.md). Equipamentos físicos continuam congelados.
- Outros fabricantes e WhatsApp: escopo futuro, não correções autorizadas nesta solicitação.

## Autorização e validação da próxima mudança

Continuação autorizada: [confirmação, saudação e métricas do Telegram](CONVERSATION_CONFIRMATION_REFRESH_20261118.md). Corrige confirmação após expirar a prova curta com nova validação do mesmo plano; responde à saudação e apresenta 3.115 ms como 3,1 ms, preservando a evidência. Publicação limitada à Central, com backup e rollback; sem atualização do Agent ou alteração de ACL.

**MTR 8.8.8.8 resolvido:** políticas e compatibilidade concluídas na continuação autorizada. Agent/helper 0.6.6, Lifecycle sequência 11 COMPLETED e preflight real ALLOWED para 8.8.8.8/ICMP/revisão 5. Nenhum MTR executado; cada pedido ainda exige prova fresca e confirmação. Ver [alinhamento e limites do rollback](MTR_HELPER_ALIGNMENT_066_20261118.md).

Continuação autorizada posterior: [análise de rota simplificada publicada](ROUTE_SIMPLE_PRODUCTION_20261118.md). Pedido coloquial de tracert agora pode selecionar MTR no Agent compatível e informa esse método antes da confirmação; exigência de somente traceroute é preservada. Preflight/ACL/tenant/confirmação continuam obrigatórios. O aviso anterior também confundia método sem contrato com versão incompatível. Não houve atualização do Agent nem teste de rota real nesta publicação.

Correção posterior publicada para o silêncio no Telegram: [relatório de produção](TELEGRAM_REPAIR_PRODUCTION_20261118.md). O usuário autorizou backup, rollback e publicação limitada; foram corrigidas a compatibilidade do preflight 0.6.6 e a recuperação segura de falhas anteriores à conexão. A instabilidade de saída ainda existe, com recuperação limitada a cinco tentativas; entregas ambíguas UNKNOWN continuam preservadas, sem reenvio. Ver relatório para imagens, testes, restore isolado e limites. Não há prova de execução de tracert nem eliminação da causa externa/DNS.

Deploy da Central e intervenção no laboratório backup-ui são ações distintas. Cada uma exige escopo operacional específico conforme [AGENTS.md](../AGENTS.md), backup, validação e rollback preservando dados. Não atualizar Agents nem acessar equipamentos como parte dessas ações.

Esta correção é documental e reversível por diff inverso dos arquivos alterados; não exige rollback de banco ou serviço. Conferir links locais e executar `git diff --check` antes de encerrar.
