# Agent/helper MTR 0.6.6 alinhados — 18/11/2026

## Resultado e autorização

O usuário autorizou alinhar Agent/helper por atualização assinada pelo Lifecycle, com laboratório, backup e rollback. Escopo único: tenant 1, VM teste, Agent `c88457e9-748f-4906-982a-166d0ee0f6d3`.

Operação **fc12e6ee-b46d-484f-8915-bf21dd0301b6**: **COMPLETED**, postcheck=true, sem erro, freeze preservado. Agent/helper **0.6.6**, sequência instalada **11**.

O preflight real posterior retornou **ALLOWED/authorized** para **8.8.8.8, ICMP, revisão 5**, com Agent/helper 0.6.6 e helper_healthy=true. Request `d7ed15b7-5e92-427a-969c-09f47842db9d`, recebido no epoch `1795028144`. É uma prova pontual; cada pedido continua exigindo autorização fresca e confirmação.

Tasks/diagnósticos permaneceram **105/30**. Nenhum MTR/tracert real, mensagem sintética, chamada LLM ou acesso a equipamento físico foi executado. A inclusão anterior de 8.8.8.8/32 e google.com foi preservada.

## Artefato e entrega

Base: artefato original assinado 0.6.6 da sequência 10, já instalado. Todos os membros internos do ZIP, incluindo código e dependências, foram preservados byte a byte. O comentário/invólucro distingue o digest no catálogo; o manifesto acrescenta o componente MTR pelo mecanismo existente. Não foi promovido agent/src 0.6.4 nem criado outro updater.

- Revisão: 0.6.6, sequência 11, protocolo de pacote 3, ponte 9 existente.
- Tamanho: 6.741.428 bytes.
- SHA-256 runtime: `a69db43731e3fd2af46dcd07c628289ebb6426d43cf51fca2ce4b33c3f8a3d97`.
- SHA-256 helper: `d0dbbfb5e228f06def3faf438d978aebbb6b7406081dfd70bd72110741f3c534`.
- SHA-256 da chave pública raw existente: `5f16350383c280c1b39d4f7257f09ecbe1002b1d59a1721ceb8bf4d5091195e1`.

ReleaseController validou assinatura Ed25519, hash, tamanho, sequência, tenant e Gate nativos. UpdateController criou a operação **sem aprovação antecipada**. O timer da VM baixou por HTTPS e concluiu o precheck real em PLANNED, com SHA correto/freeze=true. Somente depois da revisão desse resultado foi usada a aprovação nativa, sob autorização do usuário. O Lifecycle aplicou e concluiu o pós-teste real.

SFTP com allowlist/pin validado antes da credencial foi usado somente para inspeção independente, sem exec, put ou instalação. O envelope administrativo já permitia mtr/network-raw-helper; não foi ampliado. Não houve alteração de privilégios, ACLs, credenciais, RBAC/RLS, migrations ou imagens da Central nesta atualização.

## Validação

- **202 testes Python PASS**, Python 3.13 e fontes candidatas 0.6.6 em container isolado. O runner inicial omitia packaging/cwd/mountpoint; foi corrigido no laboratório e repetido integralmente, sem relaxar testes ou código.
- Laboratório com artefatos e assinaturas finais: Manager instala componente, falha de health restaura runtime/helper, identidade/journal preservados, idempotência, adulteração/replay recusados e downgrade de componente recusado. Filesystem real; fronteira de serviço simulada, explicitamente. Não é E2E systemd real.
- Contrato do helper 0.6.6 aceita o CIDR 8.8.8.8/32 e recusa 8.8.4.4 em fixture; raw_ready simulado e nenhuma sonda. A saúde real foi comprovada depois na VM.
- Pré e pós-verificação **reais** do Lifecycle na VM: PASS.
- Inspeção independente confirma helper 0.6.6 e hash do manifesto. Base, teto, envelope, unidades/socket, check-helper e drop-in conservaram hashes, owners e modos.
- Backup nativo comparado ao estado atual: identidade/config iguais; runtime anterior e helper anterior íntegros; arquivo principal do journal com mesmo hash do snapshot. Nenhum journal antigo restaurado.
- Central healthy; TLS positivo/negativo, PDO verify-full, fila técnica, diagnostic-worker e assets PASS. `git diff --check` PASS.

## Backup e rollback

Backup completo da Central: **20261118T185202Z**, sem prune, integridade aprovada. Um intento anterior não leu o staging por permissões; somente o staging privado foi corrigido e uma nova geração completa foi realizada. Não houve replicação externa nesta ação.

Backup transacional da VM: `/var/lib/agent-center-updater/backups/fc12e6ee-b46d-484f-8915-bf21dd0301b6/`, incluindo runtime, config, identidade, journal, companions e componente. O rollback automático durante falha da transação foi provado no laboratório e não precisou ser acionado na VM. Ele preserva identidade/journal vivos.

**Após commit, a proteção antirrebaixamento permanece ativa.** Um pacote 0.6.5/seq12 foi preparado somente como fixture negativa e recusado com `component_downgrade`; não foi publicado, não é rollback operacional aprovado e não deve ser aplicado. Assinatura e sequência maior não autorizam downgrade de componente. Recuperação posterior exige protocolo/escopo de recuperação conjunta; não usar rollback legado só do runtime, editar contadores ou copiar helper por SFTP.

Artefatos, snapshots e logs restritos fora do Git em `backups/mtr-align-production-20261118/`; logs locais também em `/tmp/agent-center-mtr-align-*.log`. Não versionar dumps, chaves ou estado persistente.
