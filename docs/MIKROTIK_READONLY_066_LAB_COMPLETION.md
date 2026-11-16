# MikroTik Read-Only 0.6.6 — conclusão do laboratório

**MIKROTIK READ-ONLY 0.6.6 OPERATIONAL LAB PASS** — 2026-11-16.

Código: `c6935a7`. Candidata isolada: `agent/candidates/routeros-operational-v1`.
Nenhuma release operacional foi publicada, nenhum Agent/VM operacional foi atualizado,
e nenhum equipamento real foi acessado. O freeze continua obrigatório.

## Causas e correções dos três bloqueios

1. **Suíte: 173 testes, uma falha e dois erros, reproduzidos exatamente.**
   `test_update` importava a versão 0.6.6, mas executava o builder local 0.6.4.
   O candidato ganhou builder próprio; o runner usa os mesmos testes, intactos,
   em uma árvore que resolve builder e fonte para a candidata. Os dois erros eram
   módulos ausentes: `observability` e `observation_worker`. A reconciliação aditiva
   também conservou snapshot da configuração, autorização compartilhada com o
   executor, refresh limitado de capabilities e inspeção do helper MTR.
   O `preflight.py` recuperado continua idêntico byte a byte; o heartbeat conserva
   `diagnostics/preflight`, e o helper conserva o protocolo `preflight` original.
   Resultado final: **190 testes Python, zero falhas, erros ou skips**.

2. **O Lifecycle só admitia código Python e não distribuía SSH.**
   Os seis wheels fixados foram incorporados ao zipapp assinado, com inventário
   fechado e hashes locais no catálogo. O Engine existente valida e materializa
   as dependências offline em `runtime/ssh`, antes do self-check e do swap.
   O staging de downloads do companion usa esse mesmo precheck, conservando a
   verificação de assinatura, versão e autorização do catálogo. Não há pip,
   resolução dinâmica, scripts de instalação, dependências SSH globais ou outro
   updater. O schema remoto de componentes continua fechado.
   O runtime e SSH participam do mesmo rename/rollback. A interrupção real revelou
   um staging residual, corrigido com limpeza limitada sob locks. A revisão com
   `umask 077` revelou diretórios intermediários sem leitura para o Agent; a
   materialização agora fixa diretórios em 0755 e arquivos em 0644.

3. **O teste anterior só comprovava imports/self-check em systemd.**
   O runner existente agora inicia o Agent não-root em systemd real e executa
   o bootstrap assinado 0.6.5, update para 0.6.6, postcheck real de heartbeat/poll,
   as seis consultas, reinício e rollback na **mesma instância**. As verificações
   finais acontecem antes de o runner descartar o PostgreSQL da fixture.

## Pacote e compatibilidade

- SHA-256: `4f13dfe7e9cc6c073e44bf1461969639bd05f390ad871e39526da7e992622aa8`.
- Fonte Python agregada: `8e031015f3ebab627e92a90e44d0119adbd034352c8b58bcf77cb24a2bfe855f`.
- Duas construções produziram o mesmo zipapp. Manifesto, assinatura Ed25519 e
  chave **pública de laboratório** estão nas evidências. A chave privada está
  fora do bundle/Git, modo 0600. Sequência 2 pertence somente ao domínio de
  confiança descartável; não representa reserva de sequência operacional.
- Debian 13, x86_64, Python 3.13.5, systemd 257.13 como PID 1.
- Paramiko 3.5.1, cryptography 46.0.3, bcrypt 5.0.0, PyNaCl 1.6.0,
  cffi 2.0.0 e pycparser 2.23, com hashes e licenças do lock preservados.
- Outros sistemas, arquiteturas e Python fora de 3.13 são recusados para SSH.
  O bootstrap verifica versões, bytes e permissões antes de importar as bibliotecas.

O pacote permanece somente em `/tmp/routeros066-umask-final-release/agent.pyz`;
não foi enviado a R2 nem registrado no catálogo operacional.

## E2E e rollback comprovados

Fluxo executado: Laravel / Conversation + Preflight → Task Engine → Agent API
HTTP autenticada no loopback isolado → Python Agent 0.6.6 / Executor / worker /
journal → adaptador RouterOS 7 → SSH simulado → evidência estruturada → Laravel
→ HTML autenticado e ingress/outbox nativos Telegram. O transporte Telegram usa
HTTP fake; nenhuma conexão à API externa Telegram foi realizada.

As operações efetivamente consultadas pelo Agent foram:
`DEVICE_IDENTITY_READ`, `DEVICE_BGP_PEERS_READ`, `DEVICE_ROUTE_LOOKUP_READ`,
`DEVICE_INTERFACES_READ`, `DEVICE_INTERFACE_COUNTERS_READ`, `DEVICE_RESOURCES_READ`.
Testes diretos do adaptador não foram usados como substitutos desse fluxo.

A execução integrada passou em **225 asserções**. O processo principal foi observado
como UID 65534, NoNewPrivileges=1 e CapEff/CapPrm/CapBnd/CapAmb zerados. O serviço
estava active/running, com PrivateDevices e conjuntos de capabilities vazios.

O update partiu do zipapp 0.6.5 original com hash e assinatura previamente
verificados. O bootstrap existente atualizou os companions; Manager/RuntimeAdapter/
Engine aplicaram 0.6.6. O postcheck usou o status real de heartbeat/poll, sem mock.
Depois das consultas e do reinício, o rollback retornou ao **Agent 0.6.5 ONLINE**,
restaurou os dois companions, removeu SSH do runtime ativo e preservou identidade
mais **24 execuções / 96 eventos** do journal. Nenhum estágio temporário permaneceu.

Backups e a candidata em `.failed` são a quarentena prevista pelo Engine existente,
fora do caminho de importação ativo. Não são dependências globais nem órfãos de
staging. A evidência/contador antirreplay do Lifecycle não é rebobinada pelo rollback.

O ensaio de falhas separado também passou, com `umask 077`: assinatura/hash/plataforma/
replay rejeitados, instalação parcial interrompida após stop, falha de postcheck
após start real, hold UNCERTAIN, restauração/reconciliação autenticada, processo
encerrado com `os._exit(77)` entre os renames e recuperação sem restaurar journal
ou identidade antigos. O staging assinado de download também foi exercitado.

## Segurança, isolamento e qualidade

A suíte integrada comprova capability/política negadas, tenant e equipment incorretos,
fingerprint divergente, credencial inválida, destino não autorizado, comandos fora
da allowlist/injeção, timeout, cancelamento, mudança de política durante SSH,
concorrência, replay, tarefa duplicada, saída sensível e limites de saída. PTY,
shell e forwarding são negados pelo servidor. O Preflight negado não abre conexão
nem materializa tarefa. FORCE RLS e acesso cruzado são verificados no PostgreSQL.

A rede Docker é interna, benchmark `198.18.77.0/28`, sem rota default e sem egress
para gerenciamento; o transporte SSH é somente loopback compartilhado com o
simulador. O init do contêiner exige privilégio de laboratório para systemd/cgroups;
o Agent não recebe esses privilégios. Rootfs é read-only e a unit usa PrivateTmp.
Isso não prova equivalência ao sandbox de uma VM operacional.

| Validação | Resultado |
| --- | --- |
| Python candidata, testes originais completos | 190 PASS, zero skips |
| Proveniência, freeze, Preflight legado, hashes e hardening SSH | 15 PASS, zero skips |
| Laravel completo, PostgreSQL descartável/TLS/FORCE RLS | 588 testes, 5.151 asserções, 21 skips condicionais, sem falhas |
| Concorrência entre processos | claims/worker/result, execução exclusiva, leases, idempotência, conversation idle e Preflight PASS |
| E2E systemd com update e rollback na mesma instância | 1 teste, 225 asserções; lifecycle final PASS |
| Regressão identity-only em fixture systemd nova | 1 teste, 41 asserções PASS |
| Regressão SSH direta legada, em modo separado | 19 testes, 287 asserções PASS; concorrência SSH nas duas suítes PASS |
| Pint | 380 arquivos PASS |
| Ruff dos fontes alterados/novos e tooling novo | PASS |
| Build limpo em cópia temporária, npm lock + opcionais | PASS |
| Secret scan básico do índice; diff check | PASS |

A varredura Ruff de toda a árvore também foi executada: permanecem **três
apontamentos herdados**, sem alterações nesses arquivos: I001 em `preflight.py`
e `managed_settings.py` da base preservada, e E402 no tooling `reconcile.py`
anterior. O log integral está nas evidências; não são apresentados como Ruff
integral verde. O secret scan básico não substitui scanner especializado.

Os 21 skips do Laravel completo são gates de laboratórios opcionais, listados
individualmente em `laravel-skips.json`. Os 19 testes de SSH direto/Telegram foram
executados separadamente, pois esse transporte Laravel é deliberadamente negado
no modo integrado. Os dois métodos do Agent também precisam de fixtures/bancos
novos por execução: comprometem identidades e mantêm um processo Agent com token
fixo. O método principal foi executado com o update; o método identity-only é
registrado separadamente nas evidências finais. Não há skip Python ou ausência
 das seis consultas E2E. Compatibilidade física e sandbox de VM permanecem fora
da cobertura, não são classificados como skips aprovados.

## Tentativas que falharam e limites

`attempts.json` registra os resultados intermediários, incluindo importações,
contratos de observabilidade, precheck de download, montagem noexec/PrivateTmp,
janela de fixtures, mistura de modos SSH incompatíveis, reutilização indevida de
fixtures comprometidas, permissão do leitor de status, corrida de readiness e
rollback depois do descarte prematuro do banco. Nenhum teste foi removido e nenhum
resultado de falha foi convertido em aprovação. A última cadeia passou em banco
novo, sem intervenção manual e com umask do companion.

Não houve acesso físico, deploy, alteração de ACL/política operacional, alteração
R2, atualização da VM operacional ou implementação de configuração remota. As
alterações anteriores alheias à missão no workspace foram preservadas.

Antes de qualquer homologação física será necessária autorização operacional
específica: tenant e operador, equipment_id, modelo/RouterOS reais, Agent/origem,
endereço de gerenciamento, fingerprint aprovado, credencial dedicada/referência no
cofre, direitos mínimos, janela, quantidade/timeout/saída e critérios de interrupção.
A primeira etapa física deve limitar-se a DEVICE_IDENTITY_READ; as outras cinco
continuam negadas até autorização própria. Também faltam revisão de vulnerabilidades/
licenças para distribuição operacional e validação do sandbox real. O adaptador
atual permanece preso ao simulador RouterOS 7.16.2; não existe flag que o libere
para um roteador físico. Uma release operacional e a assinatura/compatibilidade
correspondentes exigem processo e autorização separados pelo Lifecycle existente.

Evidências: [diretório final](evidence/mikrotik-operational-066-final/).
