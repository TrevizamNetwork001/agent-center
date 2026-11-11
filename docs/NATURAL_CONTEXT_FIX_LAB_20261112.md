# NATURAL CONVERSATION CONTEXT FIX LAB PASS

Código `a7e3580`, branch `natural-conversation-context-fix`, baseline publicado `4e5b1d5`. Somente laboratório; sem ativação em produção.

## Origem comprovada

Consulta somente leitura, com identidade administrativa nativa e contexto do tenant, encontrou a sequência real. As três mensagens foram classificadas como `SOCIAL`, com estado `ACTIVE` e tom `RELAXED`. Há somente a transição inicial NEW→ACTIVE no histórico dessa conversa.

| Entrada | Caminho e origem final |
| --- | --- |
| `ce ta bao?` | `ASSISTED`, com usage e request_id. Resposta da IA: “Boa noite! Tô por aqui, disponível como assistente virtual da Agent Center. E você, tá bem?” |
| `Tô bão também, meu rei! Kkkkk` | `ASSISTED`, com usage e request_id. Resposta da IA: “Que bom! Por aqui, sigo disponível como assistente virtual do Agent Center.” |
| `Rapaz, o YouTube tá uma carroça hoje 😂` | `LOCAL`, sem chamada ao gerador. A expressão “carroça” forçava `Language::local`, que produzia a frase sobre medições. Não foi fallback de erro. |

No código publicado, o contexto do gerador incluía apenas as três mensagens recentes do usuário, sem as respostas anteriores do assistente. A orientação específica para bem-estar enfatizava disponibilidade e identidade virtual. Isso favorecia a repetição institucional e não permitia ao modelo acompanhar o que ele próprio acabara de perguntar. O tom descontraído já estava correto; a regra local de lentidão ignorava esse estilo na escolha do texto.

Os contextos históricos foram reconstruídos a partir do código publicado e dos registros, incluindo versão/hash dos templates. Não são capturas de requisições HTTP antigas. A primeira chamada via `ASK_ASSISTANT_WELLBEING`; a segunda via `CONVERSATION`; a terceira não enviou contexto ao gerador. Detalhes em `original-context.json`.

## Correção mínima

Único arquivo de runtime alterado: `app/Conversation/Language.php`.

O contexto inclui até cinco turnos, com os dois lados da conversa, até 160 caracteres sanitizados por turno (máximo 800 caracteres de conteúdo, comparado ao limite anterior de 900 para as falas do usuário). Mensagens do assistente precisam ser respostas de linguagem identificadas por metadata; resultados operacionais, interpretações e respostas RAG não entram nesse contexto. Mantidos tenant, dono e conversa da consulta. A autorização externa existente continua obrigatória.

O ato de linguagem distingue perguntar sobre o assistente, compartilhar que está bem e relatar lentidão de rotina. Esses atos somente orientam a redação; não alteram o parser de intenções nem decidem operações. O modelo recebe as próprias respostas anteriores, evita reapresentação e ofertas genéricas de disponibilidade e faz no máximo uma pergunta útil no relato de lentidão. Saída institucional desnecessária é recusada em favor de fallback local; perguntas explícitas sobre identidade continuam recebendo explicação verdadeira.

Queixas de rotina podem usar o LLM autorizado, preservando o tom. Contextos sérios/técnicos permanecem locais e sóbrios. Limites mantidos: três chamadas por usuário/minuto, doze por tenant/minuto, doze por conversa; timeout até dez segundos, saída até 1.024 tokens, store=false, sem retentativa automática OpenAI e sem ferramentas/Skills. A terceira fala da sequência pode consumir a terceira chamada permitida; o teto não aumentou.

O fallback reconhece a resposta de bem-estar, sem perguntar de novo como a pessoa está. A queixa do YouTube recebe empatia leve e uma pergunta sobre o sintoma observado, sem anunciar diagnóstico nem inventar medições. Metadata registra o ato social e as sequências usadas no contexto, sem guardar novo payload ou credenciais.

## Exemplos reais no laboratório

Mesmas três entradas, em envios separados, com a configuração OpenAI autorizada lida de produção e usada apenas no LAB:

| Canal | Primeira resposta | Segunda resposta | Resposta à lentidão |
| --- | --- | --- | --- |
| Web | “Tudo certo por aqui! E você, tá bem?” | “Que bom! 😄” | “Poxa, é chato quando fica assim! Tá demorando pra abrir os vídeos ou eles ficam parando no meio?” |
| Telegram/Engine | “Tudo certo por aqui! E você, tá bem?” | “Aí sim! Que bom que tá bem!” | “Poxa, é chato quando fica assim! Os vídeos demoram pra começar ou ficam parando no meio?” |

Seis requisições OpenAI reais, três por cenário, 4.767 tokens informados no total. O primeiro teste Telegram atingiu o limite compartilhado do usuário após as três chamadas Web e respondeu com fallback pertinente: esse limite foi preservado e documentado, sem limpeza ou aumento de quota. O cenário Telegram com IA real foi refeito após a janela normal do limitador. Os testes não criaram tarefas/diagnósticos; os cenários com configuração real foram revertidos em transação e o arquivo temporário cifrado foi removido.

Transporte Telegram simulado, com ingress, Runner e Deliver nativos: “Boa! Que bom que tá tudo certo.” e “Essa lentidão atrapalha, hein. Os vídeos demoram pra começar ou ficam parando no meio?”. Cada texto entregue correspondeu ao mesmo registro exibido no painel. `/historico` preservou a conversa. Não houve envio ao Telegram externo real.

## Testes e limites

414 testes Laravel/PostgreSQL, 2.881 assertions PASS, incluindo a sequência nas duas identidades, falha/timeout com fallback, rejeição de texto institucional, sanitização e exclusão de resultados/RAG do contexto. Fluxo de diagnóstico só cria diagnóstico depois da confirmação explícita; bate-papo preserva a pendência. Regressões de idempotência, RAG/fontes, RBAC/FORCE RLS e isolamento por tenant PASS. Concorrência em processos separados e fencing do Task Engine PASS. Banco de teste descartável removido pelo runner.

Pint nos três arquivos PHP, npm ci/build Vite e git diff --check PASS. Navegador real: envios Web separados e leitura do histórico Telegram, 1440/768/390 em claro/escuro, doze combinações sem overflow/erros JS. Secret scan por padrões de chaves privadas, OpenAI, AWS e tokens Telegram: sem achados. Scanner especializado não instalado.

Hashes demonstram Engine, IntentDetector, ConversationToneDetector, Runner, Deliver e Access idênticos ao baseline. Não houve mudanças no Agent, Lifecycle, R2, equipamentos, schema ou configurações de produção. Evidências em [evidence/natural-context-fix-20261111](evidence/natural-context-fix-20261111).

## Rollback preparado

Este patch não foi publicado. Para descartar no laboratório, voltar apenas Language.php ao baseline `4e5b1d5`, preservando banco e demais trabalhos. Uma publicação futura exige autorização operacional específica, backup e imagem compatível construída sobre a versão então em produção. Depois de eventual publicação, rollback de imagem para `conversation-polish-v1-20261111` (ou seu equivalente compatível preservado antes do deploy) nos serviços web/worker/telegram-scheduler; sem migration rollback, restauração de dump, troca de credencial ou revogação de vínculos. Não reverter a versões anteriores à compatibilidade de grupos/perfis.

Resultado: NATURAL CONVERSATION CONTEXT FIX LAB PASS. A solicitação posterior de associação administrativa de Telegram a usuário existente será tratada separadamente; não faz parte deste patch de linguagem.
