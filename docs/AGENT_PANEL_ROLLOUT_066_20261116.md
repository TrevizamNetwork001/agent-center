# MikroTik Read-Only — infraestrutura 0.6.6 pelo painel

Data: 16/11/2026. Escopo operacional: somente a VM `teste`, Agent
`c88457e9-748f-4906-982a-166d0ee0f6d3`. Consultas a equipamentos físicos
permanecem bloqueadas. A autorização operacional foi fornecida pelo usuário.

**MIKROTIK READ-ONLY INFRASTRUCTURE PRODUCTION PASS**

A VM está na **0.6.6**, protocolo de pacote **3**, sequência instalada **10**.
As operações finais do Lifecycle concluíram com postcheck aprovado. A observação
completa durou **900,906 segundos**, sem falhas críticas. Identidade, credenciais,
configuração, políticas, permissões, helper, unidades systemd e journal foram
preservados. Nenhum equipamento real foi acessado.

## Causas e correções

- O companion 0.6.5 aceitava somente Python no arquivo de distribuição. O bundle
  SSH contém wheels, e atualizar apenas o runtime não atualizava seu decoder.
  O laboratório anterior usava bootstrap externo e não demonstrava o caminho
  autônomo instalado na VM. Agora uma solicitação no painel encadeia uma ponte
  Python assinada e o pacote final pelo Lifecycle existente.
- PHP/nginx limitavam uploads a 2 MiB, abaixo do limite de 8 MiB já validado pelo
  controller. O pacote tem cerca de 6,4 MiB. A publicação administrativa aceita
  agora envelope multipart de 10 MiB, com artefato limitado a 8 MiB e a mesma ACL.
  As outras rotas externas conservam 2 MiB.
- Uma escrita no primeiro build da imagem falhou por permissão, sem propagar
  essa falha. A publicação foi recusada antes de criar uma operação no Agent.
  O build foi corrigido e agora verifica a versão efetiva e o limite de upload;
  o usuário final dos serviços continua sendo `www-data`.
- A primeira transferência operacional do pacote final ocupou aproximadamente
  227 segundos. O limite systemd de 240 segundos interrompeu o precheck antes do
  apply. A ponte permaneceu saudável. O protocolo 3 transfere partes autenticadas
  de até 512 KiB, com cache privado e retomada da mesma pré-verificação entre
  timers. O limite systemd, a assinatura, o hash completo e o self-check são
  preservados; dados parciais nunca autorizam instalação.
- O painel passou a reconciliar prechecks interrompidos sem apply: exige revisão
  autorizada, Agent recente, sequência não instalada e ausência da concessão
  transacional `agent.update_started`. Instalações possivelmente interrompidas
  continuam exigindo a recuperação verificada pelo Agent. A falha original não
  é apagada.

Não foi criado outro updater, servidor Python da Central, instalador por SSH ou
dependência de pip operacional. Não houve migration, alteração de RBAC/FORCE RLS,
R2, ACL de gerenciamento, credencial de equipamento ou desativação do freeze.

## Publicação e execução

Commits de implementação:

- `8dc788d` — transição assinada pelo painel e E2E com o companion original.
- `b7aeb64` — validação efetiva das imagens e upload administrativo limitado.
- `a6a9bb1` — transferência retomável e recuperação de precheck sem apply.

As sete classes PHP publicadas correspondem byte a byte ao commit `a6a9bb1`.
O contrato operacional `Protocol.php` recebeu apenas a inclusão da versão 0.6.6,
mantendo as sete capabilities anteriores. A integração de transporte de
laboratório permanece desabilitada em produção.

| Imagem em produção | ID |
| --- | --- |
| `agent-center-central:panel-updater-web-20261116` | `b1bc8c0013baa0566767ef0af5659acb98958167b9e486c17f78d8edb9b73c94` |
| `agent-center-central:panel-updater-queue-20261116` | `d482780c611af758e8481720d5480c3652c68c8acd28e1ce0c67ae6ad34e1d56` |
| `agent-center-central:panel-updater-diagnostic-20261116` | `6e814bfe8cd5def9e05c74be8c22f8026736b469352c8aa07c9e702d5c1cc367` |

Somente web, worker, telegram-scheduler e diagnostic-worker foram recriados.
PostgreSQL e os demais serviços mantiveram suas imagens. O vhost da Central
recebeu somente o ajuste de upload da rota administrativa, com validação nginx.

As releases operacionais usam Ed25519 com a autoridade já confiada pela Central
e pelo Agent; não usam assinatura de laboratório. SHA-256 da chave pública raw:
`5f16350383c280c1b39d4f7257f09ecbe1002b1d59a1721ceb8bf4d5091195e1`.

| Sequência | Conteúdo | SHA-256 do artefato |
| --- | --- | --- |
| 9 | Ponte Python 0.6.6 | `341259577de5ea8a7db31300df9701cc969d4c74a4c94fb82790b1a138ac3354` |
| 10 | Runtime 0.6.6 com SSH offline | `737338df796262843d087ccd1f49e6861ee631e33e6d7c98910d5fab0dd3dce4` |
| 11 | Restauração 0.6.5 preparada, **não publicada** | `b1821094e1b2d431ca0e8d68d8b0a1ef2ca0eeda05d49b001ea6a0b96acbeef7` |

Operações finais, ambas **COMPLETED**:

- Ponte: `14124e00-0179-4250-b7b9-2f8c563959e4`.
- Pacote final: `ca9b2dff-e548-4cf6-a017-923e1fb61fe2`.

A tentativa anterior, sequência 8, ficou registrada como `interrupted` e foi
encerrada pela revisão nativa do painel em
`ecb7e54a-86b5-4641-8bd1-f63e58501eb5`. Não houve apply dessa tentativa nem
limpeza manual do estado do updater.

## Validação

| Verificação | Resultado |
| --- | --- |
| Laravel completo | 604 testes, 5.563 asserções; zero falhas/erros; 5 condicionais |
| Python candidato completo | 190 testes PASS, executados sem alterar os testes originais |
| Proveniência, segurança e download | 18 verificações adicionais PASS |
| Atualização nativa Debian 13/systemd | PASS, timer/HTTPS reais, Agent não-root, zero SSH na atualização |
| Falha do pacote final | PASS, rollback automático para a ponte saudável |
| Retorno completo 0.6.5 pelo Lifecycle | PASS; companions originais recuperados; SSH fora do runtime ativo |
| Seis consultas tipadas via Task Engine/Agent/API/SSH simulado | PASS, runtime final assinado, 225 asserções, Web e Telegram simulados |
| Preparação somente identidade | PASS, 41 asserções, outras cinco consultas negadas, zero conexões físicas |
| PostgreSQL/FORCE RLS e acesso cruzado | PASS; runtime sem superuser/bypass RLS |
| Concorrência | PASS: claims, fencing, callbacks, deduplicação, conversação e SSH simulado |
| Rollback de migrations com dados | Recusa destrutiva e preservação dos dados comprovadas |
| Pint | 383 arquivos PASS |
| Ruff dos arquivos alterados | PASS |
| Build das imagens e frontend isolado | PASS; nenhuma mudança visual adicional publicada |
| Secret scan do índice e diff check | PASS |

A varredura Ruff completa ainda acusa **dois I001 herdados**, em `preflight.py`
e `managed_settings.py`. Esses arquivos foram preservados byte a byte da fonte
operacional assinada 0.6.5. Não foram adicionadas supressões para ocultá-los.

Os cinco skips da suíte geral são condicionais esperados: três casos do Lifecycle
nativo e dois casos RouterOS/Task Engine exigem seus laboratórios com systemd e
SSH simulado. São exercitados separadamente. Não representam autorização ou
cobertura de acesso físico. Uma execução adicional dos dois casos RouterOS no
mesmo banco encontrou `tenants_slug_unique`, pois o primeiro confirma fixtures
para o Agent/API reais; os casos são executados em bancos/laboratórios novos.
A falha da tentativa em lote não foi usada como evidência de aprovação.
As duas novas execuções isoladas passaram sem erros, com o artefato final
`737338df...` e os mesmos hashes de dependências usados na VM.

## Integridade, observação e rollback

Conferência independente somente de leitura confirmou o hash do runtime e do
companion derivado do pacote assinado. Configuração, identidade, políticas,
helper e unidades systemd conservaram hashes, proprietários e permissões.
O journal passou no `quick_check` e manteve exatamente os dados das **103
execuções e 412 eventos**. Não restaram downloads parciais ou staging.

Instalação e reconciliação usaram os formulários autenticados do painel e o
Lifecycle por HTTPS. SFTP foi utilizado exclusivamente para inspeções e comparação
independente de arquivos, sem `exec`, `put` ou instalação manual na VM.

A observação de 900,906 segundos iniciou após as duas operações concluírem.
As amostras registram readiness da Central, heartbeat/polling recentes, updater
0.6.6/protocolo 3, sequência 10, serviços saudáveis, zero tarefas ativas,
zero jobs falhos e zero operações pendentes. O scheduler não tem healthcheck
Docker próprio; foi comprovado em execução. As consultas de device continuam
negadas, com freeze ativo e transporte de laboratório desabilitado.
O Health refere-se aos componentes principais desta publicação. Containers
`backup-ui-*` já apresentavam falhas antes dela e permaneceram fora do escopo,
sem intervenção.

O rollback automático de uma falha na etapa final retorna à ponte 0.6.6 saudável.
O retorno integral à 0.6.5 foi comprovado separadamente pelo próprio Lifecycle,
com código original reempacotado, nova assinatura e sequência superior, sem
rebobinar o journal ou a proteção antirreplay. A restauração operacional 11 está
preparada e não foi publicada, pois não houve falha crítica após a atualização.
Backups auditáveis são preservados fora do caminho ativo.

O backup PostgreSQL anterior está fora do Git, restrito, com inventário
`pg_restore` validado. O rollback da Central remove o override
`deploy/compose.panel-updater.yaml` e usa as imagens anteriores, preservando
releases, auditoria e dados; não exige restaurar o banco.

## Limites preservados

Assinatura inválida, replay, política inválida, tenant divergente, autorização
expirada, incompatibilidade e falha real de rede continuam sendo recusados.
Nenhuma configuração remota ou CLI arbitrária foi habilitada. A observabilidade
detalhada do helper legado não concede visibilidade de políticas privadas; sua
limitação de versão é preservada e não contorna Preflight.

A homologação física futura exige autorização operacional específica e plano
próprio, com escopo de equipamento, identidade, fingerprint, credencial,
políticas e destinos aprovados. Esta entrega não habilita esse acesso.

Evidências sanitizadas:
[agent-panel-rollout-066-20261116](evidence/agent-panel-rollout-066-20261116/).
Detalhes do mecanismo e do rollback:
[AGENT_PANEL_SIGNED_TRANSITION_066.md](AGENT_PANEL_SIGNED_TRANSITION_066.md).
