# AGENT CENTER NATURAL CONVERSATION LAB PASS

Entrega em `/root/agent-center-natural-conversation-lab`, derivada de `d15c7b7` (correção de confirmação, ainda não publicada). Núcleo desta evolução: commit `63db3d5`. Laravel e PostgreSQL existentes; nenhum segundo motor, SDK de IA ou conector foi criado. Produção não foi publicada nem habilitada para geração social.

## Problema e comportamento entregue

O Adaptive Tone anterior classificava poucas palavras e acrescentava basicamente “Fechou.” ao texto. A Engine não tinha intenções sociais: saudações, humor e perguntas informais caíam na resposta genérica “Informe o site…”. O início de um diagnóstico também substituía o contexto JSON inteiro, perdendo sinais conversacionais.

A Engine agora distingue GREETING, SOCIAL e CHANGE_TOPIC dentro do mesmo conjunto de intenções. Saudações recebem respostas locais variadas, sem menu de testes. Relatos de lentidão recebem acolhimento contextual; incidentes e linguagem técnica priorizam precisão e evidências. A aplicação não atribui causa de falha sem evidências e não transforma um relato informal em autorização. A intenção operacional continua decidida pelo parser, pelos estados e pelas Policies/Gates existentes.

O detector usa sinais recentes de formalidade, termos técnicos e descontração. Mantém `regionality=NOT_INFERRED`. Pedidos explícitos como “Prefiro um tom formal.” e “Pode falar mais descontraído?” salvam a preferência nesta conversa. O tom técnico de incidentes prevalece sobre a preferência de descontração. Nenhuma identidade, origem regional ou dado pessoal é deduzido desses sinais.

Perfil, destino, diagnósticos anteriores, histórico, assunto, preferência de tom e contadores de linguagem são preservados. Destinos informados numa reclamação ficam registrados como dados conversacionais validados, sem iniciar tarefa. Um pedido técnico posterior pode reutilizá-los. Saudações não consomem confirmação pendente. Mudança explícita de assunto descarta essa confirmação; cancelamento e encerramento também a invalidam, preservando evidências.

## Mesmo núcleo e mesmas fronteiras operacionais

`WebAdapter` fornece um `IncomingMessage` imutável com identidade verificada, chave estável e texto validado pelo FormRequest. O controller web entrega esses dados à mesma `Engine::send`. `Language` apenas produz texto e metadados; não seleciona intenção, não muda estado, não cria diagnóstico e não executa Skills.

A frase “Sim, confirmo o diagnóstico de google.com.” continua usando exclusivamente a pendência do estado WAITING_FOR_CONFIRMATION. A correção herdada de `d15c7b7` consome essa pendência na mesma transação e sob os locks existentes, valida destino/perfil/capabilities e usa o Task Engine existente uma única vez. Retry com a mesma chave não refaz a chamada nem o diagnóstico. Mudança de conteúdo com chave reaproveitada retorna 409. Resposta afirmativa fora do estado correto não autoriza execução.

RAG V1/Knowledge permanecem na rota existente `Answer::run`, incluindo fontes revalidadas, revogação e resposta honesta de falta de evidências. Nenhum código de RAG, classificação técnica ou execução do Agent foi alterado.

## Geração de linguagem e autorização

Não há cadastro paralelo de provider, model, prompt ou binding. A apresentação reutiliza `Client`, `SecretVault`, `NetworkPolicy`, `PromptRenderer`, `Output` e um binding habilitado de `diagnostic_explanation` ou `knowledge_assistance`, com provider/model habilitados e prompt publicado do mesmo purpose. Há duas permissões cumulativas: `allow_external_context` e o novo opt-in `allow_conversation_language`. A segunda pode ser configurada somente pela administração, no formulário existente; bindings antigos permanecem sem chamadas sociais automáticas. `diagnostic_summary` não concede essa permissão.

O opt-in autoriza somente linguagem social/apresentação. Saudações simples, preferência de tom, mudança de assunto, relatos técnicos, incidentes, confirmação, negativa, cancelamento e execução não precisam de inferência. Perguntas procedimentais/Knowledge seguem o RAG existente. Para a geração social são enviados até três textos recentes do próprio usuário, com até 300 caracteres cada, sanitizados, além de sinais mínimos de tom e contexto. Não são enviados resultados de equipamentos, dumps de Knowledge ou histórico de outros tenants.

Limites adicionais: três gerações por usuário/minuto, doze por tenant/minuto e doze por conversa; chaves de limite incluem tenant. O timeout é limitado ao menor valor entre o provider e dez segundos. Permanecem os limites de contexto/saída, SSRF, DNS pinning, HTTPS/TLS e política de retries do Client existente. A UI mantém a chave em envio incerto e oferece fallback legível. A API OpenAI continua com uma tentativa por chamada, sem tools, `store=false` e teto de 1.024 tokens de saída. Referência consultada: [OpenAI Responses API](https://developers.openai.com/api/reference/resources/responses/methods/create).

O modelo devolve o schema já existente. A apresentação usa somente `summary`, limitado a 700 caracteres, e exige `next_steps=[]`. Texto com alegações operacionais, comandos, identidade humana, links/código ou termos ofensivos é rejeitado. Declarações explícitas de ausência de ação/identidade humana não são confundidas com execução; há teste para isso. Essa filtragem é defesa de apresentação: a autorização efetiva independe do texto gerado. Falha, limite, resposta inválida ou repetição exata usam resposta local. Metadados de status, binding/prompt, uso e request ID ficam no histórico; o evento `conversation.language_rendered` passa pela allowlist sanitizada da auditoria.

## Interface e capturas

Mantidas identidade visual, temas, componentes e navegação. A tela agora se chama “Conversa”, separa o histórico do progresso técnico, usa placeholder aberto e indica “Preparando resposta…” com `aria-busy`. Após enviar, a resposta recente fica visível sem retirar foco do campo. A indisponibilidade da geração assistida tem aviso separado do horário.

O navegador do laboratório expôs uma limitação de `crypto.randomUUID` em origem HTTP local. A UI agora possui fallback UUID v4 com `crypto.getRandomValues`, sem usar aleatoriedade fraca, e gera a próxima chave antes de limpar o texto. Nenhuma regra de acesso/TLS de produção foi relaxada para fazer o teste passar.

As capturas são páginas Laravel efetivamente renderizadas com autenticação, sessão e CSRF nativos, no laboratório de contas e Agent sintéticos. O transporte LLM das capturas é uma fixture determinística, explicitamente identificada nos JSONs do navegador; as capturas não são apresentadas como inferência OpenAI real.

- [Conversa desktop](evidence/natural-conversation-20261111/browser/social-1440-light.png)
- [Conversa mobile](evidence/natural-conversation-20261111/browser/social-390-light.png)
- [Incidente, desktop escuro](evidence/natural-conversation-20261111/browser/technical-1440-dark.png)
- [Confirmação e resultado de bloqueio, mobile](evidence/natural-conversation-20261111/browser/confirmation-390-light.png)
- [Falha do modelo com fallback](evidence/natural-conversation-20261111/browser/failure-1440-dark.png)
- [Knowledge e falta de evidências](evidence/natural-conversation-20261111/browser/knowledge-390-light.png)

## Exemplos registrados

Estes turnos foram registrados no histórico real do laboratório; saudações são locais e a piada do navegador é fixture, sem chamada externa:

| Usuário | Resposta observada |
| --- | --- |
| Boa tarde, meu consagrado! | Boa tarde! Bom te ver por aqui! |
| E aí, meu rei, tudo certo? | Opa! Pode chegar, estou por aqui. |
| Salve, calabreso! | Opa! Tudo certo por aqui. Como vai? |
| Fala, meu parceiro! | Opa! Bom te ver por aqui! |
| Prefiro um tom formal. | Combinado, a preferência de tom está salva para esta conversa. |
| Meu BNG está apresentando perda de pacotes. | Entendi o relato. Ainda não há evidências suficientes para atribuir uma causa. Quando começou e qual é o alcance da perda ou indisponibilidade? Nenhuma ação em equipamentos foi iniciada. |
| Sim, confirmo o diagnóstico de google.com. | Confirmação processada para google.com. → diagnóstico encaminhado uma única vez → resultado INCONCLUSIVO por política do Agent sintético. |

O [histórico completo](evidence/natural-conversation-20261111/lab-transcripts.json) inclui Knowledge, falta de evidências e fallback, além da prova de RLS. As tarefas de laboratório foram encerradas por respostas sintéticas `policy_denied` através do protocolo do Task Engine; nenhum binário de Skill, runtime Agent, sondagem ou equipamento foi executado.

### OpenAI real, separadamente verificado

No banco isolado `agent_center_lab`, reutilizando provider/model/binding/prompt autorizados restaurados para homologação, mensagens exclusivamente sintéticas produziram:

> Usuário: E você, como é ajudar num plantão tranquilo?
>
> Agent Center: Boa tarde! Por aqui, como assistente virtual do Agent Center, um plantão tranquilo é uma boa oportunidade para conversar com calma e ajudar com dúvidas. E o seu, está sossegado?

A resposta final foi **ASSISTED, HTTP 200, 513 tokens de entrada + 66 de saída = 579**, uma tentativa, 3.117 ms, request ID `req_93762993fdcf491fadddb5179f82c7ee`. A saudação anterior foi local, sem API. A contagem de diagnósticos e tarefas permaneceu inalterada. O opt-in temporário do binding de homologação foi restaurado, e a conversa de teste foi encerrada. Não houve alteração de binding/provider/secret em produção.

Houve três requisições externas controladas no total nesta validação: uma atingiu timeout sem uso informado, outra retornou 200/596 tokens e foi rejeitada pela apresentação, e a chamada final acima passou. Não há retry automático da OpenAI. O filtro foi revisto com teste positivo para declarações de ausência de ação/identidade humana. Os dois retornos com uso informado somam 1.175 tokens; o uso/custo da requisição em timeout não foi informado, portanto este número não é apresentado como faturamento total. Não foi feita estimativa monetária.

Evidências: [resultado final](evidence/natural-conversation-20261111/openai-real.json), [primeira rodada e fallback](evidence/natural-conversation-20261111/openai-initial.json), [precheck de configuração sanitizado](evidence/natural-conversation-20261111/openai-config-precheck.json). O container temporário com acesso à chave foi removido e a cópia temporária do env foi eliminada; a chave original permaneceu somente leitura e fora do Git.

## Validações

| Verificação | Resultado |
| --- | --- |
| Laravel/PostgreSQL, regressão completa | **335 testes / 2.443 assertions PASS** |
| ConversationTest + NaturalConversationTest | 52 testes incluídos, sendo 16 cenários novos nesta evolução |
| Saudação casual/formal, gírias, humor e transição técnica | PASS; sem tarefas por conversa social |
| Preferência de tom persistente e precedência de incidentes | PASS |
| Destino conhecido, pendência, perfil e histórico | PASS |
| Confirmação curta/natural/exata; negativa, cancelamento e assunto novo | PASS |
| Retry HTTP, mensagem duplicada, confirmação antiga e refresh | PASS |
| Knowledge, fonte, ausência/revogação de evidências | PASS, regressão RAG existente preservada |
| Falha/saída insegura do LLM, ausência de opt-in e limites | PASS |
| Tenant isolation, RBAC e CSRF | PASS; navegador também conferiu 404/419/409 |
| PostgreSQL/RLS | Runtime sem superuser/BYPASSRLS; RLS e FORCE RLS ativos nas oito tabelas verificadas de conversa/LLM/Knowledge |
| Navegador | 30 combinações de tela/largura/tema; 22 screenshots; 390/768/1440 px; sem overflow ou erros JavaScript |
| Interface, resultado e fallback | PASS; última captura inclui conclusão por política negada |
| Pint | PASS em 21 arquivos PHP |
| Build frontend (`npm ci && npm run build`) | PASS, lockfile existente |
| Sintaxe JavaScript, script de laboratório e PHP | PASS |
| Secret scan básico do índice e diff check | PASS antes dos commits |
| Produção | Imagem RAG V1 original saudável; nenhum deploy executado |

Os testes usam apenas `agent_center_test` descartável no PostgreSQL de laboratório; migrations e rollback de teste passaram. Não há migration nova nesta evolução. O build executado foi o frontend existente. O laboratório usa o container PHP/tooling existente com o código montado; não foi criada nem publicada uma imagem de produção desta evolução. Os overrides em `deploy/conversation-confirmation/` pertencem à entrega anterior e não constituem rollout desta funcionalidade.

## Reprodução e revisão

Ambiente preservado para revisão local: `http://127.0.0.1:18766`, container `natural-conversation-web`, banco `natural_conversation_lab` no PostgreSQL de laboratório, estado restrito em `/root/natural-conversation-state`. Credenciais sintéticas não estão neste documento nem no Git. Não há listener público, worker de diagnósticos real ou Agent conectado a equipamentos.

Regressão, a partir deste checkout:

```sh
TEST_PG_CONTAINER=rag-v1-postgres TEST_NETWORK=rag-v1-lab TEST_OWNER_ENV=/root/rag-v1-lab/owner.env TEST_RUNTIME_ENV=/root/rag-v1-lab/runtime.env TEST_CA_FILE=/root/rag-v1-lab/no-ca scripts/test.sh
```

`scripts/natural-conversation-lab.sh` reproduz setup, fixtures, browser, resultados e capturas em ambiente ainda vazio com o PostgreSQL/rede de laboratório existentes. Recusa diretório/container ou banco preexistentes e não remove dados para forçar execução. O guard de colisão foi validado na instância preservada. Os comandos equivalentes do setup e do runner foram executados nesta entrega. O script não lê env/keys de produção nem chama OpenAI real. O teste real fica em `tests/Support/natural-live.php`, separado, com guard explícito de laboratório, banco obrigatório e orçamento de uma requisição por execução; não é rota web nem parte automática do runner.

Para interromper a prévia, parar somente `natural-conversation-web`, mantendo banco e evidências para revisão. Não parar o PostgreSQL/rede compartilhados por outros laboratórios. Nenhum comando de deploy está autorizado por esta entrega.

## Contrato para canais futuros e limites

Um futuro adapter Telegram/WhatsApp deve verificar assinatura/origem, replay e vínculo de conta antes de produzir `Identity` e `IncomingMessage`; telefone ou contact ID sozinhos não autenticam alguém. A chave de mensagem deve ser estável e incluir o namespace do canal/tenant/usuário para reaproveitar a deduplicação. O adapter precisa aplicar validação de tamanho/formato e limites do canal. Histórico, autorização e decisões continuam nesta Engine. Hoje `allowed()` aceita somente web; identidades não web continuam rejeitadas, inclusive em teste. Não foi implementado webhook, bot, conector ou cadastro de canal.

As respostas locais são variações controladas; a geração social exige opt-in e disponibilidade do provider. Nenhuma preferência de estilo muda autorização, classificação ou política de diagnóstico. O freeze de equipamentos permanece. Agent, Lifecycle Manager, R2, equipamentos e a release 0.6.3 em quarentena não foram alterados. Uma eventual publicação exige autorização operacional específica e preparação/reconciliação de imagem, rollout e rollback sobre a versão de produção então ativa. O trabalho desta entrega termina no laboratório.
