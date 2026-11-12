# Inventário NOC V1

Módulo Laravel administrativo, sem conexão operacional com equipamentos. Base: `3469793`. O pedido inicial era de laboratório; a autorização complementar “pode publicar” permite publicação somente depois de homologação, prechecks, backup verificado e rollback preparado.

## Arquitetura e dados

- `noc_devices`: UUID, tenant, nome, hostname normalizado, IP `inet`, fabricante/plataforma, modelo, versão declarada, categoria, função, POP, ambiente, descrição, estado administrativo, observações, revisão e responsáveis.
- `noc_device_revisions`: snapshots administrativos completos e imutáveis por revisão. Desativar não exclui cadastro nem histórico.
- `noc_simulations`: resultados identificados como FIXTURE/SIMULADO, UUID e chave idempotente por tenant/usuário, separados de `diagnostics`, conclusões e tarefas reais. Nenhuma escrita em Operations Health, alarmes, Knowledge ou RAG.
- Migration `2026_11_13_000001_create_noc_inventory`: aditiva, com ENABLE/FORCE RLS nas três tabelas, FKs compostas `(device_id, tenant_id)`, unicidade por tenant, índices e triggers de proteção do histórico. Runtime não recebe DELETE; histórico e simulações não recebem UPDATE. A reversão da migration exige tabelas vazias, com CHECK validado pelo PostgreSQL independentemente da visibilidade RLS.
- Nomes são únicos sem distinção de maiúsculas; hostname é normalizado; IPv4/IPv6 são validados e IPv4 mapeado em IPv6 é canonicalizado. Endereços CIDR não são aceitos como IP de gerenciamento.
- `NocDevicePolicy`: usuários ativos do tenant podem consultar; administradores do tenant/plataforma podem cadastrar, editar e desativar; operadores também podem gerar simulações. Viewer não pode alterar nem simular. Administrador de plataforma precisa selecionar um tenant.
- `Inventory` usa transações, bloqueio de linha, revisão otimista e chave idempotente. Simulações do mesmo usuário são serializadas por advisory lock. Conflitos de edição retornam 409 e duplicidades de cadastro retornam validação.
- `NocDeviceRequest`/`NocActionRequest` rejeitam campos não previstos, credenciais reconhecíveis e tentativas de informar autoria, tenant, disponibilidade ou resultado. Entradas inválidas são descartadas antes do flash de sessão. Campos livres continuam exigindo disciplina: não são um cofre e não conseguem reconhecer qualquer segredo arbitrário.
- Auditoria global aceita eventos fechados `noc.device_created`, `noc.device_updated`, `noc.device_deactivated`, `noc.simulation_created`; grava apenas o UUID autorizado, sem IP, notas, credenciais ou payload. Histórico administrativo permanece acessível sob RLS/RBAC.

## Interface

Navegação OBSERVAR → Inventário NOC. Resumo administrativo, listagem paginada, busca por nome/hostname/IP/modelo e filtros por categoria/fabricante/POP/função/estado. Telas de cadastro, edição, detalhe, capacidades, limitações, revisões e demonstrações. Layout, ícones, tokens, temas e sessão nativos preservados. Não há selo de saúde real, senha ou botão para conectar.

`NocInventoryController` usa autenticação, sessão, password.changed, Gates, FormRequests, CSRF e throttling Laravel. Não existe endpoint de exclusão ou execução remota. Fabricantes e plataformas incompatíveis são rejeitados; a versão desconhecida aparece explicitamente como não informada/não verificada.

## Catálogo e simulações

`resources/noc-catalog.json` versão 1 contém oito categorias, nove fabricantes administrativos, 14 plataformas e 25 capacidades de Routing, sistema, Switching, FTTH e BNG/PPPoE. Metadados originais incluem identificador estável, objetivo, plataformas candidatas, pré-requisitos, schema de evidência, limites e estado. DOCUMENTED descreve; SIMULATED indica fixture; AVAILABLE exige um executor homologado; UNAVAILABLE indica indisponibilidade. **Nenhuma capacidade está AVAILABLE.**

Modelo e versão são considerados como identidade declarada, não como suporte comprovado. Uma associação de categoria/plataforma retorna `REQUIRES_VALIDATION` ou `NOT_DOCUMENTED`, sempre `operational_enabled=false`. Adicionar fabricante ou capacidade requer extensão versionada/revisada do catálogo e testes; não há setter administrativo que possa habilitar execução.

Sete fixtures próprias: BGP estabelecido, peer down, CRC, ONU offline, potência óptica baixa, PPPoE desconectado e dados insuficientes. Os valores ópticos de referência são fictícios, não especificações do fabricante ou limites universais. Evidências e classificação aparecem somente como SIMULADAS. Desativação administrativa não impede estudar uma fixture e não habilita conexão.

## Planejamento compartilhado

O `IntentParser` existente delega objetivos de equipamento ao `Noc\IntentInterpreter`, independente do tom ou do LLM. `IntentPlanning::devicePlan` gera plano fechado e indisponível. `IntentPlanning::profile` recusa objetivos de equipamento para impedir substituição por Profiles de VM. O Conversation Engine usa essa decisão nos dois canais; confirmação, Task Engine e Preflight não são acionados por esses objetivos.

| Pedido | Intenção | Capacidade prevista | Resultado |
|---|---|---|---|
| Verifique o BGP do NE8000 | BGP_SESSION_ANALYSIS | routing.bgp.sessions | UNAVAILABLE |
| Confira a rota até 8.8.8.8 no roteador | ROUTING_TABLE_LOOKUP | routing.table | UNAVAILABLE |
| Analise o caminho até 8.8.8.8 | ROUTE_PATH_ANALYSIS | MTR/Profile existente | Fluxo existente e Preflight preservados |
| Verifique a potência das ONUs | OPTICAL_SIGNAL_ANALYSIS | ftth.optical.signal | UNAVAILABLE |
| Verifique as interfaces do roteador | DEVICE_INTERFACE_ANALYSIS | system.interfaces | UNAVAILABLE |
| Confira as sessões PPPoE | PPPOE_SESSION_ANALYSIS | bng.sessions | UNAVAILABLE |
| Verifique o status das ONUs | ONU_STATUS_ANALYSIS | ftth.onu.status | UNAVAILABLE |

Caminho/MTR “no roteador” exige esclarecimento entre tabela do equipamento e caminho a partir do Agent. Não oferece confirmação. Conceitos/procedimentos Knowledge continuam no fluxo RAG existente. Nome/hostname exato é procurado apenas no tenant, em consulta limitada; seleção ausente/ambígua não autoriza nada. Não há DNS lookup do inventário. O destino mencionado fica como parâmetro de planejamento, nunca como autorização.

Resposta: “Entendi qual verificação você precisa, mas essa consulta ao equipamento ainda não está habilitada no Agent Center. Nenhum teste foi executado.” Metadados separam intenção, autorização UNKNOWN, passos vazios, classificação ausente e objetivo NOT_EXECUTED.

## Referências revisadas

Fonte primária: [clfigueiredo/hermes-infra-skills](https://github.com/clfigueiredo/hermes-infra-skills), commit fixado `a49a6a1ec7611f796edb1e0169b2c408a6d2b056` (2026-07-13). O repositório se apresenta como backup; `SOURCE.txt` aponta para `clfigueiredo/skills_forumtelecom`. O commit não possui assinatura verificada. O diretório raiz revisado não fornece arquivo LICENSE e a API informa licença de repositório não identificada.

| Arquivo revisado sob `.hermes/skills/forumtelecom/` | Metadados encontrados | Uso nesta entrega |
|---|---|---|
| [atendimento-isp-n1-n2](https://github.com/clfigueiredo/hermes-infra-skills/blob/a49a6a1ec7611f796edb1e0169b2c408a6d2b056/.hermes/skills/forumtelecom/atendimento-isp-n1-n2/SKILL.md) | Hermes Tutor; 1.0.0; MIT declarado no frontmatter | Referência conceitual para triagem e privacidade |
| [dashboard-noc-diagnostico-cliente](https://github.com/clfigueiredo/hermes-infra-skills/blob/a49a6a1ec7611f796edb1e0169b2c408a6d2b056/.hermes/skills/forumtelecom/dashboard-noc-diagnostico-cliente/SKILL.md) | Hermes Tutor; 1.0.0; MIT declarado no frontmatter | Separação de evidências e interpretação; nenhum plugin importado |
| [huawei-ne-ops](https://github.com/clfigueiredo/hermes-infra-skills/blob/a49a6a1ec7611f796edb1e0169b2c408a6d2b056/.hermes/skills/forumtelecom/huawei-ne-ops/SKILL.md) | Autoria, versão e licença não declaradas no arquivo | Revisão de famílias/versões; comandos excluídos |
| [mikrotik-ops](https://github.com/clfigueiredo/hermes-infra-skills/blob/a49a6a1ec7611f796edb1e0169b2c408a6d2b056/.hermes/skills/forumtelecom/mikrotik-ops/SKILL.md) | Autoria, versão e licença não declaradas no arquivo | Distinguir RouterOS 6/7; comandos e automações excluídos |
| [zabbix-ops](https://github.com/clfigueiredo/hermes-infra-skills/blob/a49a6a1ec7611f796edb1e0169b2c408a6d2b056/.hermes/skills/forumtelecom/zabbix-ops/SKILL.md) | Autoria, versão e licença não declaradas no arquivo | Limites e cautela com monitoramento; nenhuma API integrada |
| [olt-huawei-ops](https://github.com/clfigueiredo/hermes-infra-skills/blob/a49a6a1ec7611f796edb1e0169b2c408a6d2b056/.hermes/skills/forumtelecom/olt-huawei-ops/SKILL.md) | Autoria, versão e licença não declaradas no arquivo | Família MA5800; credenciais padrão e comandos excluídos |
| [datacom-dmos-ops](https://github.com/clfigueiredo/hermes-infra-skills/blob/a49a6a1ec7611f796edb1e0169b2c408a6d2b056/.hermes/skills/forumtelecom/datacom-dmos-ops/SKILL.md) | Hermes Tutor; 1.0.0; MIT declarado no frontmatter | Identificação de DmOS e exigência de validar release/modelo |

A revisão encontrou exemplos de provisionamento, reset/reboot, gravação de configuração, automações SSH e credenciais padrão. **Nenhum desses trechos foi incorporado.** Metadados e fixtures foram escritos para a Central, sem copiar executores ou exemplos de comandos. Nenhuma skill foi instalada, nenhum Hermes foi instalado e nenhuma Knowledge foi publicada automaticamente. Antes de futura coleta real, validar documentação oficial de cada fabricante, hardware, firmware, versão e direitos de reutilização do conteúdo específico.

## Futuro Device Access — somente arquitetura

`Noc\QuerySpecification` é um DTO fechado de dados: tenant, UUID do equipamento, revisão, capability e parâmetros tipados; rejeita `command` e não possui `execute` ou `connect`. Schemas de evidência exigem identidade, modelo, versão, origem, timestamp e limitações. O DTO não prova permissão.

Um adaptador futuro precisará de identidade verificada, referência de credencial em cofre (sem segredo no inventário), pinning SSH/TLS, autorização efetiva por tenant/equipamento/capability, device freeze, consultas fechadas sem shell, timeout, orçamento de evidência, limite de concorrência e auditoria. Deverá revalidar identidade/revisão/permissão no executor e tratar timeout, identidade divergente, parser incompatível e evidência parcial. Nada disso foi conectado nesta versão. Configuração assistida continua fora do escopo.

## Publicação e rollback

As imagens incrementais derivam das três imagens operacionais do Preflight Real Flow. Web e fila usam o artefato `deploy/noc-inventory/web.operational.php`, que conserva a ausência da rota de Observability que existe no checkout histórico, mas não na imagem publicada; acrescenta somente as rotas NOC. Não copia Python Agent, Lifecycle ou Preflight.

1. Prechecks de Health/versões/operações, hash de política e trabalho preexistente; backup local com prune desativado e restauração isolada.
2. Construir e testar as imagens Web/fila/diagnóstico, sem habilitar conectividade de equipamentos.
3. Executar somente a migration NOC nova com autoridade migrator, aplicar grants fechados das três tabelas e verificar FORCE RLS. A Central antiga continua compatível durante a transição.
4. Trocar apenas imagens Web, fila, scheduler e worker de diagnóstico. Preservar env, secrets, signing, assets e Agent. Limpar views; validar HTTPS, login, readiness, filas, webhook e Health.
5. Validar o módulo em transação temporária ou com fixtures apenas no laboratório. Não semear equipamentos de demonstração na produção.

Rollback: voltar às imagens `preflight-real-flow-web-20261112`, `preflight-real-flow-queue-20261112` e `preflight-real-flow-diagnostic-20261112`, mantendo as três tabelas/grants/RLS e todo o histórico. Não rodar `migrate:rollback` em dados reais. Não restaurar backup sobre dados mais recentes como reversão normal. O módulo não cria novos jobs, executores, AgentTasks ou credenciais; a versão antiga ignora seus dados. Uma migration down só é admissível com tabelas comprovadamente vazias em ambiente descartável.

## Validação

Resultados, commits, imagens e screenshots efetivos serão registrados após a homologação e a publicação autorizada. Produção permanecerá preservada se qualquer condição crítica falhar.

## Homologação parcial registrada

- Revisão visual: 24 combinações de listagem/cadastro/detalhe/catálogo, 390/768/1440 pixels e claro/escuro; CRUD, desativação, simulação e teclado nativos passaram em banco dedicado com fixtures. O ajuste de tablet restringiu rolagem à tabela, sem alterar o layout global.
- Build frontend passou com o lockfile existente, sem novas dependências. Backup `20261112T203517Z` foi restaurado em banco isolado; 16 tabelas protegidas, RLS e credenciais cifradas conferidas.
- O primeiro ciclo completo identificou dois testes legados com contagem fixa de 40 tabelas FORCE RLS; o novo schema tem 43. Foram atualizadas as expectativas exatas para 43, mantendo as verificações específicas de RLS, ausência de DELETE e isolamento nas três tabelas NOC. Uma importação ausente no teste novo também foi corrigida. Nenhuma asserção de segurança foi removida.
- Reversão de schema populado em laboratório foi recusada pela validação `noc_devices_rollback_empty` do PostgreSQL, preservando os registros mesmo para a autoridade migrator. O rollback operacional mantém tabelas e histórico.

## Resultado de homologação

**AGENT CENTER NOC INVENTORY V1 LAB PASS.** Suíte completa: **535 testes / 4.322 asserções PASS**. Concorrência em processos distintos do Task Engine, encerramento de conversa e retorno de Preflight PASS. Rollback integral das migrations no banco descartável vazio PASS. Pint **329 arquivos PASS**, build frontend PASS, revisão visual e CRUD/simulação nativos PASS. Nenhuma conexão a equipamento, AgentTask de inventário ou capacidade AVAILABLE.

A confirmação final incluiu requests Web/Telegram, RAG/Knowledge preservados, RBAC, CSRF, duplicidades, edição concorrente por revisão, FORCE RLS, FK entre tenants, histórico imutável, fixtures, intenção e ausência de substitutos. A autorização de publicação será aplicada somente a este módulo. [Evidências de teste](evidence/noc-inventory/test-proof.json), [interface](evidence/noc-inventory/ui-proof.json) e [screenshots](evidence/noc-inventory/screenshots).

Revalidação focada final: **16 testes / 324 asserções PASS**, incluindo seleção de identidade com pontuação no pedido, planejamento sem Profile de VM e contrato futuro sem `execute`/`connect`. Secret scan e diff check PASS.

A checagem de empacotamento confirmou que o worker de diagnóstico possui bootstrap/rotas distintos da Web (sem arquivo de rotas Telegram). Sua imagem preserva essas rotas e layout originais, recebendo somente o núcleo/bibliotecas necessários; não se injeta o roteamento Web nesse papel. O teste de bootstrap da imagem é obrigatório antes da publicação.

Revisão final de segurança: o nome livre do equipamento é escapado também no título HTML, além do corpo da página. Teste com delimitador de título e marcação de script garante saída literal, sem execução. Revalidação do módulo: **16 testes / 312 asserções PASS**, após o ciclo completo, cobrindo o novo caso de segurança. A verificação mobile consulta a navegação no DOM porque o menu lateral fica oculto por padrão nessa largura.

## Publicação autorizada concluída

Commits funcionais: `08a2eda`, `b847214` e `023d7c1`. A migration NOC e somente os grants das três novas tabelas foram aplicados. Nenhuma migration antiga pendente foi executada. Pré-checks e deploy-precheck passaram, incluindo PostgreSQL/TLS, fila funcional e worker de diagnóstico.

| Papel | Imagem final | SHA-256 |
|---|---|---|
| Web | `agent-center-central:noc-inventory-web-final-20261112` | `bc7332f97c67e513a851668711c0505d7cef08c97f442357e477be1cb1550a31` |
| Fila/scheduler | `agent-center-central:noc-inventory-queue-final-20261112` | `55d39d774c5ef673abba78a0af1e90bfc036c52f337c1785a15ee780b43fcb28` |
| Worker diagnóstico | `agent-center-central:noc-inventory-diagnostic-final-20261112` | `b74651125a6a3d4bc98f53c1d3228474fdd5233138d57dd22743ff87e9037e19` |

Validação produtiva: criação/edição/desativação, três revisões, simulação idempotente, separação do histórico real, intenção, RLS e contexto sem visibilidade entre tenants passaram dentro de transação revertida. **Zero registros temporários persistidos, zero conexões a equipamentos, zero AgentTasks/diagnósticos criados.** Não foram semeados dados demonstrativos em produção. A Web autenticada passou em seis combinações de listagem/catálogo/cadastro e mobile/desktop; acesso anônimo exige login; nenhum erro JavaScript. Screenshots produtivos permanecem no diretório privado de evidências.

Health final: **PASS / VERDE**, webhook OK, filas sem falhas, readiness/HTTPS 200. A amostra final continha um job pendente e zero failed jobs, sem backlog anormal. A única alteração dentre as 16 tabelas protegidas comparadas foi a entrada da nova migration. RAG, Knowledge, Profiles/Skills, releases, quarentena, operações Lifecycle e configuração do Agent permaneceram iguais. Agent **0.6.5**, identidade e política revisão **4** preservados; Preflight sem alteração de fonte. Dos 220 arquivos preexistentes monitorados, somente o compose recebeu a troca autorizada das quatro referências de imagem; os demais foram preservados por hash.

Sessão temporária de validação revogada. Rollback preparado e não acionado: `/root/noc-inventory-private/rollback.sh`, restaurando as três imagens Preflight Real Flow e preservando tabelas/RLS/histórico. Backup restaurado/verificado: `20261112T203517Z`. Não houve alteração de R2, acesso SSH/SNMP/NETCONF, instalação de executores, mudança de ACLs, de device freeze ou de release em quarentena.

Evidências: [serviços e RLS em produção](evidence/noc-inventory/production-proof.json), [Web](evidence/noc-inventory/production-browser-proof.json), [Health](evidence/noc-inventory/production-health.json) e [preservação](evidence/noc-inventory/preservation-proof.json).

**AGENT CENTER NOC INVENTORY V1 LAB PASS — publicado após autorização operacional expressa.** A limitação permanece explícita: inventário e simulação não habilitam diagnósticos reais em equipamentos; nenhuma capacidade de equipamento está AVAILABLE.
