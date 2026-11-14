# MikroTik Read-Only Operational Lab V1

Resultado: **MIKROTIK READ-ONLY OPERATIONAL LAB BLOCKED**.

Esta execução entregou código parcial, recuperação verificável de fontes,
ferramentas de empacotamento, um candidato separado e provas de inicialização
Debian 13/systemd. Não entregou a integração operacional completa nem habilitou
acesso físico. O resultado anterior do laboratório 0.6.4 não valida este candidato.

## Implementação e origem

Commit de código: `995e93b`.

- `agent/candidates/routeros-operational-v1/base/`: 38 arquivos Python exatos da
  release operacional 0.6.5, sequência 6, recuperados do arquivo local assinado.
- `provenance.json`: assinatura validada pelo verificador nativo do Lifecycle,
  hashes individuais e comparação com o workspace (26 iguais, 11 diferentes e
  `preflight.py` ausente localmente). O arquivo `__main__.py` tem hash registrado,
  mas não foi copiado para a árvore de módulos.
- Artefato original: SHA-256
  `a2de84beb6cb519047c6123423fb420d9d410b41b85c6c9b4a49df63b562d315`.
- Âncora pública Ed25519, hash dos 32 bytes:
  `5f16350383c280c1b39d4f7257f09ecbe1002b1d59a1721ceb8bf4d5091195e1`.
  Ambos coincidem com os registros históricos locais. Nenhuma chave privada
  operacional foi usada. As sequências em quarentena 3 e 4 não foram reutilizadas.
- `src/`: candidato 0.6.6 derivado dessa base. Preserva todos os demais fontes
  byte a byte, incluindo Preflight 0.6.5, heartbeat, journal, execução, políticas,
  helpers e Lifecycle. As três alterações revisadas estão no identificador de
  versão, dispatcher/self-check e tratamento de JSON recursivo na API. O adaptador
  RouterOS existente foi adicionado ao dispatcher, conservando seus bloqueios.
- A árvore local `agent/src` com alterações preexistentes não foi sobrescrita.
  O candidato não constitui outra Central, executor ou mecanismo de tarefas.

## Dependências e Debian 13 comprovados

O lock fixa Paramiko 3.5.1, cryptography 46.0.3, bcrypt 5.0.0, PyNaCl 1.6.0,
cffi 2.0.0 e pycparser 2.23 para Python 3.13 / amd64, com hashes de wheels,
requisitos Python e hashes dos arquivos de licença. As declarações dos próprios
pacotes estão em `docs/evidence/mikrotik-operational-v1/licenses.json`; revisão de
vulnerabilidades e obrigações de distribuição não foi concluída.

`fetch-wheels.sh` usa downloads com `--require-hashes`; `bundle.py` valida conjunto
fechado, versões, licenças e correspondência ao lock. Não executa instalação.
Duas construções independentes produziram o TAR idêntico:
`8a15ced87e8900f986a28da78ba62ed2293ca01d1d7d7eca0444dd2beb6cc942`.
O bundle está fora do Git em `/tmp/routeros-operational-v1/ssh-dependencies.tar` e
pode ser reproduzido pelo tooling. Os seis wheels somam 6.726.567 bytes.

Instalação offline dos wheels em venv temporário, `--no-index --no-deps
--require-hashes`: aprovada em Debian 13, Python 3.13.5. Importação real dos seis
pacotes e self-check do candidato executados por unit systemd como UID/GID 65534,
NoNewPrivileges=true, conjuntos effective/bounding/ambient vazios e PrivateDevices.
O probe foi repetido após as alterações finais. Systemd 257.13 é PID 1 real.
A primeira tentativa de consultar a versão usou um caminho inexistente; esse
erro está preservado separadamente e a versão foi obtida depois pelo binário
`/lib/systemd/systemd` da mesma imagem.

O contêiner de init usou modo privilegiado exclusivamente laboratorial, cgroup
privado, rootfs somente leitura, sem bind mounts do host e rede `none`. Units de
hardware/sysctl foram mascaradas apenas na imagem de laboratório. Inicialização
sem acesso ao cgroup falhou antes desse ajuste; isso não comprova equivalência
integral ao sandbox da VM operacional. A unit executou **somente imports e
self-check**, sem configuração operacional, credencial, heartbeat, polling ou SSH.

## Bloqueios concretos restantes

| Exigência | Estado desta execução |
| --- | --- |
| Fonte operacional 0.6.5 verificável | Recuperada e validada criptograficamente; reconciliação funcional ainda incompleta |
| Destinos físicos previamente autorizados | Não implementados; transporte continua exclusivamente no simulador/loopback e namespace de laboratório |
| Distribuição SSH pelo Lifecycle | Não implementada; `components.specification()` aceita somente MTR e rejeita `routeros-ssh` com `unsupported_component`; o validador de runtime aceita somente arquivos Python |
| Instalação/rollback transacional do bundle | Não implementados nem homologados; instalação do venv temporário não substitui esse critério |
| Compatibilidade com os módulos locais de observabilidade | Falha: `observability` e `observation_worker` ausentes na base assinada; precisam de reconciliação aditiva preservando o Preflight 0.6.5 |
| Empacotamento do candidato | Falha: teste usa builder local 0.6.4 e detecta divergência com 0.6.6; não há builder específico final homologado |
| Negociação Central/Agent 0.6.6 | Não preparada; nenhuma alteração da Central nesta execução |
| Seis operações Task Engine/API/SSH em Debian 13/systemd | Não executadas neste candidato |
| Atualização, postcheck, rollback e recuperação após reinício | Não homologados neste candidato |
| Assinatura e manifesto da nova release | Não produzidos; não reutilizada assinatura de artefato anterior |

Não foi criada flag para liberar device freeze ou aceitar SSH arbitrário. Não
foram alterados R2, catálogo, units/permissões da VM, ACLs ou equipamentos. Nenhuma
conexão ao MikroTik físico foi iniciada. O adaptador continua vinculado às fixtures
RouterOS 7.16.2; compatibilidade com modelo/versão físicos não está comprovada.

## Validação realizada

| Verificação | Resultado |
| --- | --- |
| Laravel/PostgreSQL, `scripts/test.sh` | 588 testes, 5.150 asserções, 21 skipped, sem falhas; rollback das migrations no banco descartável concluído |
| Python do workspace local | 190 testes PASS; não são 190 testes aprovados do candidato |
| Python contra candidato reconciliado | 173 testes coletados; 1 falha de versão/empacotamento e 2 erros de importação de observabilidade; resultado FAIL |
| Proveniência/preservação/freeze/lock/contrato Lifecycle | 7 testes PASS, incluindo execução em Python 3.13 |
| JSON profundo e self-check sem helper opcional | 5 testes direcionados PASS após correção |
| Pint | PASS, 380 arquivos |
| Ruff | PASS nos arquivos novos/alterados verificados e na suíte `agent/src`/`agent/tests` com configuração do Agent; base arquivada não reformatada |
| Build inicial com node_modules existente | FAIL: binding nativo Rolldown ausente, problema de instalação local |
| Secret scan | PASS, índice Git, scanner básico do repositório |
| Diff check | PASS após normalização de whitespace nos transcripts de evidência |
| Build limpo | PASS com `npm ci --include=optional` em cópia temporária, mesmo package-lock; diretório de trabalho não alterado |

Os skips do Laravel incluem suítes condicionadas aos laboratórios específicos.
Não equivalem a aprovação de E2E SSH, concorrência, RLS entre processos ou de todas
as negações exigidas para o novo candidato. As falhas do candidato são limitações
desta entrega, não foram classificadas como regressões preexistentes aprovadas.
Logs e evidências reais estão em `docs/evidence/mikrotik-operational-v1/`.

## Rollback e autorização futura

Não houve implantação: rollback operacional é desnecessário. Descartar apenas os
contêineres nominados deste laboratório, bundles/venv temporários e candidato
isolado. Nenhuma identidade ou journal operacional foi substituído. Não executar
revert amplo: o workspace contém alterações anteriores alheias a esta missão.

O rollback de dependências pelo Lifecycle continua sendo um requisito bloqueante.
Antes da homologação física será necessário completar os itens da tabela,
produzir nova assinatura laboratorial sobre artefatos finais e obter autorização
operacional específica: tenant, usuário operador, equipment_id, modelo/versão,
endereço de gerenciamento, Agent/origem, fingerprint aprovado, conta dedicada e
referência tenant-scoped no cofre, somente DEVICE_IDENTITY_READ, janela, quantidade
máxima de consultas, timeout, limite de saída e critérios de interrupção. As
outras cinco capabilities deverão permanecer negadas para o equipamento físico.
Não criar conta no roteador ou alterar ACL sem autorização separada.
