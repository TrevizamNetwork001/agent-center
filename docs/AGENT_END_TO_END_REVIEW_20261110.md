# Revisão de ponta a ponta do Agent — 10/11/2026

## Conclusão e estado da entrega

A separação estrutural é adequada: Central Laravel/PostgreSQL, Agent sem root,
worker de execução isolado, journal local e atualizador privilegiado separado.
Foram encontradas falhas reais em caminhos de erro, limites e recuperação. As
correções estão na fonte e passaram nos testes; **os binários da VM continuam
0.6.2 e não receberam essas correções nesta auditoria**.

O fluxo real existente foi validado por um diagnóstico DNS criado pela interface
HTTP protegida da Central e executado pelo Agent cadastrado. O novo pacote da
fonte também foi validado em laboratório com seu worker empacotado real.

## Escopo e rastreabilidade

- Revisão dos 36 módulos Python: startup, configuração, identidade, TLS/API,
  heartbeat/poll, autorização, execução/cancelamento, journal/reconciliação,
  capabilities, DNS UDP/TCP, MTR/socket, telemetria, observabilidade, assinaturas,
  atualização, componentes, backup, rollback e empacotamento; leitura das units
  systemd, instalador e fronteiras Laravel correspondentes.
- Baseline Git: `a3b091b667f56ee06751ce5310f2918ab24e8c1e`.
- VM `teste`: acesso cadastrado válido, allowlist, Gate administrativo,
  fingerprint antes da credencial e identidade local igual ao Agent cadastrado.
- Comparação por hash dos módulos e AST de onze funções relevantes nos pacotes
  instalados. As onze implementações coincidem com o baseline Git, incluindo
  funções onde os testes novos reproduziram os defeitos. A comparação não afirma
  que a árvore inteira 0.6.3 seja idêntica ao pacote 0.6.2.
- [Comparação de fonte e referência](evidence/agent-end-to-end-20261110/source-comparison.json).

## Referência indicada pelo usuário

Foi lida a pasta [agent do DNS Center](https://github.com/TrevizamNetwork001/painel-para-dns-autoritativo/tree/0ba3b7a82a90f2726183ef144e3cba5918e2db3d/agent),
fixada no commit `0ba3b7a82a90f2726183ef144e3cba5918e2db3d`. O arquivo
`dns-center-agent.py` dessa revisão declara 0.15.0.

A comparação considerou locks, estado durável, reports pendentes, autodiagnóstico,
timers separados, atualização e restauração após falha. O DNS Center usa serviços
oneshot e um processo de operações root para administrar BIND; o Agent Center usa
runtime persistente sem root e helper MTR separado. Foram preservados os limites
e a assinatura Ed25519 do Agent Center. Nenhum arquivo da referência foi executado,
copiado para o runtime ou usado para alterar BIND, zonas ou equipamentos.

## Falhas reproduzidas e corrigidas na fonte

| Prioridade | Achado e impacto | Correção e validação |
| --- | --- | --- |
| Alta | O executor esperava o worker terminar antes de ler stdout. Uma saída válida de 32 KiB bloqueava em pipe de 4 KiB e virava `execution_timeout`. | Drenagem não bloqueante durante a execução, preservando o teto de 64 KiB. Testes com saída válida e saída excedente. |
| Alta | Descendentes podiam sobreviver quando o worker pai já havia terminado. | Encerramento do grupo da execução mesmo após a saída do líder; cleanup antes do envio do resultado e controle para evitar kill tardio após finalização. Teste com descendente real e watchdog independente de renew bloqueado. |
| Alta | Shutdown ainda podia aceitar outra tarefa; cancelamento recebido durante START podia ser seguido por spawn do worker. | Recusa antes de reservar durante shutdown e nova checagem após o ACK de START. Resultado cancelado entregue sem iniciar processo. |
| Alta | Falhas de stop, instance lock ou backup no `RuntimeAdapter.snapshot` podiam deixar o Agent parado. O restart após falha de backup também ocorria ainda segurando o lock do próprio Agent. | Recuperação do serviço anteriormente ativo, liberação do lock antes de reiniciar e preservação do estado anteriormente inativo. Quatro cenários de recuperação passaram. O relatório assinado também deixou de declarar backup completo quando o snapshot falha antes de produzi-lo; regressão específica aprovada. |
| Média | O self-check de seis capabilities consultava o helper MTR antes de excluí-lo. A espera opcional podia exceder o prazo do próprio self-check do updater. | Exclusão do MTR antes da consulta de disponibilidade. Teste assegura que o helper opcional não é contatado. |
| Média | JSON excessivamente aninhado escapava como `RecursionError` em payload, resposta da API e estado privado. | Falhas convertidas em `invalid_payload`, `invalid_response` e `StateError`. Casos reais de parsing/serialização dentro dos limites de tamanho foram testados. |
| Média | `True` ou `1.0` eram aceitos como `task_protocol=1`; flags numéricas eram aceitas como booleanos. | Tipos estritos para `task_protocol` e `tasks_enabled`, mantendo os valores válidos enviados pela Central. |
| Média | O parser DNS UDP/TCP incluía endereços de owners alheios à pergunta e ignorava loops/conflitos de CNAME. | Respostas limitadas ao nome consultado e à sua cadeia CNAME validada, inclusive fora de ordem. Loops e aliases conflitantes são recusados. Nenhuma autoridade de rede é derivada das respostas. |
| Alta de conformidade | A CLI legada permitia a root aplicar/retornar um arquivo somente com seu SHA, fora da operação assinada do Lifecycle. Não era uma rota remota pública. | `--apply` recusado na CLI; dry-run preservado. O Engine continua disponível internamente para o Lifecycle autenticado. Testes para update/rollback como root e sem root. |
| Média de endurecimento | O entrypoint do companion não validava seu namespace privado e podia iniciar sem a raiz de confiança necessária ao fluxo assinado. | Diretório de controle root e privado verificado; chave de confiança obrigatória antes de carregar credenciais ou consultar operações. Testes asseguram ausência de transporte nesse caso. |

Adicionalmente, `Service.active()` recebeu timeout de oito segundos para não
aguardar indefinidamente o systemd. A documentação do Manager foi alinhada à
integração já existente com o RuntimeAdapter. O patch anterior de logs seguros e
timeout de dez segundos do companion foi preservado.

## Validação automatizada e laboratório

| Verificação | Resultado |
| --- | --- |
| Python, usuário `nobody`, código revisado | **178 testes aprovados**, 23 a mais que o baseline de 155 |
| Laravel — Agent, API, auth, autorização, RLS, diagnósticos, releases e quarentena | **172 testes / 1.361 assertions aprovados** |
| Agent API com fixture adicional de concorrência em processos separados | **8 testes / 203 assertions**, mais fixture aprovada |
| Concorrência | Claim exclusivo, worker/result concorrentes, fencing após expiração, resultado duplicado idempotente e pré-requisitos DNS preservados |
| PostgreSQL descartável | Migrations Laravel up/down aprovadas nas rodadas focadas; banco de teste criado e removido pelo executor do projeto |
| Sintaxe | 36 módulos compilados; script de laboratório validado; `git diff --check` aprovado |

A suíte Feature ampla foi executada: **260 testes / 2.011 assertions, uma falha**
em `AuditTimezoneTest`. O teste ainda espera a string literal
`America/Sao_Paulo`, enquanto a view de auditoria previamente modificada apresenta
o nome localizado e o offset. A falha foi registrada; esses arquivos de interface
não foram alterados por esta auditoria. Portanto, não se declara a suíte geral
inteiramente aprovada.

O [laboratório empacotado](../scripts/lab/agent-runtime-e2e.py) constrói o zipapp
duas vezes, exige bytes iguais, executa o pacote e seu worker real como usuário
sem privilégios, completa uma tarefa `agent_info` em fixture restrita a loopback,
confirma ACK durável, journal, remoção da lease, heartbeat, poll, freeze e shutdown.
Usa somente identidades fictícias e não lê credenciais da instalação real.

Resultado: um START, um RESULT, quatro eventos e nenhuma credencial na saída.
O pacote de laboratório declara 0.6.3 porque essa é a versão da fonte preparada;
**não é a release 0.6.3/seq 4 em quarentena e não foi assinado ou publicado**.

- SHA-256 do pacote de laboratório: `b795e70bbc6426daaaeb8fa78acbd0229aa577c6d229fdc9dad0e01819cddcb0`.
- SHA-256 da fonte: `c2d30eba91e3ecec3a06628a975c4695bc3065dc0fd6314fb9108d05a6457ec5`.
- [Resultado do laboratório](evidence/agent-end-to-end-20261110/packaged-lab.json),
  [testes Python](evidence/agent-end-to-end-20261110/python-tests.log) e
  [resumo Laravel/concorrência](evidence/agent-end-to-end-20261110/validation-summary.json).

## Ponta a ponta real na instalação cadastrada

Foi utilizado apenas o profile existente `PROBLEMA_DNS`, com plano previamente
conferido para conter exclusivamente duas a quatro etapas `dns_lookup:v1`, skill
já habilitada e segura, sem operações pendentes no Agent. A criação ocorreu por
HTTPS em `/diagnostics`, com Auth, sessão, CSRF, Policy e tenant nativos. Não foi
inserida uma tarefa diretamente no banco nem alterado o catálogo.

- Alvo: domínio da própria Central, `agente.trevizamnetwork.com.br`.
- Diagnóstico: `1545628a-bc11-4fe4-8988-2ad1534b3770`.
- Resultado: **COMPLETED / NORMAL**.
- Etapas A, AAAA, DNS UDP e DNS TCP: todas COMPLETED, cada uma com resposta válida.
- Quatro tarefas, quatro attempts, todos generation 1 e com digest do resultado.
- Journal: **87 → 91 execuções**, **348 → 364 eventos**, quick_check aprovado,
  zero pendências. O incremento corresponde às quatro novas execuções.
- Sessão de validação removida; arquivo temporário com cookie/CSRF removido.
- Diagnóstico e evidências foram mantidos no histórico normal para auditoria.

As consultas DNS usaram os resolvers permitidos pelo Agent. Não houve MTR ativo,
ping/traceroute externo, captura, varredura, alteração de zona ou acesso operacional
a equipamento. Os demais executores foram exercitados nas fixtures locais.

[Resultado real sanitizado](evidence/agent-end-to-end-20261110/live-dns-e2e.json)
e [avaliação dos critérios](evidence/agent-end-to-end-20261110/live-evaluation.json).

## Conferência nativa e preservação

Debian 13 / Python 3.13.5 observados. Principal `agent-center-agent` sem root,
CapabilityBoundingSet e AmbientCapabilities vazios, NoNewPrivileges e
ProtectSystem=strict. Helper MTR em usuário separado, somente CAP_NET_RAW e
NoNewPrivileges. O socket ativo e o template do helper foram distinguidos dos
estados transitórios das instâncias de serviço.

Identidade/configuração 0600, estado 0700, controle do updater root/0700. A chave
pública está root/0600, uma permissão válida e mais restrita que 0644. A identidade
corresponde ao cadastro. Self-check nativo aprovou seis capabilities e freeze.

Runtime, companion e CLI legada permanecem 0.6.2. Os hashes monitorados de runtime,
companion e helper são os mesmos antes e depois do diagnóstico. Não houve troca
de binário, reinício de serviço, migration de produção ou mudança de privilégios
nesta auditoria. O timeout de dez segundos aplicado anteriormente ao runtime foi
mantido. O contador histórico do Lifecycle permanece **4**; sua versão 0.6.3
registrada representa a instalação histórica seguida de rollback, não o runtime
ativo atual. Não se reduziu contador nem se retirou quarentena.

[Coleta anterior](evidence/agent-end-to-end-20261110/native-before.jsonl) e
[coleta final](evidence/agent-end-to-end-20261110/native-after.jsonl).

## Pendências para uma liberação futura

1. As correções precisam de releases assinadas e compatíveis. A Central publicada
   ainda aceita versões até 0.6.2. Não reutilizar a release 0.6.3/seq 4 nem alterar
   seu conteúdo para tentar distribuir este patch.
2. O Lifecycle atual troca runtime e MTR; não substitui `remote-updater.pyz` nem
   `updater.pyz`. Assim, publicar apenas o runtime não corrige o snapshot usado
   pelo companion nem a CLI instalada. Falta suportar a distribuição assinada e
   o rollback desses componentes no fluxo existente.
3. A falha intermitente de transporte do companion permanece aberta. O teste DNS
   bem-sucedido e os testes de código não comprovam resolução dessa falha.
4. Antes de liberar Observability 0.6.3, revisar o orçamento de tempo das consultas
   executadas no thread de heartbeat: resolução DNS da inspeção não tem deadline
   próprio e o socket de observação pode aguardar 19 s. Esse caminho novo não está
   instalado na VM e não foi homologado em produção por este teste.
5. O anúncio de capabilities é mantido após a inicialização; recuperação tardia
   de uma dependência opcional requer estratégia de reanúncio. A VM estava com
   as dependências disponíveis durante a conferência, sem reproduzir uma falha
   de cold start desse tipo nesta rodada.

Para manutenção do código, o módulo `update/remote.py` concentra transporte,
staging, backup e orquestração. Separar essas responsabilidades e a primitiva de
confiança de arquivos pode reduzir acoplamento, preservando o protocolo e os
testes. A revisão não converteu a Central em Python nem mudou a arquitetura para
o modelo root/oneshot da referência.

## Rollback e integridade do trabalho anterior

As alterações de fonte são reversíveis pelo
[patch exclusivo desta auditoria](evidence/agent-end-to-end-20261110/audit-only.patch).
A checagem reversa com `git apply --reverse --check` passou. O patch preserva os
logs e o timeout do companion preparados antes desta auditoria, os seis testes
anteriores do updater e as alterações preexistentes de interface/documentação.
Antes de efetuar reversão, repetir a checagem e preservar qualquer mudança posterior.

Não há rollback de runtime ou banco a executar nesta entrega: nenhum pacote foi
instalado. O diagnóstico real já terminou; seus registros não devem ser apagados.
Se uma validação desse tipo precisar ser interrompida, usar o cancelamento nativo
da própria execução e manter as evidências. Uma futura distribuição de software
precisará de backup/rollback próprios pelo Lifecycle, preservando identidade,
journal, contador e dados posteriores.
