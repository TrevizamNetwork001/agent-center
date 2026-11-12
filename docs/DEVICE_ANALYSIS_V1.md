# Device Analysis V1 — laboratório

> Atualização: a demonstração administrativa isolada em produção tem um contrato separado, desabilitado por padrão. Ver [compatibilidade da demonstração](DEVICE_ANALYSIS_DEMO_COMPATIBILITY.md). O bloqueio conversacional original em produção permanece.

## Escopo e revisão

Base de integração: `cd2aa49`, branch `lab/device-analysis-v1`, checkout isolado `/root/agent-center-device-analysis-lab`. Revisão operacional conferida por leitura: imagens `noc-inventory-{web,queue,diagnostic}-final-20261112`, Agent operacional 0.6.5, nenhuma operação Lifecycle pendente, sequências 3/4 em quarentena. O checkout operacional possuía 220 alterações preexistentes, inventariadas por hash antes do desenvolvimento; não são parte desta entrega.

Não houve publicação, acesso a equipamentos ou alteração de Agent, Lifecycle, Preflight, Observability, ACL, política, freeze ou R2. A existência de equipamento no inventário e o reconhecimento de intenção não concedem autorização operacional.

## Arquitetura reutilizada

O catálogo do Inventário NOC mantém suas 25 capacidades e seus sete cenários originais. A extensão `device_analysis` no mesmo catálogo descreve sete contratos fechados; `device-analysis-fixtures.json` contém os cenários especializados. `Catalog` continua sendo o ponto de consulta. Não foi criado outro inventário, catálogo de autorização ou executor.

`Inventory::simulate` continua usando `NocDevicePolicy.simulate`, transação PostgreSQL, lock consultivo por ator e lock do cadastro. A extensão recebe parâmetros tipados e revisão esperada. O histórico especializado usa **a mesma tabela `noc_simulations`**, com request key por tenant/ator, registro imutável e auditoria existente. Não há migration, tabela, fila ou endpoint remoto novo.

Fluxo LAB:

```text
Inventário NOC / conversa Web / ingresso Telegram existente
 -> solicitação explicitamente simulada
 -> RBAC + tenant LAB autorizado + revisão do cadastro
 -> contrato fechado + fixture + schema
 -> normalização por plataforma
 -> evidência estruturada SIMULATED / LAB
 -> interpretação determinística
 -> Diagnostic Experience V2 / detalhes autenticados
```

O planejador continua reconhecendo pedidos reais, mas devolve `UNAVAILABLE`, autorização `UNKNOWN`, passos vazios e cobertura `NOT_EXECUTED`. Os IDs de contrato são metadados de planejamento, **não Skills registradas no Agent**. Task Engine e Preflight permanecem responsáveis pelas operações reais existentes; uma simulação não cria `Diagnostic`, `AgentTask`, medição, alerta ou estado ONLINE.

## Contratos e compatibilidade

| Contrato | Objetivo | Estado nesta entrega |
|---|---|---|
| DEVICE_IDENTIFICATION_READ | Identificação declarada | SIMULATED |
| DEVICE_BGP_PEERS_READ | Sessões BGP, uptime, transições e motivo registrado | SIMULATED |
| DEVICE_ROUTE_LOOKUP_READ | Prefixo, next hop, protocolo, preferência, métrica e tabela | SIMULATED |
| DEVICE_INTERFACES_READ | Estado administrativo/operacional, velocidade e flaps acumulados | SIMULATED |
| DEVICE_INTERFACE_COUNTERS_READ | Contadores e comparação temporal | SIMULATED |
| DEVICE_RESOURCES_READ | CPU/memória e referências explícitas | SIMULATED |
| DEVICE_ALARMS_READ | Envelope tipado e limites para alarmes Huawei | DOCUMENTED |

Alarmes não possuem mapeamento de códigos NE8000 homologado por release nem cenário que finja essa homologação. O contrato está documentado, sem execução. Um código não confirmado não recebe causa inferida. Esta é uma limitação explícita; não foram reutilizados códigos de outras famílias Huawei.

Matriz fechada **de declarações para laboratório**, sem afirmar suporte a saída CLI ou hardware real:

* Huawei: fabricante `huawei`, plataforma `huawei-vrp`, família VRP8; NE8000/NETENGINE 8000, opcionalmente M4/M8/M14; releases `V800R022C00SPC600` e `V800R023C00SPC500`.
* MikroTik: fabricante `mikrotik`, plataforma `routeros7`, família ROUTEROS7; CCR2004-16G-2S+ e CCR2004-16G-2S+PC; 7.15.3, 7.16.2 e 7.17.2 (aceita apresentação declarada `RouterOS ... (stable)`).

RouterOS 6, VRP 5, outros modelos/releases e campos ausentes permanecem incompatíveis. Os parsers tratam **fixtures JSON estruturadas**, não textos CLI arbitrários nem respostas coletadas de equipamentos. Compatibilidade de coletor real precisará de homologação própria para cada release.

Cada contrato contém objetivo, fabricante/família, matriz, parâmetros, schemas de entrada/saída, pré-requisitos, indisponibilidades, autorização necessária e formato de evidência. Limites: entrada 1.024 bytes, evidência 65.536 bytes, 128 registros por coleção, profundidade de schema 16, previsão de timeout 5 s e concorrência serial por ator. Nenhum transporte aguarda cinco segundos: o timeout é parte do contrato futuro. O normalizador RouterOS converte somente campos numéricos tipados e enums previstos; colisões de nomes e propriedades extras são rejeitadas.

Entradas: IP válido para peer/destino, nome limitado de interface/tabela e referências percentuais de CPU/memória (1–100). Não existe parâmetro de comando. Rotas simuladas só aceitam o destino/tabela presentes na fixture; mudar o destino não inventa uma nova consulta.

## Cenários e interpretação

Há 17 cenários para cada plataforma (34 no total): identificação; BGP Established/Idle/Active/instável; rota encontrada/ausente; interface ativa/inativa; erros com duas amostras; contadores acumulados/resetados; CPU/memória acima da referência; recursos sem referência; dados insuficientes; equipamento indisponível.

Os cenários BGP/CRC equivalentes reutilizam a evidência dos cenários originais do Inventário NOC. Os demais ampliam esses casos com modelo, versão, janela e evidências normalizadas. Todos são sintéticos, com endereços de documentação; 8.8.8.8 é apenas o destino literal da fixture de tabela de rotas, sem tráfego de diagnóstico.

Regras:

* BGP Established é normal apenas no cenário; Idle/Active e saídas registradas de Established merecem atenção. UNKNOWN e falta de evidência tornam a interpretação inconclusiva. Motivo registrado não é inferência de causa raiz.
* Rota ausente só se refere à tabela consultada. Rota encontrada não comprova caminho, FIB, outras VRFs ou MTR. Evidências contraditórias são rejeitadas.
* Interface administrativamente desativada não vira falha. Velocidade e flaps sem janela são apresentados com suas limitações.
* Contador acumulado positivo não vira falha. Variação exige duas amostras, intervalo positivo, mesmo ciclo de contadores e nenhuma redução/reset. Sem isso, resultado inconclusivo.
* CPU/memória só são comparadas com referência explícita da fixture ou do operador LAB. Ausência de referência é inconclusiva. Não há threshold universal nem diagnóstico de saturação persistente com uma amostra.
* Classificação do cenário (`NORMAL`, `ATTENTION`, `INCONCLUSIVE`, `UNAVAILABLE`) é independente da cobertura (`FULL`, `PARTIAL`, `NONE`). A cobertura descreve a evidência simulada do contrato; a cobertura operacional permanece `NOT_EXECUTED`. `root_cause` é sempre nulo.

## Web, conversa, Telegram e IA

O detalhe existente ganha contratos, compatibilidade, schemas e formulários de simulação. Continua usando layout, navegação, temas, mobile e histórico existentes. As evidências são escapadas por Blade.

Exemplos de seleção real, sempre sem execução:

| Pedido | Contrato/intenção |
|---|---|
| Verifica os peers BGP do NE8000 | DEVICE_BGP_PEERS_READ |
| Tem alguma interface com erro no CCR? | DEVICE_INTERFACE_COUNTERS_READ |
| Qual rota o NE8000 usa para 8.8.8.8? | DEVICE_ROUTE_LOOKUP_READ |
| Como está a CPU do NE8000? | DEVICE_RESOURCES_READ |
| Como está o caminho até 8.8.8.8? | ROUTE_PATH_ANALYSIS existente |

Uma simulação exige pedido explícito, por exemplo `Simule bgp-idle no NE8000` ou `Simule interface-errors no CCR`. O nome/hostname deve selecionar exatamente um cadastro do tenant. Erro de cenário ou ambiguidade não escolhe outro teste silenciosamente.

Resposta simplificada: `SIMULATED / LAB`, quantidade/estado dos peers ou fatos equivalentes, classificação do cenário, cobertura e a frase de que os dados não representam um roteador real. Os detalhes ficam no painel autenticado. O mesmo Engine atende os dois canais. O Telegram reutiliza ingresso, deduplicação e outbox; entrega revalida autorização administrativa/tenant LAB antes de enviar um resultado.

O simulador é local e não faz HTTP. `Explique a simulação` é ação separada que pode usar o binding existente de explicação, somente com os dois opt-ins já existentes. Diagnostic Experience V2 permite à IA escolher IDs de frases/fatos aprovados; texto livre, medição ou classificação enviados pelo modelo são rejeitados. A aplicação mantém fatos, cobertura e classificação. Dados de conversa e identificação administrativa real não são enviados nesse modo. Não se cria outro cliente OpenAI, prompt publicado ou Knowledge publicada automaticamente.

## Segurança e isolamento

Exige `APP_ENV=local/testing`, `DEVICE_ANALYSIS_LAB_ENABLED=true` e tenant na lista `DEVICE_ANALYSIS_LAB_TENANTS`. Em `production` o simulador conversacional permanece bloqueado mesmo se as flags LAB forem definidas acidentalmente. A demonstração administrativa usa o contrato separado e as flags descritas na atualização de compatibilidade. A interface mostra os contratos documentados, mas não oferece acesso operacional.

Reutiliza autenticação, policies, CSRF, auditoria e FORCE RLS do inventário. Revisão do cadastro é verificada sob lock antes de gerar um cenário novo. Repetição da mesma chave/parâmetros retorna o registro original; reutilização com conteúdo diferente é conflito. Validação não reflete parâmetros inválidos na sessão. A evidência permanece no histórico demonstrativo imutável, separado das medições operacionais.

Não há credenciais, SSH/Telnet/SNMP/NETCONF/RESTCONF/API de equipamentos, shell, alteração de ACL, agente, device freeze ou política. Texto do LLM não se torna comando.

## Referências revisadas

Revisão conceitual do repositório [hermes-infra-skills](https://github.com/clfigueiredo/hermes-infra-skills), snapshot `a49a6a1ec7611f796edb1e0169b2c408a6d2b056`: `huawei-ne-ops`, `mikrotik-ops`, `atendimento-isp-n1-n2` e `dashboard-noc-diagnostico-cliente`, sob `.hermes/skills/forumtelecom/`. Os dois últimos declaram Hermes Tutor/MIT/1.0.0 no front matter; os dois primeiros não declaram autoria/versão/licença no documento consultado. Não se presumiu uma licença global ausente. Não foi copiado texto de procedimentos, executor, credencial ou comando; não foi instalado Hermes. Fixtures, schemas e interpretação desta entrega são originais. Comandos de configuração, reset e exemplos de credenciais foram excluídos da incorporação.

Snapshot dos documentos revisados: [huawei-ne-ops](https://github.com/clfigueiredo/hermes-infra-skills/blob/a49a6a1ec7611f796edb1e0169b2c408a6d2b056/.hermes/skills/forumtelecom/huawei-ne-ops/SKILL.md), [mikrotik-ops](https://github.com/clfigueiredo/hermes-infra-skills/blob/a49a6a1ec7611f796edb1e0169b2c408a6d2b056/.hermes/skills/forumtelecom/mikrotik-ops/SKILL.md), [atendimento-isp-n1-n2](https://github.com/clfigueiredo/hermes-infra-skills/blob/a49a6a1ec7611f796edb1e0169b2c408a6d2b056/.hermes/skills/forumtelecom/atendimento-isp-n1-n2/SKILL.md), [dashboard-noc-diagnostico-cliente](https://github.com/clfigueiredo/hermes-infra-skills/blob/a49a6a1ec7611f796edb1e0169b2c408a6d2b056/.hermes/skills/forumtelecom/dashboard-noc-diagnostico-cliente/SKILL.md).

Documentação primária usada para conferir os conceitos (snapshot revisado em 2026-11-12):

* [MikroTik BGP](https://help.mikrotik.com/docs/spaces/ROS/pages/328220/BGP), [IP Routing](https://help.mikrotik.com/docs/spaces/ROS/pages/328084/IP+Routing), [migração v6/v7](https://help.mikrotik.com/docs/spaces/ROS/pages/30474256/Moving+from+ROSv6+to+v7+with+examples).
* [Interface stats](https://help.mikrotik.com/docs/spaces/ROS/pages/139526175/Interface+stats+and+monitor-traffic), [Resource](https://help.mikrotik.com/docs/spaces/ROS/pages/40992875/Resource), [CCR2004-16G-2S+](https://mikrotik.com/product/ccr2004_16g_2splus).
* [Huawei BGP troubleshooting](https://info.support.huawei.com/network/ptmngsys/Web/tsrev_s/en/content/s/39_BGP_peer_setup_failure/BGP_peer_setup_failure_desk004.html) para campos de sessão, sem presumir compatibilidade universal.
* [Huawei NetEngine 8000](https://support.huawei.com/enterprise/en/routers/netengine-8000-pid-252772223): documentação completa retornou HTTP 403 nesta consulta. O índice oficial InfoFinder listou as duas releases da matriz. Isso não foi tratado como validação de saída CLI, de código de alarme ou de executor. Essa qualificação permanece pendente para acesso real.

As páginas MikroTik podem refletir releases mais recentes que as fixtures; a matriz acima é deliberadamente limitada a declarações LAB. Nenhum conteúdo foi publicado na Knowledge/RAG nesta entrega.

## Futuro acesso real — apenas arquitetura

```text
Inventário -> identidade verificada -> autorização efetiva existente
 -> adaptador tipado por versão -> consulta somente leitura
 -> evidência validada -> interpretação determinística -> explicação opcional
```

Antes de implementar transporte: referência de credenciais em cofre (não senha no inventário), verificação de identidade SSH/TLS, autorização por tenant/equipamento/protocolo e capacidade fechada, limites de tempo/tamanho/concorrência, revisão/validade e revalidação no executor, falhas sanitizadas, evidência e auditoria vinculadas à identidade. Mudanças futuras exigem autorização operacional própria, canário e rollback compatível, preservando journal, política e identidade. Não há endpoint que habilite esse fluxo nesta entrega.

## Publicação futura e rollback

Esta entrega não autoriza deploy. `deploy/device-analysis/Dockerfile.lab` apenas empacota overlays sobre cada uma das três imagens operacionais atuais, preservando bootstrap/rotas de cada papel. Não altera compose ou serviços. Antes de qualquer publicação: comparar revisão efetiva, integrar somente os commits desta entrega, conferir regressões e hashes, backup verificável e ausência de operações conflitantes, revisar compatibilidade de Web/queue/diagnostic. Manter flags LAB desligadas na produção. Não exige migration nem atualização do Agent.

Rollback: desabilitar flags LAB primeiro, reverter as imagens Central para as imagens anteriores verificadas e reverter os commits de aplicação. Preservar `noc_simulations`, revisões e auditoria; não apagar dados nem executar rollback de migrations antigas do inventário. Não há alteração de firmware, companion ou ACL a desfazer. As simulações retidas continuam identificadas como fictícias no histórico.

Evidências de validação, screenshots e resultados finais: [relatório de homologação](evidence/device-analysis-v1/REPORT.md).

## Reprodução das verificações

Executar a suíte pelo `scripts/test.sh`, usando os overrides `TEST_PG_CONTAINER`, `TEST_NETWORK`, `TEST_OWNER_ENV`, `TEST_RUNTIME_ENV` e `TEST_CA_FILE` de um PostgreSQL exclusivamente de laboratório. Habilitar `TEST_DIAGNOSTIC_CONCURRENCY=1` e `TEST_CONVERSATION_IDLE_CONCURRENCY=1` para os três cenários concorrentes existentes. O script recusa banco de teste preexistente e aplica migrations/grants, roda os testes com a role runtime, testa rollback e remove o banco descartável.

A concorrência especializada está em `app/tests/Support/device-analysis-concurrency.php`: executar com PHP no container tooling existente, `APP_ENV=testing` e banco dedicado `agent_center_device_analysis_lab`, previamente migrado com os grants originais. O programa verifica esses limites antes de criar fixtures e coordena quatro processos PHP com a mesma chave. Não apontar para banco operacional. Testes especializados de HTTP, parsing e explicação estão em `DeviceAnalysisTest`, `DeviceAnalysisExplanationTest` e no caso `device_analysis_lab` de `TelegramTest`.
