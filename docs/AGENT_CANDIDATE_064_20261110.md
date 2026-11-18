# Agent 0.6.4 — preparação de candidato, 10/11/2026

O candidato local 0.6.4 contém as correções da auditoria anterior e as correções
adicionais abaixo. Não foi publicado, assinado com a chave de produção ou instalado.
A execução desta etapa não reiniciou o Agent real nem alterou seu contador,
identidade, journal, permissões ou serviços.

## Correções adicionais

- Inspeção de observabilidade e verificação de permissões passaram para um worker
  Python separado, sem root, de entrada e operações fixas (`snapshot`/`check`).
  O heartbeat limita cada chamada a `min(2 segundos, intervalo / 4)`; o prazo inclui
  DNS e helper. No timeout, encerra o grupo do worker e devolve `UNKNOWN`, sem
  autorizar execução. Não usa shell e não recebe credencial da Central.
- O entrypoint empacotado suporta esse worker com `python -I`; o laboratório
  conferiu que o worker real do pacote produziu observabilidade no heartbeat.
- A verificação de readiness do helper usa orçamento de um segundo, incluindo
  conexão, envio e leitura. A coleta MTR mantém seu orçamento independente.
- Capabilities são reavaliadas a cada 60 segundos e precisam ser anunciadas de
  novo quando mudam. Isso cobre retorno e perda de dependências após o boot.
  A capacidade de claim permanece zero enquanto o anúncio não é confirmado.
- A fonte Laravel passou a aceitar 0.6.4. Não houve deploy da Central publicada.
  O teste novo confirma heartbeat com observação `STATUS_UNKNOWN`, device freeze,
  rejeição de tenant injetado e leitura negada a outro tenant por RLS.

## Pacote e assinatura

[prepare-agent-candidate.py](../scripts/prepare-agent-candidate.py) prepara runtime,
companion, CLI e helper com a mesma fonte e build determinístico. Exige diretório
novo, recusa sobrescrita e sequência que não supere o mínimo informado. A versão
0.6.3 está reservada e não pode ser usada por essa preparação.

O candidato está em `artifacts/agent-candidates/0.6.4-seq5`. A sequência 5 é uma
proposta baseada no último contador observado (4); antes da publicação é necessário
revalidar os contadores e releases existentes. A ferramenta não reserva sequência
na Central nem avança o contador do Agent.

- Fonte SHA-256: `5ef7bd83b9339376edd9d43be56aa25fade54aef6364b3d8eecc249389a6d61c`.
- Runtime SHA-256: `eb8e7eddcaabebc3e0a71a63ff3af452b304423cebfad79493cf3c5db2cd57fe`.
- [Hashes dos quatro pacotes e pendências](evidence/agent-candidate-064-20261110/candidate-report.json).

A ferramenta aceita assinatura Ed25519 offline com chave privada restrita fora do
repositório e verifica a assinatura pelo verificador existente do Lifecycle.
Nesta etapa, a assinatura foi testada somente com chave descartável de laboratório;
o candidato persistido permanece sem assinatura. A ferramenta nunca presume que
uma chave informada seja a trust anchor instalada em produção.

A assinatura do manifest existente autentica o runtime e o hash do helper MTR.
Companion e CLI compartilham os mesmos módulos autenticáveis, com entrypoints fixos,
mas seus arquivos irmãos não estão individualmente assinados nesse formato.
Uma futura distribuição deve derivá-los do runtime verificado usando catálogo
local fixo, ou evoluir o manifest assinado e seus validadores. Não instalar os
arquivos irmãos confiando apenas no relatório JSON de hashes.

## Validação

| Verificação | Resultado |
|---|---|
| Suíte Python completa, usuário `nobody`, versão 0.6.4 | 182 testes aprovados |
| Preparação e assinatura com chave descartável | 2 testes aprovados; adulteração, replay e sobrescrita recusados |
| Runtime e workers reais empacotados, fixture loopback | Aprovados; uma execução, um resultado, quatro eventos, lease removida e freeze preservado |
| Laravel: API, observabilidade, assinatura, quarentena e isolamento | 37 testes / 340 assertions aprovados em PostgreSQL isolado; migrations up/down |
| Integridade | Hash do runtime do candidato igual ao testado no laboratório; quatro builds repetidos idênticos |
| Rollback local e whitespace | Checagem reversa do patch e `git diff --check` aprovados |

As evidências estão em [agent-candidate-064-20261110](evidence/agent-candidate-064-20261110/).
Não foram repetidos os testes gerais de interface: a falha anterior de
`AuditTimezoneTest` continua fora do escopo desta etapa. Os quatro DNS reais
aprovados pertencem à auditoria anterior do Agent 0.6.2, não são uma homologação
nativa deste candidato.

## Condições ainda necessárias para produção

1. Implementar e homologar entrega assinada e rollback do companion/CLI no
   Lifecycle. O adapter atual troca runtime e MTR e não troca esses dois arquivos.
   O companion antigo também não ganha código novo ao trocar apenas o runtime;
   a transição inicial precisa ser tratada explicitamente. Não foi introduzido
   instalador remoto por SSH para contornar isso.
2. Homologar instalação, falhas, interrupção e rollback em Debian 13/systemd real,
   incluindo conta sem root, helper, root companion e preservação de dados novos.
   O laboratório loopback executado não fornece essa homologação.
3. Confirmar trust anchor, sequência e compatibilidade da Central realmente
   publicada; assinar o conteúdo final no ambiente de assinatura autorizado.
4. Observar transporte do companion instalado após distribuição. A instabilidade
   histórica de transporte ainda não tem causa comprovada; timeout maior e logs
   sanitizados não demonstram que a infraestrutura foi corrigida.
5. Somente com as condições acima atendidas, submeter o pacote e rollback
   concretos à autorização operacional específica exigida no AGENTS.md para
   deploy da Central e atualização do Agent real.

O campo `production_ready` permanece `false`; a ferramenta não altera catálogo,
status de release ou quarentena, não publica arquivos e não instala nada.

## Rollback local

[Patch exclusivo desta preparação](evidence/agent-candidate-064-20261110/preparation-only.patch).
Antes de reverter, repetir `git apply --reverse --check` e preservar mudanças
posteriores. Esse patch retorna à fonte revisada 0.6.3, preserva as correções da
primeira auditoria e o trabalho anterior de UI, updater, configuração e documentos.
Os arquivos de candidato podem ser arquivados; não remover releases publicadas ou
quarentenas. A auditoria anterior descreve seu rollback separado; executar primeiro
a reversão desta preparação se for necessário retornar também àquela etapa.
Não há rollback de produção nesta entrega, pois não houve deploy.
