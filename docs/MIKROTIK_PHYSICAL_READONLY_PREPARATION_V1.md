# MikroTik físico — preparação offline V1

**BLOCKED: o candidato atual não é compatível com uma homologação física segura nas restrições desta missão.** Foi preparada e testada uma referência assinada de laboratório; nenhuma proteção foi removida para tornar o equipamento de produção acessível.

Base: `7f2dd4b` / `7b08930`. Data: 2026-11-14. Esta entrega não publica, instala, atualiza ou executa consultas operacionais.

## Estado confirmado e limites da inspeção

| Componente | Evidência efetiva local/offline | Limite |
| --- | --- | --- |
| Central | PHP 8.4.27, Laravel v13.40.0, phpseclib 3.0.58, lidos de vendor no tooling | Não comprova imagem publicada |
| PostgreSQL | CLI da imagem de laboratório 17.11; fixtures em banco descartável/TLS/FORCE RLS | Banco operacional não consultado |
| Agent fonte disponível | 0.6.4 em `__init__.py` e pyproject; Python >=3.13; dependências de projeto vazias | Agent operacional 0.6.5 é informação do operador, não foi interrogado |
| Tooling candidato | Python 3.13.18, Debian 12, x86_64; Paramiko 3.5.1, cryptography 46.0.3, bcrypt 5.0.0, PyNaCl 1.6.0, cffi 2.0.0, pycparser 2.23 | Dependências instaladas somente na imagem LAB; VM operacional não inspecionada |
| Lifecycle | Verificador Ed25519, build determinístico e dry-run nativos exercitados sem alterações | Manifesto/perfil nativo alvo Debian 13; precheck systemd completo não homologado nesta rodada |
| Inventário / Preflight / Task Engine | Código atual e fixtures tenant-scoped; atestação por operação, nonce/digest, revalidação claim/START/result, journal/leases existentes | Nenhum dado do inventário físico, secret operacional ou publicação é presumido a partir do LAB PASS |

As alterações locais anteriores, inclusive em Lifecycle, são preexistentes e permanecem fora deste commit. A referência foi construída da árvore local disponível e vinculada ao source_sha256 do builder existente; uma release operacional exige árvore revisada, versão/floor e origem verificadas, não promoção desta árvore suja.

## Quatro bloqueios concretos

1. `RouterOs7\Integration::enabled/input` e `routeros7_lab.isolated/validate/collect` exigem ambiente/montagem/rede de simulador e destino fixo `127.0.0.1:2223`. Não existe contrato operacional aprovado para produção. Marcar o MikroTik físico como LAB, encaminhar loopback ou relaxar esses checks seria bypass, não preparação segura. Nenhum desses caminhos foi criado.
2. `lifecycle.components.specification` só admite MTR/network-raw-helper com dependências fechadas python3.13/mtr0.95. Um componente SSH é rejeitado com `unsupported_component`, inclusive com assinatura de laboratório válida. `update.engine.validate` e o builder aceitam apenas fontes `.py` do Agent; o arquivo não inclui Paramiko/cryptography e suas dependências nativas. Não há distribuição SSH homologada pelo Lifecycle existente. Nenhuma instalação manual na VM, extensão de allowlist ou alteração de Lifecycle foi feita.
3. A fonte disponível gera 0.6.4, inferior ao operacional 0.6.5 declarado. Não houve bump fictício de versão, atribuição de sequência operacional, downgrade ou presunção de compatibilidade. O piso real de sequência/componentes e a âncora operacional permanecem desconhecidos.
4. O manifesto é `platform=debian13`, mas o host de tooling efetivo é Debian 12. Assinatura/formato/dry-run positivos não comprovam o precheck Debian 13/systemd, hardening, dependências ou rollback nativo da plataforma alvo. Não se alterou o verificador para aceitar essa diferença.

Esses bloqueios impedem **MIKROTIK PHYSICAL READ-ONLY READY**. A preparação parou antes de qualquer ampliação de privilégios, remoção de proteção ou acesso físico.

## Referência de release produzida

Reutilizados `scripts/prepare-agent-candidate.py`, `agent/packaging/build.py`, `lifecycle.releases.verify` e `update.engine.Engine.dry_run`, sem criar executor/updater.

- Bundle público: `/tmp/routeros-physical-v1/checked-bundle/`.
- Agent SHA-256: `a73b2bde39327124bb622c0550142a4144eb1cd8bee2501077be6630a0b1d30f`.
- Artefatos agent, updater companion, updater CLI, helper e bootstrap gerados da mesma fonte e reconstruídos byte a byte pelo builder existente. Nenhum deles foi instalado ou publicado.
- Assinatura Ed25519 de laboratório verificada pelo código nativo. Chave privada efêmera fora do Git foi destruída com o container de preparação; somente chave pública e assinatura constam das evidências. A chave não é âncora operacional.
- Sequência 2 / piso 1 são sintéticos, exclusivos do laboratório. Não são reserva ou autorização de sequência operacional.
- Adulteração do artefato, replay de sequência e plataforma diferente foram rejeitados pelo verificador. Dependência SSH não suportada foi recusada, sem relaxar allowlists.
- Dry-run nativo retornou apply=false. Hashes de fixtures locais de identidade, configuração, runtime e journal com tentativa incerta ficaram iguais antes/depois. Nenhuma identidade ou journal foi restaurado, apagado ou substituído.

`prepare-physical.py` automatiza essa avaliação offline e emite **BLOCKED**, mesmo quando a construção/assinatura termina corretamente. O bundle assinado final tem o mesmo hash do Agent efetivamente executado no teste SSH.

## Identidade somente — validação offline nova

O runner agora pode montar uma zipapp candidata read-only; o launcher verifica assinatura/hash com o verificador existente antes de chamar o entrypoint, inclusive o action-worker isolado `-I`. A consulta não foi uma chamada direta ao adaptador.

**1 teste / 41 asserções PASS**: solicitação de identificação por conversa → Preflight → confirmação → plano/Task Engine → Agent API HTTP real → zipapp assinada não-root → SSH real no simulador → evidência persistida e resposta SIMULADO. O trace/journal confirma quatro ciclos entregues, incluindo falhas esperadas e cancelamento; não são quatro identificações bem-sucedidas.

A mesma aprovação de identidade recusou expressamente DEVICE_BGP_PEERS_READ, DEVICE_ROUTE_LOOKUP_READ, DEVICE_INTERFACES_READ, DEVICE_INTERFACE_COUNTERS_READ e DEVICE_RESOURCES_READ. Não houve habilitação por fabricante, categoria ou tenant. O teste também comprovou host key incorreta, viewer sem permissão, equipamento inexistente/adulterado, tenant diferente com FORCE RLS, política vencida, duplicação sem nova tarefa, timeout e cancelamento. Ambos os guards existentes de rollback recusaram destruir aprovações/credenciais preenchidas e preservaram os dados.

O journal terminou com quatro ciclos reconhecidos, zero replay de START, reservas duplicadas recusadas, UID 65534 e freeze=true. Python completo: **190 testes PASS**. Pint do arquivo alterado e Ruff do tooling: PASS. A suíte Laravel de 587 testes pertence à entrega anterior; não foi recontada como execução desta rodada. Os testes Python incluem os casos existentes de rollback/recovery em filesystem/adapters de teste; não se afirma rollback operacional ou instalação systemd nesta missão.

Reprodução de laboratório, usando um bundle previamente verificado e caminho temporário novo:

```bash
ROUTEROS_TASK_LAB=1 ROUTEROS_PACKAGED_CANDIDATE=/tmp/routeros-physical-v1/checked-bundle/agent.pyz DEVICE_READONLY_STATE=/tmp/physical-identity-new ./scripts/lab/device-readonly/run.sh --filter test_identity_only_physical_preparation
```

Um ensaio inicial da verificação no launcher foi bloqueado por PermissionError nos metadados públicos 0600. A correção restringiu-se a tornar manifesto, assinatura e chave **pública** legíveis (0644), mantendo chave privada 0600 fora do bundle e Agent não-root. A repetição passou sem desativar verificação ou ampliar privilégios.

O runner tem rede Docker internal, sem portas publicadas, sem rota default nem egress de gerenciamento. Não usa env/credenciais operacionais. A leitura `/system resource print` antecede `/system identity print` para validar modelo/versão; esses são os dois templates do contrato de identidade, não liberação da operação DEVICE_RESOURCES_READ. O dialeto continua restrito a **7.16.2 stable** no simulador; não comprova formato nem modelo/versão do roteador físico.

## Gate operacional futuro — ainda não concedido

Documento aprovado explicitamente pelo operador, com ID imutável, identidade do aprovador e timestamp. Nenhum valor abaixo foi descoberto/inferido nesta missão; o cadastro no inventário não concede acesso.

| Campo obrigatório | Exigência exata |
| --- | --- |
| Tenant e ator | tenant_id, usuário solicitante/aprovador e permissões efetivas; RLS/RBAC preservados |
| Equipamento | equipment_id único e revisão, confirmação de borda física em produção; não CHR/fixture |
| Modelo e versão | Modelo e RouterOS exatos obtidos por canal autorizado prévio; incompatibilidade exige revisão offline, nunca alteração do roteador para satisfazer parser |
| Destino | IP literal de gerência e porta SSH exatos; sem DNS/proxy/túnel/redirecionamento |
| Origem | Agent ID, versão/hash assinados e compatíveis, plataforma, IP/interface/segmento autorizados e origem já permitida; sem alterar ACL nesta autorização |
| Identidade SSH | Chave/fingerprint SHA256 previamente aprovados por canal independente, origem da verificação e revisão; sem TOFU |
| Credencial | Conta dedicada já existente com direitos efetivos mínimos atestados, referência/revisão no SecretVault; nunca chave/senha no documento. Sem admin/full nem credenciais administrativas genéricas |
| Escopo | Somente DEVICE_IDENTITY_READ, parâmetros vazios, dois templates fixos de identidade/recursos, nenhuma CLI genérica |
| Janela | Início/fim UTC, validade de confirmação/Preflight e revisão/digest da política; mudar qualquer vínculo exige nova autorização |
| Orçamento proposto | **Uma consulta lógica, uma sessão, sem retry automático**; até duas leituras fixas, timeout compartilhado proposto de 5 s e até 65.536 bytes; aprovar explicitamente esses limites |
| Interrupção | Fingerprint/modelo/versão divergentes, permissão/política expirada ou modificada, cancelamento, falha de autenticação, timeout, resposta inválida/sensível/truncada/excessiva ou qualquer indício de impacto |
| Evidência | Auditoria por tenant/ator/equipamento/Agent/operação/digest/janela; somente dados estruturados sanitizados; sem console bruto/secrets |

As outras cinco capacidades permanecem negadas no plano proposto. Não consultar BGP/rotas completos, configuração, firewall, PPPoE ou conexões. Criar conta/direitos no MikroTik exige autorização separada; nenhuma conta foi criada aqui.

O gate não é implementado mediante uma flag de laboratório em produção. Será necessário revisar um contrato operacional restrito que preserve freeze global e o Lifecycle existente; esta missão não autoriza remover proteções para implementá-lo.

## Rollback e riscos residuais

Sem deploy ou migrations novas a reverter. Para esta preparação, reverter apenas este commit de testes/tooling/documentação, preservando as mudanças locais anteriores. Os containers/rede/volume privados do runner são descartados; nenhum serviço operacional é parado/reiniciado. Artefatos públicos podem ser retidos para auditoria; não distribuir a assinatura de laboratório como confiança operacional.

Uma implantação futura autorizada exige backup e imagem/release assinada anterior, prechecks nativos, plano de interrupção e restauração pelo Lifecycle existente. Preservar identidade, journal, gerações, evidências e resultados incertos; não repor journal antigo nem repetir START automaticamente. Rollback de release não autoriza reduzir piso/seq ou contornar anti-downgrade.

Riscos pendentes: hardware e versão reais não verificados; plataforma/estado publicado e dependências da VM não confirmados; árvore local com mudanças anteriores não revisadas para release; distribuição SSH e autorização operacional inexistentes; leitura também consome recursos da borda; ausência de prova de carga/compatibilidade e rollback systemd nativos. Não houve conexão física, publicação, atualização do Agent, alteração de rede/ACL/usuários, R2 ou Lifecycle. Device freeze global permaneceu ativo.

## Arquivos desta preparação

- `scripts/lab/routeros-tasks/prepare-physical.py`: build/assinatura de laboratório, verificação e avaliação de compatibilidade offline, reutilizando o tooling existente.
- `scripts/lab/routeros-tasks/launch.py` e `scripts/lab/device-readonly/run.sh`: montagem read-only de bundle temporário e verificação antes da execução exclusivamente LAB.
- `app/tests/Feature/RouterOsTaskIntegrationTest.php`: caso novo de identidade somente e negações, sem alteração de código operacional.
- Este relatório e `docs/evidence/mikrotik-physical-preparation-v1/`: manifesto, assinatura/chave pública LAB, versões, testes e motivos BLOCKED. Os arquivos zipapp públicos permanecem no caminho temporário indicado; nenhum binário foi publicado.
