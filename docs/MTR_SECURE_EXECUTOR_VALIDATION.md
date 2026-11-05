# MTR secure executor — validação de 2026-11-06

**MTR SECURE EXECUTOR READY** — kit preparado e validado para implantação administrativa manual. A VM teste não foi alterada. O runtime real permanece 0.6.1 até atualização autorizada; o candidato é 0.6.2. O contrato Laravel MTR também precisa estar publicado antes da atualização do runtime.

## Desenho e arquivos

- Principal: capability sets vazios, NoNewPrivileges e PrivateDevices preservados. Nenhuma alteração em sua unit, Config, ManagedSettings, política ou AGENTS.md.
- Executor: usuário dedicado sem login; somente CAP_NET_RAW; protocolo Unix fechado e SO_PEERCRED; políticas local-base e teto root-only; LoadCredential para snapshots; sem acesso à identidade privada.
- Arquivos principais: agent/src/agent_center/mtr_helper.py, mtr_client.py, mtr.py, capabilities.py; agent/packaging/build.py; agent/mtr-debian13/*; scripts/build-mtr-secure.py; agent/tests/test_mtr_helper.py; scripts/tests/mtr-secure/*.
- Instalação e rollback completos: docs/MTR_SECURE_EXECUTOR.md, também incluído como INSTALL.md no kit.

## Ambiente comprovado

Debian 13, Python 3.13.5, mtr-tiny 0.95-1.1+b1, mtr 0.95, systemd 257.13-1~deb13u1. mtr-packet com cap_net_raw=ep. A unit passou no systemd-analyze verify da imagem Debian 13.

O laboratório de protocolo usa UID 987 para o cliente sem capabilities e UID 988 para o helper, com somente NET_RAW. Para validar as units sem criar contas no host, o laboratório nativo usa cliente nobody/65534 e usuário dinâmico exclusivo para o helper, em rootfs Debian 13; estado e credenciais sintéticos são montados em namespaces privados. O kit de produção usa o usuário permanente dedicado e StateDirectory 0700. Todos os demais controles relevantes (NoNewPrivileges, PrivateDevices, bounding/ambient raw-only, famílias de socket, filtros, tempo e recursos) foram mantidos.

## Resultados finais das units e do runtime empacotado

| Verificação | Resultado |
|---|---|
| ready via socket | PASS |
| ICMP autorizado | PASS — 8.509 s incluindo inicialização/supervisão |
| TCP/443 autorizado | PASS — 8.649 s incluindo inicialização/supervisão |
| runtime agent.pyz via action worker, sem capabilities | PASS — 10.790 s |
| duas solicitações simultâneas | uma coleta aceita, outra recusada por mtr_rate_limited |
| cancelamento | cliente fechou em 1.236 s; nenhuma instância executando/desativando após a janela de verificação de até 3 s |
| cooldown | mtr_rate_limited |
| destino fora da política | policy_denied |
| comando/flags livres | mtr_invalid_input |
| helper sem raw capability | mtr_permission_denied, antes de qualquer probe |
| supervisor com prazo de 2 s no teste | conexão encerrada em 3.135 s incluindo inicialização; nenhum resultado NORMAL inventado |
| setuid(0) | negado |
| leitura da identidade do Agent | negada |
| alteração do código | negada |
| AF_PACKET/captura | negado |
| usuário do helper | não root |
| effective capabilities | exatamente 0x2000, somente CAP_NET_RAW |

A coleta técnica por socket no laboratório em container levou aproximadamente 6,01 s para ICMP e 6,37 s para TCP/443. Os tempos da tabela são da execução nativa final com limite CPU de 25%, namespace e ativação systemd e não devem ser confundidos com tempo técnico puro ou duração final do diagnóstico da Central.

As coletas usaram somente IPv4 público resolvido de google.com, explicitamente autorizado nas políticas sintéticas. Não se tratou de teste a partir do Agent do cliente, nem de diagnóstico de seu circuito. Não houve SSH/API em equipamentos, consultas ASN externas, captura tcpdump ou varredura Nmap.

## Regressões e integridade

- Laravel/PostgreSQL: 228 testes, 1.791 assertions, PASS.
- Harness PostgreSQL em processos separados: claims concorrentes, corrida worker/result, lane exclusiva, fencing de claim expirado, idempotência de resultado duplicado e dependências DNS, PASS.
- Migrations up/rollback no banco descartável, PASS.
- Python: 102 testes, PASS, incluindo contrato do helper, políticas, timeout, cancelamento, lock/cooldown, limite de saída, ambiente mínimo, stdin isolado do socket e IPv6 normalizado.
- Pint: 21 arquivos pertinentes, PASS; PHP não foi alterado nesta extensão de segurança.
- Ruff: fontes, testes e ferramentas do kit/laboratório, PASS.
- Build determinístico, manifestação SHA-256 e self-check do runtime, PASS.
- Instalador em container Debian 13: preparação sem início de serviços; config/unit/identidade preservadas; teto não autorizado recusado; instalação anterior recusada; pacote adulterado recusado; retirada dos arquivos do helper preservou os dados do principal.
- Nenhuma mudança nos seis arquivos de invariantes listados na validação automatizada, incluindo a unit principal e ambos os AGENTS.md.
- Diff check e varredura de padrões de credenciais nas adições registrados no diretório de evidências. Nenhum .env, chave ou segredo faz parte do kit.

## Artefato final

Arquivo: mtr-secure-debian13.tar.gz

SHA-256 externo: `49fd13a3da99e889402016d5ff10dc2a9729dd8c62715ae64b351e11c59d75cb`

Runtime: `37554891c5d0b9b896a7300b7f08f755a6d57d87defc4649bb11e04d1d0534a1`

Helper: `a6ab1c55d490523a9a385574e9f10dc8638b4d3f489ae5199119c783ded74b81`

Manifesto interno e arquivo .sha256 acompanham o pacote. O digest externo deve ser conferido por canal confiável. O pacote não inclui os binários Debian de mtr, tcpdump ou Nmap; a preparação manual usa os repositórios Debian.

## Estado real e limites residuais

A VM teste não foi acessada nem alterada automaticamente. A entrega permite instalação manual, não constitui habilitação real do Agent. O novo executor está na branch local de integração; não foi publicada imagem/versão na Central nem no GitHub.

O teto administrativo é intencionalmente restritivo. O helper usa a allowlist-base de config.json: autorizações presentes apenas em managed-settings.json são recusadas nesta versão, mesmo que o principal as reconheça. Isso falha de forma restritiva; não ampliar políticas para contornar uma recusa. Revisar o escopo antes de alterar administrativamente o teto/base. Alterações de política valem para novas coletas; uma coleta iniciada termina/cancela dentro dos limites definidos. Rotação DNS pode ocasionar recusa segura de um endereço que deixou de aparecer na resolução.

CAP_NET_RAW permanece sensível dentro do helper. Código/units/artefatos e teto precisam permanecer root-owned. O parser e a interpretação seguem as limitações do MTR: respostas de hops não provam encaminhamento, causalidade ou caminho BGP. IPv6 tem testes de contrato, mas não coleta externa real neste laboratório.

tcpdump e Nmap foram contemplados nas instruções de instalação de pacotes para uso futuro; não têm executores remotos habilitados, nem autorização de captura/varredura por este helper. Device freeze permanece ativo. Nenhum recurso de R2, retenção ou serviço não relacionado foi modificado.

Evidências completas: /root/agent-center-mtr-secure/ (logs, protocolo, resultado de units e testes de instalação). Units temporárias são removidas pelo harness em finally.
