# Telegram V1 — operação, publicação e rollback

## Escopo e arquitetura

Novo canal Laravel da Conversation Engine existente. TelegramAdapter produz o mesmo IncomingMessage/Identity; Engine, Adaptive Tone, Language, RAG/Knowledge, cliente OpenAI e Task Engine continuam únicos. Não existe executor remoto. A IA não recebe ferramentas nem autoridade operacional.

Código em checkout isolado `/root/agent-center-telegram-lab`, a partir de `c65d949`. Nenhuma publicação de produção autorizada nesta missão. Não alterar Agent, Lifecycle, Observability, quarentena 0.6.3, R2 ou equipamentos.

A extensão do núcleo limita-se à allowlist de canais, armazenamento do contato, cercamento de identidade/canal e confirmação obrigatória para solicitações Telegram. A confirmação já publicada continua consumida transacionalmente pelo mesmo ramo da engine. Pendências recebem `issued_at`; a expiração adicional do Telegram usa esse instante, não é renovada por saudações. A Web mantém seu comportamento operacional anterior.

## Criar e cadastrar o bot com segurança

1. No aplicativo oficial Telegram, abra o [BotFather](https://t.me/BotFather), execute `/newbot` e siga as instruções. Use bot exclusivo de homologação nesta fase.
2. Na Central autenticada por HTTPS, selecione o tenant e abra **Telegram** no menu Canais. Apenas administradores com `manage-intelligence` podem cadastrar credenciais, configurar webhook, testar ou reenviar respostas.
3. Cadastre o token somente no campo de senha **Bot Token**. Não envie token neste chat, tickets, screenshots, comandos de shell ou Git. O servidor valida `getMe`, guarda a credencial criptografada e nunca a devolve ao navegador. Um bot não pode ser reutilizado por outro tenant, inclusive após rotação do token.
4. Registre o webhook pelo botão do painel. O endereço deriva de APP_URL HTTPS e da identidade do tenant/bot; não aceita endpoint arbitrário. `secret_token` é gerado criptograficamente, armazenado criptografado e enviado ao Telegram sem exibição.
5. **Testar integração** verifica a identidade do bot e o webhook remoto. Confira estado, última verificação, causa sanitizada de falha, filas e eventos no painel.

Criptografia usa o Crypt nativo do Laravel e APP_KEY. Backup deve incluir banco e chave, em armazenamento protegido. Não trocar APP_KEY sem procedimento de rotação/recuperação compatível. Não registrar headers, bodies ou URLs da Bot API: a credencial faz parte do caminho oficial da API. O cliente captura erros de transporte e retorna somente códigos sanitizados.

## Vínculo de identidade

- Usuário autenticado com as mesmas permissões de conversa/diagnóstico exigidas na Web gera código aleatório de 48 caracteres, válido por 10 minutos. Apenas HMAC fica no registro de vínculo.
- O código é mostrado numa única resposta `no-store`, sem flash/session/URL. Envie `/start CODIGO` em conversa **privada** com o bot configurado.
- O webhook autenticado verifica `from.id`, `chat.id`, tipo private, remetente não bot e ausência de encaminhamento/via_bot. Nome, telefone e username não vinculam identidade.
- O código é consumido uma única vez e captura o ID Telegram. Volte ao painel, confira esse ID e clique **Confirmar meu ID Telegram**. Só o próprio usuário pode aprovar, dentro da validade.
- Até essa aprovação, não há acesso à engine, Knowledge privada ou diagnóstico. Um ID Telegram pode representar contas distintas apenas em bots distintos, cada um de seu tenant.
- Cada processamento e entrega recarrega usuário, tenant e permissões. Usuário desabilitado, senha pendente, vínculo revogado, bot desabilitado ou fontes revogadas bloqueiam o uso. As políticas RLS existentes não foram ampliadas.
- Revogação pelo painel permite ao usuário revogar o próprio vínculo e ao administrador revogar vínculos do tenant. A confirmação operacional pendente é invalidada e respostas privadas enfileiradas são suprimidas. Revogar o canal não cancela silenciosamente diagnósticos já autorizados: use `/cancelar` antes ou o cancelamento nativo no painel.

## Experiência do usuário

| Entrada | Comportamento |
|---|---|
| `/start CODIGO` | Solicitar vínculo; exige aprovação posterior no painel |
| `/start`, `/help`, `/ajuda` | Identificação do assistente virtual e ajuda |
| Conversa natural | Mesmo tom e intenções da Web, sem sondagens sociais |
| `/knowledge pergunta` | RAG do tenant pelo serviço existente; fontes revalidadas |
| Pedido de diagnóstico | Sempre pede confirmação explícita no Telegram |
| `Sim, confirmo o diagnóstico de google.com.` | Consome apenas a pendência válida; alvo e perfil validados pelo núcleo |
| `/agents` | Lista limitada de Agents autorizados do tenant |
| `/nova [UUID do Agent]` | Cancela o contexto anterior pelo protocolo nativo e abre nova conversa, opcionalmente selecionando Agent |
| `/status`, `/resultado` | Sincroniza estado e resultados oficiais, sem reexecutar tarefas |
| `/cancel`, `/cancelar` | Cancelamento determinístico pelo Task Engine |
| `/unlink`, `/desvincular` | Pede confirmação explícita de desvínculo |
| `/desvincular confirmar` | Revoga o próprio vínculo |

Contexto não migra entre usuários, tenants ou canais. Histórico Telegram pode ser lido pelo proprietário na Web, sem compositor de mensagens desse canal. URLs de fontes e histórico exigem login, tenant e permissões no painel. Respostas Telegram são texto simples, sem parse_mode ou prévia de links, com proteção de conteúdo; são limitadas a 4000 caracteres. Texto longo pode ser abreviado; histórico completo permanece no painel. Referências incluem título, versão, trecho e URL autenticada. O envio revalida fontes publicadas; fonte revogada suprime a resposta inteira, inclusive o texto previamente gerado.

Confirmações têm validade de 5 minutos e precisam ser posteriores ao pedido. Updates com mais de 15 minutos ou data futura inválida não executam ações. Cancelamento, negação, desvínculo, rotação do bot e mudança de contexto impedem reutilização de pendências. Falta de Agent, capability/Profile ou política autorizada continua explicada pelo núcleo existente.

## Filas, recuperação e limites

- Webhook grava inbox criptografada com unicidade por bot/geração/update e por mensagem privada. Mesmo update com conteúdo diferente retorna 409. Replay idêntico retorna 200 sem nova execução. Credencial do webhook inválida retorna 401; payload inválido 422; flood 429.
- Aceite HTTP ocorre após persistência; processamento via jobs nativos `ProcessUpdate`. Locks e a transação da engine cercam inbox, contexto, histórico e outbox. Jobs contêm apenas tenant/UUID; não contêm texto, código ou credenciais.
- Configurar conexão de fila **database**, nunca sync em produção. Worker Laravel existente processa jobs; o diagnóstico continua exclusivamente no Task Engine. Não é necessário modificar workers Python.
- `TELEGRAM_ENABLED=false` é o padrão. `TELEGRAM_TENANTS=1,2` habilita varredura explícita de tenants configurados, sem enumeração global que contorne RLS. Scheduler Laravel deve executar `schedule:run` a cada minuto. `telegram:drain ID` recupera inbox/outbox, expira códigos, elimina corpos de inbox atrasados e sincroniza resultados oficiais pelo Engine::sync.
- Limites: 20 mensagens/minuto/remetente, 120/minuto/bot; proteção de ingresso 300/minuto/IP; administração 10/minuto/usuário. Mantidos os limites OpenAI/RAG existentes. Payload de ingresso até 32 KiB, texto até 4000 caracteres; só mensagens de texto privadas.
- Processamento transitório tem até 5 tentativas pela recuperação persistente. Permissão/dados rejeitados viram DENIED, visíveis ao administrador. Nenhuma exceção contendo URL com token é propagada.
- Entrega usa outbox e claim SENDING durável antes do HTTP. 429 respeita retry_after, até 5 tentativas. Timeout/5xx/interrupção ficam UNKNOWN; **não** há reenvio automático de resultado incerto. A Bot API não oferece chave de idempotência no sendMessage: não se promete exactly-once de entrega externa. Retentativa administrativa exige reconhecer possível duplicação da resposta e nunca reprocessa diagnóstico.
- Bot bloqueado registra causa e revoga o vínculo. Desabilitar o bot (inclusive falha ao registrar webhook) troca a geração, revoga vínculos e invalida pendências. Geração antiga suprime entregas e ignora inbox antiga. Reativação exige novo vínculo.
- Corpos de updates são apagados após consumo ou expiração de 15 minutos; notices criptografadas da outbox expiram em 7 dias. Histórico segue a retenção da Conversation Engine. A limpeza privilegiada existente remove referências Telegram antes do histórico; o runtime não ganhou DELETE nem BYPASSRLS.

## Publicação futura — depende de autorização específica

1. Revisar os commits desta entrega contra a revisão operacional, preservando alterações posteriores e os ajustes locais de layout. Não sobrescrever o checkout operacional nem copiar arquivos de fixtures para o runtime público. Audit.php e layout.blade.php possuem ajustes operacionais locais: integrar aditivamente os eventos Telegram e a navegação, preservando esses ajustes; não substituir esses arquivos inteiros pelo checkout de laboratório.
2. Fazer prechecks e backup verificável do banco/chaves com o procedimento local aprovado, sem alteração de R2. Registrar imagem anterior e validar restore isolado.
3. Publicar imagem Laravel compatível com a migration e grants aditivos. Aplicar migration pelo migrator e `deploy/database-roles.sql` pelo procedimento aprovado. As quatro tabelas novas exigem FORCE RLS; runtime continua NOSUPERUSER/NOBYPASSRLS e sem DELETE nelas.
4. Inicialmente manter TELEGRAM_ENABLED=false e TELEGRAM_TENANTS vazio. Rodar regressões, readiness/HTTPS/login/Operations Health e Web/RAG/OpenAI.
5. Para ingresso público, revisar e instalar **somente** `deploy/telegram/webhook-location.conf` dentro do vhost HTTPS da Central, com `nginx -t` antes de reload autorizado. A ACL atual do painel continua intacta. Não abrir globalmente o painel nem os endpoints de Agent/Lifecycle. Não registrar segredo em logs. Esse include foi validado sintaticamente, **não foi instalado em produção**.
6. Habilitar flag e scheduler para o tenant autorizado; cadastrar bot de homologação, registrar webhook e concluir vínculo real. Testar Telegram → inbox → fila → engine → outbox → Telegram, duas identidades/tenants e dados sintéticos, com custo OpenAI limitado.
7. Conferir getWebhookInfo, respostas e fontes, ausência de novas tarefas sociais, idempotência, cancelamento, falhas e Health. Só então considerar publicação aprovada. Credenciais reais de Telegram não estavam disponíveis nesta missão; esta etapa externa permanece pendente.

## Rollback que preserva dados

Rollback funcional preferencial: definir TELEGRAM_ENABLED=false e TELEGRAM_TENANTS vazio na imagem compatível, desabilitar webhook pelo painel antes da flag quando acessível, remover apenas o include público Telegram e validar Nginx. Inbox/outbox existentes não executam novas ações; não apagar histórico ou vínculos. Invalidar vínculos/pendências pelo painel antes de desabilitar, quando necessário. Verificar HTTPS/readiness/Health e Web/RAG.

A migration é aditiva. **Não executar down após existir histórico Telegram**; uma constraint validada sobre todas as linhas impede esse rollback mesmo quando RLS esconde registros. O ensaio automático de migrate/down ocorreu somente em banco descartável sem dados persistentes. Não restaurar integralmente o banco operacional para remover um canal.

Não voltar cegamente à imagem pré-Telegram depois de criar histórico: ela não possui o cercamento de canal nem a retenção compatível. Se um rollback binário for indispensável, preparar previamente imagem com o canal desabilitado que retenha as proteções de identidade/canal e a migration, testar em restore e obter autorização operacional. O rollback por flag preserva as funcionalidades Web anteriores e o patch de confirmação, sem alterar Agent, equipamentos ou R2.

## Referências primárias

- [Telegram Bot API: webhook, secret_token, updates e sendMessage](https://core.telegram.org/bots/api)
- [Telegram Bot Features: comandos e vínculo por start](https://core.telegram.org/bots/features)

## Reproduzir a homologação

Instalar dependências PHP apenas no container do projeto e gerar assets com npm ci/build antes de iniciar o painel. `scripts/test.sh` prepara Composer e usa PostgreSQL descartável; passar explicitamente TEST_PG_CONTAINER, TEST_NETWORK, TEST_OWNER_ENV, TEST_RUNTIME_ENV e TEST_CA_FILE de laboratório. Não apontar para o banco operacional.

`scripts/telegram-lab.sh` exige esses argumentos de ambiente e TELEGRAM_LAB_DIR absoluto e privado. `start` recusa banco preexistente, cria apenas `agent_center_telegram_lab`, gera senha/chave sintéticas e inicia Laravel em rede Docker interna, sem porta publicada. O transporte Telegram é explicitamente simulado e restrito ao nome desse banco. Sequência: `start`, `browser link`, `worker`, `browser approve`, `worker`, `browser screenshots`, `browser revoke`, `worker`. O worker grava provas sanitizadas e a referência do histórico para o navegador. Nenhuma fixture deve ser instalada em produção.

O teste OpenAI real desta entrega utilizou somente leitura da configuração autorizada, um banco sintético isolado e mount da chave existente somente para leitura durante a chamada limitada. Não alterou o binding operacional. Evidência separa claramente OpenAI real de transporte Telegram simulado. Para repetir, usar configuração LLM autorizada no ambiente de homologação com opt-in de linguagem, dados sintéticos e orçamento explícito; não copiar credenciais para Git.

Reativação após rollback por flag: antes de expor novamente o ingresso, desabilitar o bot pelo painel compatível para trocar a geração/revogar vínculos e invalidar quaisquer pendências retidas durante a interrupção. Depois registrar webhook e realizar vínculos novos. Nunca reativar simplesmente uma fila antiga após interromper o worker.
