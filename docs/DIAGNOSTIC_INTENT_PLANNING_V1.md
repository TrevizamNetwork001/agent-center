# Diagnostic Intent Planning V1 — laboratório

## Causa raiz

O reconhecimento anterior de caminho aceitava algumas expressões fixas, como
“teste de rota” e “MTR”, mas não “Queria avaliar essa rota aqui 8.8.8.8, ver se está
boa.”. O extrator reconhecia o IPv4 como destino. Sem intenção operacional de
caminho, o seletor de Profile retornava SITE_LENTO por padrão. Um destino IP não
era distinguido de um pedido de disponibilidade HTTPS.

O Planner de SITE_LENTO agendava TCP/443 e HTTPS, com ICMP complementar e
traceroute condicional a falha. Conectividade normal podia dispensar traceroute.
A classificação NORMAL era correta para aquelas sondagens, mas não respondia
à pergunta sobre rota. A apresentação não tinha um contrato separado de cobertura.

Além disso, a ramificação de MTR reaproveitava o Profile anterior e acrescentava
MTR ICMP e TCP, mantendo sondagens de site desnecessárias. V1 seleciona os Profiles
MTR existentes diretamente e não herda esse escopo.

## Matriz de decisão

| Intenção operacional | Profile | Skills pertinentes |
| --- | --- | --- |
| ROUTE_PATH_ANALYSIS | MTR_ICMP ou MTR_TCP existentes | mtr_path, em um protocolo explicitado na confirmação |
| ROUTE_PATH_ANALYSIS, alternativa limitada | CAMINHO_TRACEROUTE | traceroute ICMP; alternativa identificada antes da confirmação |
| BASIC_CONNECTIVITY | CONECTIVIDADE_BASICA | icmp_ping; não comprova caminho |
| WEB_AVAILABILITY | SITE_NAO_ABRE | tcp_connect/443, http_probe HTTPS |
| DNS_RESOLUTION | PROBLEMA_DNS | dns_lookup A/AAAA; UDP/TCP com resolver existente quando compatível |
| LATENCY_PACKET_LOSS | PERDA_LATENCIA | icmp_ping no destino; não transforma ping em análise de rota |
| SERVICE_PORT_CHECK | PORTA_SERVICO | tcp_connect, somente 443 conforme o contrato existente |
| GENERAL_SLOWNESS | SITE_LENTO, GOOGLE_YOUTUBE ou CONTEUDO_LENTO | sondagens iniciais DNS/TCP/HTTPS e complementos condicionais dos Profiles existentes |

Para hostname, DNS A/AAAA pode ser pré-requisito para resolver o destino. O plano
registra essa justificativa. Para o IP 8.8.8.8, análise de caminho não inclui DNS,
TCP connect, HTTP, HTTPS ou ping complementar. MTR TCP é análise de caminho com
sondas TCP/443; não é a Skill tcp_connect.

Os três Profiles adicionados usam apenas Skills já existentes. Nenhuma nova Skill
ou capability foi criada. Os contratos legados dos Profiles existentes continuam
disponíveis; a seleção explícita por objetivo usa a versão 1 do planejamento.

## Plano antes e depois

Pedido reproduzido literalmente: “Queria avaliar essa rota aqui 8.8.8.8,
ver se está boa.”

| Etapa | Antes | Depois, MTR ICMP autorizado |
| --- | --- | --- |
| Objetivo registrado | Ausente | ROUTE_PATH_ANALYSIS |
| Profile | SITE_LENTO | MTR_ICMP |
| Obrigatórias | TCP/443, HTTPS | mtr_icmp |
| Complementares | ping, traceroute apenas em determinadas falhas | Nenhuma |
| Confirmação | Pedido genérico de diagnóstico | Objetivo, destino e MTR ICMP explicitados |
| Sondagem MTR | Não agendada | 5 ciclos, até 16 saltos, intervalo de 1 segundo |
| Conclusão | NORMAL podia ser confundido com rota saudável | Classificação técnica e cobertura separadas |

## Linguagem, contexto e confirmação

`IntentParser` classifica o objetivo de forma determinística, antes da seleção de
Profile e separadamente do Adaptive Tone. Tom formal ou informal não modifica
Skills, limites, política ou critérios de classificação. Declarações sociais e
queixas sem pedido operacional preservam o fluxo conversacional.

IP isolado, IP com lentidão sem escopo definido e opções ambíguas geram uma
pergunta objetiva, sem autorização pendente de execução. O destino é preservado
quando o usuário responde “rota”, “ping” ou “conectividade”. Porta não informada
também gera esclarecimento. Porta 22/80 não é substituída silenciosamente por 443.
Traceroute TCP não é substituído por traceroute ICMP.

Os dois canais usam o mesmo planejamento. Antes da confirmação, a Central
armazena o objetivo, Profile, destino e digest do plano. A confirmação continua
protegida por transação, locks, prazo e chave de idempotência existentes. Mudança
de escopo/disponibilidade invalida a confirmação; nenhum substituto é executado.

Exemplo Web e Telegram com Agent elegível e autorização recente:

> Usuário: Queria avaliar essa rota aqui 8.8.8.8, ver se está boa.
>
> Central: Posso analisar o caminho de rede até 8.8.8.8? Escopo: Caminho MTR
> (MTR ICMP). Confirma a execução? A autorização local permanece obrigatória.
>
> Usuário: Sim, confirmo.

Exemplo sem objetivo:

> Usuário: 8.8.8.8
>
> Central: Você quer analisar o caminho de rede, medir ping/perda no destino ou
> verificar um serviço? Um IP sozinho não define um site HTTPS. Nenhum teste foi iniciado.

## Capacidades e política

`PathReadiness` consome os contratos autenticados já existentes de Protocol,
ManagedSettings e Observability, sem modificar esses módulos ou conceder permissões.
Verifica capability anunciada/compatível, Skill habilitada e segura, helper MTR
instalado/ativo/autenticado/saudável, relatório recente, revisão/digest efetivos e
resultado de permissão ALLOWED para a mesma capability, destino e protocolo.

A mesma verificação ocorre na preparação do plano e antes do claim da tarefa de
caminho. A autorização definitiva local continua obrigatória no Agent/helper.
Nenhuma ACL é ampliada e o device freeze permanece intacto. Relatório ausente,
antigo ou sem permissão específica não é interpretado como autorização.

Quando a permissão não está comprovada, a resposta orienta usar a verificação de
permissões que já existe no painel do Agent e solicitar novamente. Nenhuma nova
sondagem ou consulta de permissão é disparada automaticamente por essa resposta.
Um administrador autorizado pode usar o fluxo existente; operadores não ganham
novos privilégios. Helper indisponível e política negada explicam o impedimento.
Conectividade básica é oferecida como alternativa distinta, somente mediante novo
pedido e confirmação, sem afirmar que ela cobre a análise de rota.

## Classificação e cobertura

`DiagnosticConclusion.classification` continua sendo a conclusão determinística dos
testes. `ObjectiveCoverage` deriva uma avaliação separada das evidências persistidas
e do plano; não modifica conclusões históricas.
Os critérios de suficiência MTR usam os thresholds registrados no próprio plano,
inclusive quando um Profile existente tem limites personalizados.

Exemplo de sondagens de conectividade normais com objetivo de rota não atendido:

```text
Classificação técnica dos testes: NORMAL
Análise de rota: NÃO EXECUTADA
Conclusão da solicitação: INCONCLUSIVO
```

O destaque principal do painel usa a conclusão da solicitação, preservando a
classificação técnica em campo separado. Web e Telegram informam cobertura e
limitações. MTR com amostra insuficiente ou destino não confirmado pode ter sido
executado, mas a solicitação permanece inconclusiva. Erro do helper permanece
ERRO_DE_EXECUCAO técnico, com objetivo não executado e solicitação inconclusiva.

GENERAL_SLOWNESS é cobertura parcial: sondagens não provam qualidade de vídeo,
banda ou todas as causas de lentidão. Traceroute não supre métricas MTR de perda
por salto; um pedido dessas métricas continua parcial/inconclusivo nessa alternativa.

A análise MTR existente é reutilizada: alcance do destino, saltos, RTT e perda no
destino. Perda intermediária isolada não prova falha de encaminhamento quando os
saltos seguintes respondem. Permanecem as limitações sobre ausência de respostas,
amostra curta, assimetria e ausência de identificação causal/ASN/localização.

## Validação e evidências

As execuções de teste são sintéticas, via contratos Laravel e PostgreSQL separado
de laboratório. Não há Agent Python executando sondagens ou equipamentos envolvidos.
O caso 8.8.8.8 passa pelo Conversation Engine, confirmação, planejamento, Task
Engine, claim/start/result e normalização de fixture MTR. Telegram usa seu ingresso,
worker e transporte HTTP falso existentes nos testes.

Cobertura exigida: sete intenções, plano mínimo, ausência de HTTPS/DNS para rota
em IP, capacidades/versão indisponíveis, policy denied, permissão para outro
protocolo/destino, helper unhealthy/failure, cancelamento, idempotência, revalidação
no claim, RLS e isolamento entre tenants, fixtures MTR e Profiles legados.

Evidências visuais e resultados finais ficam em
`docs/evidence/diagnostic-intent-planning-v1/`. O laboratório navegável está
restrito a loopback em `http://127.0.0.1:18820`; credenciais permanecem fora do Git.

Regressão completa: 488 testes, 3.668 asserções, sem falhas, erros ou testes
ignorados. Passaram também os testes em processos separados de claims concorrentes,
worker/result, fencing, idempotência e encerramento por inatividade. A rodada
específica posterior validou a apresentação de cobertura em “mais detalhes” e
exportou o transcript sintético do Telegram. Os resultados finais e o JUnit estão
no diretório de evidências.

Interface: 30 combinações de página, viewport (390/768/1440) e tema (claro/escuro),
sem overflow horizontal ou erros de JavaScript; acesso de outro tenant retornou
404. Build de produção PHP/Composer e Vite concluído em imagem de laboratório,
Pint e secret scan do índice Git aprovados. Nenhuma dependência foi alterada.

## Rollback e publicação

Entrega somente em laboratório. Não há autorização de publicação nesta tarefa.
Não há migração de schema, mudança no Python Agent, Lifecycle, Observability, R2
ou equipamentos. Os motores existentes continuam responsáveis pela execução.

Para rollback de uma futura publicação autorizada: interromper novas solicitações,
deixar tarefas em andamento concluírem ou cancelá-las pelo mecanismo existente,
e republicar a imagem anterior da Central. Preservar tabelas, planos, evidências,
históricos e novos Profiles. Não apagar dados nem reescrever conclusões. Os três
Profiles V1 novos devem ser desabilitados pelo fluxo administrativo existente
antes de retornar a uma imagem que não suporte suas chaves. Na retomada,
usar uma imagem compatível com os Profiles V1 para qualquer diagnóstico pendente;
não entregar esses Profiles a um worker anterior que desconheça suas chaves.

Para desfazer somente o laboratório, parar/remover apenas o container
`intent-planning-v1-web` e, se solicitado, o banco sintético criado por este fluxo.
Não executar limpeza genérica, prune, remoção de volumes ou ações em produção.
