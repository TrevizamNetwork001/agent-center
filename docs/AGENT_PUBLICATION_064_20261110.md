# Publicação do Agent 0.6.4 — 10/11/2026

A publicação foi autorizada pelo operador e concluída na Central e no Agent
cadastrado `teste`, tenant 1. O runtime, companion, CLI e helper MTR estão em 0.6.4.
A operação da Central terminou `COMPLETED`, com pós-check e freeze confirmados.
A janela posterior de 900.000 segundos passou, com 31 amostras.
O [patch de fonte para revisão](evidence/agent-publication-064-20261110/release-source.patch)
abrange as correções do Agent e as mudanças de backend desta publicação; não é
um procedimento de rollback operacional nem deve ser revertido sobre trabalho posterior.

## Identificação da entrega

- Release: `f00ec265-6230-4eb8-8651-a5a8343c353a`, sequência **5**.
- Operação HTTP/Agent API: `6122b074-c512-478b-a9de-237f72c837b2`.
- Bootstrap do companion pelo Lifecycle: `762a3311-5f39-4f87-b42d-a24f925c5a3a`.
- Agent: `c88457e9-748f-4906-982a-166d0ee0f6d3`.
- Fonte SHA-256: `e8be8ffab0eeefb05be49cd15fabd0e49b8623f0e953408002b605a242f60303`.
- Runtime SHA-256: `a4d87d91c7592ddb59218e670e2c839d57fea7ca67dee5ae571004eacf607bff`.
- [Manifest assinado](evidence/agent-publication-064-20261110/manifest.json),
  [assinatura Ed25519](evidence/agent-publication-064-20261110/signature.base64) e
  [chave pública](evidence/agent-publication-064-20261110/signer-public.pem).
- [Hashes dos cinco pacotes preparados](evidence/agent-publication-064-20261110/signed-candidate.json).

O candidato preliminar de mesmo número não foi publicado. A release imutável
publicada contém o SHA acima, com a manutenção transacional dos atualizadores.
O relatório de preparação preserva seus flags anteriores à publicação; o estado
final está no resumo de validação. As releases anteriores, inclusive a sequência
4/0.6.3 em quarentena, foram
preservadas. A chave operacional existente foi reutilizada; nenhuma chave privada
foi enviada à Central, VM ou repositório.

## Fechamento da pendência do companion

O Lifecycle passou a derivar os entrypoints fixos do companion e da CLI a partir
dos módulos do runtime autenticado pela assinatura. Não aceita scripts, comandos
ou destinos de instalação vindos do manifest. Os únicos arquivos adicionais são
`/opt/agent-center-agent/remote-updater.pyz` e `updater.pyz`.

A autorização administrativa local exige `lifecycle-updater` em `profiles` e
`components`, mantendo `device_operations_frozen=true`. Essa allowlist foi
habilitada explicitamente no bootstrap deste Agent. Políticas-base e teto MTR,
contas, ACLs, units e privilégios foram preservados.

A transição inicial usa o **Manager existente**, com adapter fechado e ledger
separado em `companion-lifecycle`; o verificador previamente provisionado confere
assinatura, artefato e sequência antes de executar o bootstrap derivado. O
bootstrap atualizou somente os dois atualizadores e preservou runtime 0.6.2,
identidade, configuração-base e ledger principal. O novo companion anunciou 0.6.4
à Central antes da solicitação HTTP da atualização completa.

Nas atualizações seguintes, o RuntimeAdapter inclui backup dos dois arquivos e
os instala após o pós-check do runtime, antes do commit da sequência. Falhas no
finalize acionam rollback. O companion executa de cópia privada estável para que
imports tardios não misturem versões durante sua própria substituição.

A Central recusa solicitar ou entregar uma release >=0.6.4 a um companion antigo,
inclusive se a versão anunciada regredir entre solicitação e claim. Não há
fallback para a CLI legada `--apply`, que permanece bloqueada.

O primeiro acionamento foi recusado porque os novos eventos de auditoria ainda
não estavam na allowlist. A recusa ocorreu na Central, antes de executar a
transição no host. Foram registrados e testados os eventos
`agent.lifecycle_bootstrap_authorized` e `agent.lifecycle_bootstrap_completed`,
com isolamento por tenant e filtragem de secrets. Depois disso, a transição
concluiu e seus eventos foram gravados pelo serviço de auditoria nativo.

## Homologação e produção

| Verificação | Resultado |
|---|---|
| Fonte final Python, usuário sem privilégios | 185 testes aprovados |
| Preparação/assinatura e rejeição de adulteração/replay | 2 testes aprovados com chave descartável |
| Laravel: API, observabilidade, assinatura, quarentena, operações e auditoria | 51 testes / 459 assertions; PostgreSQL isolado e migrations up/down |
| Debian 13/systemd isolado: falha HTTP real de pós-check | Rollback restaurou runtime, helper e os dois atualizadores; identidade/journal preservados |
| Debian 13/systemd: transição legada e atualização completa | Ambas `COMPLETED` com a fonte assinada final |
| Debian 13/systemd: interrupção durante CHECKING | Repetição bloqueada; recuperação verificada preservou um registro posterior no journal |
| Debian 13/systemd: serviço endurecido do companion | Execução da cópia estável terminou com exit 0 |
| Agent real | Precheck, aprovação, execução e resultado `COMPLETED` por HTTP/Agent API, sem SSH durante a atualização autônoma |
| Binários reais | Quatro versões 0.6.4, hashes iguais aos pacotes da fonte assinada |
| Dados reais | Todos os registros anteriores de executions/events comparados com o backup e preservados; identidade e configuração-base iguais |
| DNS após atualização | Quatro consultas `COMPLETED`, classificação `NORMAL`; journal 91/364 → 95/380, zero pendências |

A atualização foi autorizada às **17:35:41 UTC**, terminou na Central às
**17:36:12 UTC** e foi observada como concluída às **17:36:15 UTC**. Sessões
administrativas temporárias de publicação e DNS foram destruídas após uso.

O diagnóstico DNS pós-atualização é `68883c7f-c336-4778-bf22-9dd1f925bd44`.
Nenhum MTR ativo, acesso a equipamento, alteração de zona, captura, varredura ou
nova integração foi executado. A sondagem DNS usa o fluxo seguro existente.

[Evidência da operação HTTP](evidence/agent-publication-064-20261110/publication-http.json),
[verificação dos binários e preservação dos dados](evidence/agent-publication-064-20261110/real-after-dns.jsonl),
[DNS](evidence/agent-publication-064-20261110/dns-after-update.json),
[laboratório de instalação/rollback](evidence/agent-publication-064-20261110/native-lab-install-rollback.jsonl) e
[laboratório de interrupção](evidence/agent-publication-064-20261110/native-lab-interruption.jsonl).

Os procedimentos técnicos do laboratório foram preservados em
[scripts/lab/agent-lifecycle-native-e2e.py](../scripts/lab/agent-lifecycle-native-e2e.py)
e [scripts/lab/agent-lifecycle-native-interruption.py](../scripts/lab/agent-lifecycle-native-interruption.py).
O laboratório isolado foi desligado ao final, com identidade e binários do
Agent operacional novamente conferidos como inalterados pela limpeza.
Exigem ambiente nspawn/systemd de laboratório com a fixture provisionada em
`/lab/publish064`; não devem ser executados na VM operacional.

## Observação após publicação

Janela UTC: **2026-11-10T17:40:20.939824+00:00 → 2026-11-10T17:55:20.940120+00:00**, 900.000 s,
31 amostras. Heartbeat avançou **45** vezes;
polling avançou **128** vezes. Mesmo processo principal,
nenhuma amostra DEGRADED/QUARANTINED, status recente, binários esperados, conta sem
root e NoNewPrivileges ativos. Identidade, configuração-base e journal 95/380
permaneceram iguais, com zero pendências e quick_check aprovado em cada amostra.

A consulta aos journals de runtime/companion teve exit 0 e não encontrou os
eventos de falha monitorados no período. Isso é uma janela limpa de observação,
sem atribuir causa definitiva à instabilidade histórica da infraestrutura.
[Observação integral](evidence/agent-publication-064-20261110/observation-900s.json) e
[resumo da validação](evidence/agent-publication-064-20261110/validation-summary.json).

## Central publicada

- Web: `agent-center-central:agent064-web-audit-20261110`.
- Workers: `agent-center-central:agent064-workers-20261110`.
- A imagem web original foi preservada e recebeu somente quatro arquivos PHP:
  Protocol, Operations, UpdateController e Audit. Workers receberam somente Protocol.
- Sem migration de produção, alteração de rede, firewall, exposição de serviços
  ou deploy das alterações locais preexistentes de interface.
- Backup PostgreSQL em formato custom verificado por `pg_restore --list`, privado
  em `backups/agent064-publication-20261110/database.dump`.
- Readiness HTTPS 200; serviços da Central saudáveis após deploy.
  [Serviços finais](evidence/agent-publication-064-20261110/services-final.json) e
  [identificadores imutáveis das imagens](evidence/agent-publication-064-20261110/image-ids.json).
- Artefato privado sem autenticação: HTTP 401. PostgreSQL confirmou TLSv1.3;
  role runtime sem superusuário/bypass e FORCE RLS em agents, releases,
  operations, tasks e audit_events. As duas quarentenas anteriores permanecem.
  [Conferência de segurança](evidence/agent-publication-064-20261110/security-final.json) e
  [HTTPS](evidence/agent-publication-064-20261110/https-final.json).

## Backup e rollback

Backup principal do Agent:
`/var/lib/agent-center-updater/backups/6122b074-c512-478b-a9de-237f72c837b2`.
Inclui evidências do estado anterior e backups separados do MTR e atualizadores.
O snapshot contém runtime/helper 0.6.2 e atualizadores 0.6.4 já preparados pelo
bootstrap. O backup anterior dos atualizadores 0.6.2 está em
`/var/lib/agent-center-updater/companion-lifecycle/backups/762a3311-5f39-4f87-b42d-a24f925c5a3a/updaters`;
a política anterior, em `companion-lifecycle/policy-backups/before.json`.

Durante a instalação, o Manager restaura os arquivos pelo adapter se houver
falha, sem restaurar identidade ou journal. Interrupção abrupta mantém hold e
exige recuperação verificada; não há replay automático. Ambos os caminhos foram
homologados no laboratório nativo. Os testes de arquivos também cobrem falha na
segunda substituição do updater e recusa de backup adulterado antes de gravar.

Para recuperação administrativa posterior de uma operação já concluída:

1. Impedir nova distribuição por quarentena nativa da release afetada, preservar
   auditoria e concluir/segurar operações em andamento. Conferir tarefas e leases;
   não cancelar tentativas para contornar fencing.
2. Fazer novo backup do estado atual e verificar os manifests/bytes do backup
   escolhido. Suspender coordenadamente apenas o Agent/timer deste host e obter
   os locks existentes do Lifecycle.
3. Restaurar conjuntamente runtime e MTR pelo adapter do Lifecycle, usando os
   arquivos autenticados do backup e conferindo o `.previous` esperado. A CLI
   legada `--apply` e o rollback isolado do painel não são alternativas válidas.
   Manter os companions 0.6.4 compatíveis ou revisar o backup do bootstrap se
   também for necessário recuperá-los.
4. Nunca restaurar identity/journal/WAL/leases antigos sobre dados atuais, nem
   reduzir os contadores/ledgers para permitir replay. Verificar integridade e
   preservação dos registros novos, freeze, hashes, helper e heartbeat/poll reais;
   registrar a recuperação e conciliar os metadados administrativos com o estado
   efetivamente verificado. Uma nova distribuição usará sequência maior que 5.
5. Manter a Central compatível enquanto houver companion 0.6.4. O compose anterior
   está em `backups/agent064-publication-20261110/compose.before.yaml`; seu retorno
   só deve ocorrer após recuperar os Agents que dependem da versão nova. Não
   restaurar dump antigo sobre o banco vivo, apagar histórico ou executar
   `migrate:rollback` para desfazer esta publicação.

Nenhum rollback foi necessário no Agent operacional. Os cenários destrutivos ou
de interrupção foram confinados ao laboratório.

## Limites da evidência

A janela de observação mede estabilidade no período observado; não prova ausência
permanente de falhas de rede. O timeout/logging do companion agora está instalado,
mas a causa de infraestrutura da instabilidade histórica não foi demonstrada.
A falha geral anterior de interface `AuditTimezoneTest` não foi alterada por esta
publicação; a imagem existente de interface foi preservada.
