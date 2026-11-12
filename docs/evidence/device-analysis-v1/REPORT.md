# Homologação Device Analysis V1

Estado final: **AGENT CENTER DEVICE ANALYSIS V1 LAB PASS**. Sem publicação em produção. Homologação encerrada em 2026-11-13.

Implementação: `2186588`. Base operacional conferida: `cd2aa49` / NOC Inventory final, Agent 0.6.5. Desenvolvimento exclusivamente no checkout LAB.

## Escopo verificado

* Sete contratos tipados no catálogo existente, sem nova migration e sem endpoint remoto.
* 34 cenários especializados, preservando as 25 capacidades e os sete cenários originais.
* Huawei NE8000/VRP 8 e MikroTik CCR/RouterOS 7 com matriz fechada; RouterOS 6, VRP 5 e versões não reconhecidas rejeitadas.
* Intenção de BGP/contadores/tabela de rotas/recursos separada de MTR. Pedidos reais indisponíveis, sem confirmação ou tarefas substitutas.
* Interpretação local, sem causa raiz inventada, sem comparar contador acumulado com zero como critério de falha e sem threshold universal de recursos.
* Web e Telegram reutilizam o Engine e a apresentação V2. Simulador não faz rede; explicação opt-in separada só permite escolher fatos aprovados.

## Validação

Resultados finais:

| Verificação | Resultado |
|---|---|
| Laravel completo em PostgreSQL/FORCE RLS | PASS — 545 testes, 4.649 asserções, sem warnings |
| Concorrência Task Engine, conversa e Preflight | PASS |
| Concorrência específica do simulador | PASS — quatro processos, uma simulação e um evento de auditoria, zero tarefas |
| Pint completo | PASS — 342 arquivos |
| Build Vite / npm audit | PASS — zero vulnerabilidades apontadas |
| Web, teclado, mobile e temas | PASS — 18 combinações, sem erros de página ou overflow |
| Telegram / RAG / LLM / RBAC / CSRF / dois tenants / Task Engine | PASS na suíte completa e casos especializados |
| Matriz declarada de modelos/releases | PASS — 20 apresentações LAB, sem qualificação de coletor real |
| Autoload, Blade e paridade de rotas das três imagens | PASS |
| Rollback das migrations no banco descartável | PASS — banco de teste removido ao final |
| Diff check | PASS |

[Validação e hashes dos logs](validation.json), [matriz declarada](compatibility-matrix.json), [paridade de rotas](route-parity.json), [preservação operacional](preservation.json), [guarda de escopo](scope-proof.json) e [imagens candidatas LAB](candidate-images.json). Secret scan básico do índice Git: PASS, sem achados. Não substitui um scanner especializado externo.

 O cenário de concorrência específico usa quatro processos independentes, uma simulação imutável e um evento de auditoria; nenhuma tarefa operacional.

A primeira regressão completa encontrou duas verificações que precisaram acompanhar o novo contrato: contagem fechada de intents de 17 para 19 e comparação de imutabilidade depois da hidratação PostgreSQL/JSONB. A verificação direcionada do formulário encontrou e corrigiu referências opcionais vazias convertidas para null pelo middleware nativo. Campos extras continuam rejeitados, sem reflexão do valor na sessão. Nenhuma classificação operacional foi relaxada.

## Evidências visuais

A revisão com Playwright cobriu 18 combinações: NE8000, RouterOS 7 e conversa Web; 390, 768 e 1440 px; temas claro e escuro. Incluiu teclado nos detalhes, ausência de overflow, formulário nativo/CSRF, destino fixo da fixture, referências opcionais de recursos e envio de simulação na conversa. Nenhum erro de página. [Prova sanitizada](ui-proof.json).

| Tela | Desktop claro | Mobile escuro |
|---|---|---|
| Huawei NE8000 | [Screenshot](device-analysis-ne8000-1440-light.png) | [Screenshot](device-analysis-ne8000-390-dark.png) |
| MikroTik RouterOS 7 | [Screenshot](device-analysis-routeros7-1440-light.png) | [Screenshot](device-analysis-routeros7-390-dark.png) |
| Conversa Web | [Screenshot](device-analysis-conversation-1440-light.png) | [Screenshot](device-analysis-conversation-390-dark.png) |

Telegram foi validado pelo ingresso autenticado, processamento, outbox e entrega nativos com transporte HTTP fake, incluindo repetição de update e supressão após retirada da habilitação LAB. Não houve mensagem enviada a um bot real. Exemplo de resultado: “SIMULATED / LAB … O equipamento simulado possui 3 peer(s): 2 estabelecido(s) e 1 fora de Established … Consulta operacional: NÃO EXECUTADA”.

## Compatibilidade e preservação

As três imagens candidatas mantêm as rotas operacionais de cada papel: Web 136, queue 136, diagnostic 96. Autoload dos especialistas e compilação Blade foram verificados em cada imagem. Nenhum endpoint de comandos ou nova rota de execução foi adicionado. Os nomes e hashes finais das imagens são registrados com os resultados abaixo; são candidatas LAB, não imagens publicadas.

Os hashes dos 220 arquivos preexistentes do checkout operacional foram comparados novamente: sem alterações; HEAD operacional `cd2aa49` preservado. O AGENTS.md operacional foi copiado para orientar o checkout LAB e permanece fora dos commits desta entrega. Não houve acesso a equipamentos, sondagens, deploy, atualização de Agent, mudança de política/ACL/freeze/R2 ou retirada de quarentena.

## Limites e rollback

Alarmes Huawei: contrato DOCUMENTED, sem catálogo de códigos por release homologado ou consulta real. Modelos/versões compatíveis são declarações para fixtures, não qualificação de coletor CLI. Não há executor real nem autorização operacional concedida pelo inventário. Essa limitação é apresentada nos dois canais.

Flags especializadas continuam desligadas por padrão e são bloqueadas em `production` mesmo quando configuradas por engano. Laboratório exige ambiente local/testing, flag e tenant explicitamente habilitado. Sessões de QA não fazem parte dos artefatos Git.

Plano de publicação/rollback, schemas, regras e fontes: [documentação](../../DEVICE_ANALYSIS_V1.md). Nenhum deploy foi iniciado. Rollback futuro preserva simulações/revisões/auditoria e restaura imagens anteriores; não exige alteração de Agent ou schema.
