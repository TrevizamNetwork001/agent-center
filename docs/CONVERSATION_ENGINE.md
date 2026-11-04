# Conversation Platform

## Arquitetura e autoridade

Web → WebAdapter (ChannelAdapter) → sessão Laravel + Identity (tenant/user/channel/contact) → Conversation Engine → IntentDetector + ConversationToneDetector → state machine/context → Diagnostics Engine existente → Profiles/Skills fechadas → Agent Python 0.5.2.

Somente Web está implementado. Outros adapters terão de verificar remetente, contato e vínculo com usuário/tenant e aplicar autorização explícita; número de telefone ou Telegram ID, isoladamente, não autoriza operação. Nenhum webhook, bot ou API externa de conversa foi criado.

Conversation não executa comandos nem Skills diretamente. Reutiliza `Diagnostics\Engine::create/cancel`, Planner, catálogo e protocolo Agent API existentes. O worker original continua sendo autoridade sobre tarefas, leases, fencing, resultados e conclusão. Seis capabilities comprovadas, sem novas capabilities/device actions.

## Dados e isolamento

Migration `2026_11_04_210000_create_conversation_platform` cria:

- `conversation_settings`: ativação e retenção por tenant, default desabilitado.
- `conversations`: proprietário, canal, estado/status, Profile/diagnóstico/alvo atuais, intenção, contexto, relógios, sequência e creation_key.
- `conversation_messages`: sequência, remetente, tipo, texto sanitizado, intenção, metadata fechada e request_key.
- `conversation_events`: transições anterior/próxima, tipo e metadata sem texto do usuário.

Quatro tabelas FORCE RLS, scopes Eloquent e FKs compostas de tenant em referências técnicas. Total da aplicação: 27 tabelas FORCE RLS. Policies exigem tenant e ownership de usuário, inclusive para administradores: administração do rollout não concede leitura da conversa privada de outro usuário. Platform admin precisa selecionar tenant e só acessa suas próprias conversas. Viewer não pode criar/enviar; operator/admin podem investigar conforme DiagnosticPolicy. Histórico da UI é paginado; transcript limitado a 500 mensagens por conversa.

Runtime não é owner, não tem DELETE nessas tabelas e só possui SELECT/INSERT no histórico. Triggers impedem UPDATE em mensagens/eventos. Exclusão por retenção exige conexão migrator explícita. Audit global registra IDs e eventos, nunca conteúdo.

## Estados e intents

Estados: NEW, ACTIVE, WAITING_FOR_TARGET, WAITING_FOR_CONFIRMATION, DIAGNOSTIC_RUNNING, DIAGNOSTIC_COMPLETED, FOLLOW_UP, CANCELLED, CLOSED. Status acompanha OPEN/CANCELLED/CLOSED. `State::permits` fecha todas as transições possíveis; transição inválida retorna 409. Eventos são persistidos na mesma transação.

Intents fechadas: START_DIAGNOSTIC, CONTINUE_DIAGNOSTIC, PROVIDE_TARGET, REQUEST_MORE_DETAIL, REQUEST_ANOTHER_TEST, CONFIRM, DENY, CANCEL, CLOSE, ACKNOWLEDGE, HELP, UNKNOWN. Detecção determinística: não depende do LLM nem aceita intent/state enviados pelo cliente.

- Pedido explícito de teste com alvo válido cria diagnóstico usando identidade autorizada. Sem alvo, solicita-o na conversa atual. YouTube/Google têm aliases explícitos; hostname/IPv4/localhost são validados pelo Planner existente.
- Um hostname isolado fora de pedido pendente exige confirmação. Uma resposta curta de alvo em WAITING_FOR_TARGET completa o pedido anterior, sem criar outra conversa.
- Depois da conclusão, obrigado/valeu/era só isso/resolveu encerram sem nova execução ou oferta desnecessária.
- Agradecimento enquanto aguarda alvo/confirmação não cancela. Durante execução, apenas reconhece; encerramento explícito durante execução pede confirmação de cancelamento.
- CONTINUE preserva alvo, Profile, diagnóstico e evidências. Oferece novo escopo de perda/latência com confirmação, quando diferente do anterior. Não repete automaticamente o diagnóstico anterior. A oferta também verifica as capabilities atuais do Agent antes da confirmação; sem aprofundamento comprovado disponível, informa o limite.
- REQUEST_ANOTHER_TEST exige confirmação. Nova execução mantém IDs anteriores no contexto; evidências técnicas permanecem nas tabelas Diagnostics.
- CANCEL usa cancelamento nativo, de forma idempotente. Conversation fica CANCELLED imediatamente como intenção registrada; tarefas em execução terminam por reconcile/result/lease do Task Engine. A UI continua mostrando o estado técnico real. Resultados anteriores não são apagados.
- CLOSED nunca reabre. Nova solicitação explícita cria outra Conversation e retorna sua URL. Reenvio da mesma mensagem retorna a Conversation canônica já criada.
- Operações SSH/Huawei/MTR/BGP/shell/device_* e outras fora do catálogo não são executadas. Capacidades obrigatórias indisponíveis impedem criação do diagnóstico.

## Concorrência e idempotência

Advisory lock PostgreSQL por usuário serializa criação/envio, seguido de row lock da conversa. Unique creation_key e request_key impedem duplicação. Digest HMAC com APP_KEY detecta reutilização de chave com conteúdo diferente (409), sem persistir o conteúdo bruto. A mesma chave com o mesmo corpo devolve a resposta canônica, inclusive após CLOSED. Trocar APP_KEY pode invalidar digests antigos: falha fechada, sem repetir execução.

Sincronização faz row lock; resultado final tem event_key único por diagnóstico. Ordem é sequence monotônica, com constraint unique. GET não altera estado. POST /sync autenticado e protegido por CSRF aplica conclusão técnica e retorna fragmento HTML. Não há dependência inversa do worker para Conversation, evitando lock circular entre worker/conversa.

## Adaptive Tone

Padrão único: ADAPTIVE_TONE=AUTO. Não há seletor manual. Até quatro mensagens recentes alimentam regras controladas: formality, verbosity, technical_level, directness, regionality e confidence. Regionality é sempre NOT_INFERRED; nenhum atributo sensível ou perfil psicológico é deduzido. A análise é transitória, não cria perfil linguístico permanente.

Mirror leve usa apenas expressões presentes na mensagem: “Mano…” pode receber “Fechou”; “bão/trem” recebe reconhecimento breve; “Boa noite. Poderia…” preserva cumprimento e texto neutro. Sem caricatura, nome repetido ou sotaque inventado. Na ausência de indícios, natural/neutro.

Tom só muda apresentação. Nunca é entrada do Planner, thresholds, autorização, Agent, classificação ou cancelamento. NORMAL permanece exatamente: “Não foram encontradas anormalidades no escopo testado.”

## Knowledge e LLM

Botão opcional reutiliza `Llm\Interpretation` da RECOVERY-5 depois de diagnóstico COMPLETED: evidências sanitizadas, Knowledge e Prompt publicados, binding administrativo e provenance existentes. Não envia o transcript de conversa ao modelo. Não há tools/function calling nem detecção de intent via modelo. Classificação oficial é lida e preservada; interpretação ASSISTED é apresentada separadamente.

Sem provider/secret/allowlist ou em falha, a conclusão técnica continua completa. Fallback é silencioso e mantém contexto. Falha externa nunca impede concluir um diagnóstico ou conversar. LLM output é escapado e não concede autoridade operacional, mesmo que contenha prompt injection.

## UI, limites e privacidade

Entrada em Diagnósticos → Conversar, sem alterar sidebar/dashboard. Histórico próprio, nova conversa com Agent selecionado (automático se único), transcript, alvo, status, progresso agrupado e link para evidência técnica. Poll leve de 5s, sem navegação integral. Valida tenant do fragmento; sessão/contexto inválido interrompe refresh. Formulário nativo funciona sem JavaScript.

Mensagem: até 2000 caracteres. Envio/criação: 20/min por usuário e 120/min por tenant. Sync: 60/min por usuário. LLM mantém limite 6/min por usuário e 30/min por tenant. Retries no navegador preservam request_key; não armazenam texto em localStorage.

Text remove controles, credenciais em URLs, rótulos usuais senha/token/API key, formatos comuns de keys e blocos privados. Logs e auditoria nunca recebem body, Authorization ou secrets; body também é excluído do flash de validação. Redaction por padrões não detecta todo segredo arbitrário: não inserir credenciais; restringir acesso/backup do histórico. HTML é escapado pelo Blade.

## Rollout e retenção

Ausência de conversation_settings significa desabilitada. Admin do tenant pode habilitar na tela de conversas após validação; nenhuma ativação global ou automática na produção. Alteração de flag bloqueia novos envios/sync, sem destruir histórico nem cancelar tarefas silenciosamente.

Retenção padrão: 30 dias após última atividade para CLOSED/CANCELLED; configurável 7–365 dias por tenant. Conversas abertas ou com diagnóstico ainda em execução são preservadas até encerramento. Política vale também para solicitações administrativas de expurgo. Audit técnico e Diagnostics têm política própria e não são removidos pelo purge.

Prévia (runtime, somente leitura): `php artisan agent-center:prune-conversations TENANT_ID`.

Apply exige container efêmero com `.env.migrator`, tenant explícito e `--apply`; até 500 conversas elegíveis por chamada, com locks e transação. Agendar por operador responsável; não existe purge global automático. Backups locais têm janela de sete dias, portanto um expurgo não remove imediatamente cópias históricas. Destino externo precisa de retenção compatível e controle de acesso.

## Recuperação

Dump integral inclui settings, conversations, messages e events; Git bundle/configs seguem procedimento existente. APP_KEY (sessões e digest) e master key AES-GCM são secrets separados do Git. Prova de restore verifica contexto, estado CLOSED, mensagens/eventos, Knowledge/Prompts/bindings e descriptografia com chave recuperada do backup. Erro de prova ou ausência de marcador explícito PASS agora falha o procedimento.

Não há destino externo autorizado configurado. Backups locais, mesmo com restore comprovado, não protegem contra perda total desta VPS. HTTPS permanece com gate restrito à VPS até resolver esse pré-requisito de recuperação; ver DEPLOYMENT.md.
