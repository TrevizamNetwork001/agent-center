# AGENT CENTER CONVERSATION POLISH LAB PASS

Implementação: `ea6cd1a`, branch `agent-center-conversation-polish-lab`, sobre `8b17243`. Polimento somente em laboratório. Não houve ativação desta imagem em produção.

O trabalho anterior foi publicado mediante autorização expressa: `1d70aa9` (wizard Telegram, grupos/colaboradores por empresa e perfil/avatar) e `8b17243` (eventos em português e horário de São Paulo). A produção usa `telegram-guided-company-profile-20261111`. A publicação anterior teve backup local `20261111T221750Z`, restauração isolada, ensaio das migrações, TLS/queue/readiness e navegador em 24 combinações aprovados, Health VERDE, tarefas 97→97 e diagnósticos 28→28. Dados protegidos e arquivos locais de outras tarefas foram preservados. Grupos externos reais ainda dependem do cadastro pelo usuário; sua homologação foi feita com transporte simulado.

## Causa demonstrada

Consulta somente leitura ao registro real de produção, com identidade nativa e contexto do tenant, mostrou:

| Campo | Valor |
| --- | --- |
| Entrada | `ce ta bao ?` |
| Intent | `UNKNOWN` |
| Tom | `RELAXED` |
| Geração assistida | `FALLBACK`, motivo `timeout` |
| Resposta | “Pode continuar, estou acompanhando o contexto.” |
| Uso informado | vazio; não comprova ausência de cobrança |

O detector normalizava acentos/caixa, mas não reconhecia a construção abreviada de bem-estar. A mensagem chegava ao componente de linguagem como desconhecida. A geração assistida foi tentada e excedeu o prazo; o fallback local genérico produziu a frase observada. Adaptive Tone já tinha reconhecido a informalidade. A frase vem de `Language::local`, não de uma confirmação ou transição da máquina de estados. O contexto técnico recente também podia fazer uma pergunta pessoal receber a resposta sobre ausência de evidências.

Separadamente, `Telegram\Deliver` acrescentava `Histórico:` incondicionalmente a cada mensagem da conversa. A identidade/histórico compartilhado não era a causa dessa repetição visual.

## Correção pontual

`SocialText` reconhece uma gramática de perguntas sociais com normalização lexical de pronomes, abreviações, acentos e pequenas variações ortográficas. Não há resposta vinculada a cada frase de entrada. O texto original permanece no histórico; destinos e confirmações não são reescritos. As precedências operacionais permanecem antes do reconhecimento social. Nenhuma alteração no Engine ou Task Engine neste patch.

O LLM autorizado recebe o ato social identificado e uma orientação para responder diretamente à pergunta, com disponibilidade de assistente virtual, sem alegar sentimentos humanos ou saúde da rede. Construções sociais curtas reconhecidas também podem usar esse caminho. As duas permissões administrativas existentes continuam obrigatórias. Permanecem os limites de três chamadas por usuário/minuto, doze por tenant/minuto e doze por conversa; três mensagens recentes sanitizadas, timeout de até dez segundos, saída de até 1.024 tokens e `store=false`. OpenAI não ganha retentativas automáticas. Mensagens operacionais e contexto sério/técnico continuam sem chamadas de linguagem.

Em indisponibilidade, a resposta local compõe disponibilidade e uma continuação pertinentes, ajustadas ao tom, evitando repetir imediatamente a resposta anterior. Uma pergunta social durante incidente é respondida com sobriedade e preserva a confirmação pendente. Gírias adicionais influenciam moderadamente o tom, sem inferir região.

No Telegram, respostas sociais deixam de receber o rodapé automático. `/historico` e `/history` disponibilizam o link da conversa atual, sem criar outra conversa nem consumir confirmação; Gate e identidade de canal/contato/grupo são conferidos. Mensagens de diagnóstico, resultados, interpretações e textos truncados continuam oferecendo histórico. Referências verificáveis do RAG são preservadas e revalidadas antes do envio. Cabeçalhos de grupos e separação por pessoa/tópico permanecem.

## Exemplos executados

| Caminho | Entrada | Resposta observada |
| --- | --- | --- |
| Web, OpenAI real no LAB | `ce ta bao ?` | “Tô por aqui, disponível como assistente virtual do Agent Center! E você, tá bem?” |
| Telegram, Engine com OpenAI real no LAB | `ce ta bao ?` | “Tô por aqui, disponível como assistente virtual do Agent Center! E você, tá bem?” |
| Web, resposta local no navegador | `ce ta bao ?` | “Tudo certo por aqui, pronto pra ajudar! Como estão as coisas por aí?” |
| Telegram, transporte simulado completo | `ce ta bao ?` | “Por aqui, tudo certo! Estou à disposição. E você, tá bem?” |
| Telegram, mesma sequência | `salve calabreso` | “Opa! Tudo certo por aqui. Como vai?” |
| Telegram, mesma sequência | `como vc tá?` | “Tudo certo por aqui, pronto pra ajudar! E você, tá bem?” |

O texto entregue pelo transporte Telegram simulado foi comparado ao mesmo registro mostrado no painel, sem alteração nem rodapé social. Web e Telegram utilizam o mesmo Engine/Language, respeitando suas identidades e contextos; não se fundem conversas privadas e de grupo. Respostas locais podem variar com a sequência e o tom. O teste OpenAI fez exatamente duas chamadas reais: 618 tokens informados em cada uma (572 de entrada, 46 de saída), sem tarefas ou diagnósticos novos. O Telegram externo real não foi acionado para este polimento.

## Validação

- Laravel/PHPUnit/PostgreSQL: **409 testes, 2.809 assertions**, sem falhas ou avisos; migrações de teste revertidas e banco descartável removido pelo runner.
- Concorrência em processos separados: reservas concorrentes, corrida worker/resultado, execução exclusiva, fencing de reserva expirada, idempotência e pré-requisitos DNS PASS.
- Gírias e oito expressões solicitadas; abreviações, acentos, erro `taa/bemm`, humor, linguagem formal, sequência social, transição técnica, confirmação explícita, recusa/cancelamento, RAG/fontes revogadas, duplicatas, custo e isolamento por tenant PASS. Pergunta pessoal preserva pendência; comando de histórico com conversa de outro proprietário é negado.
- FORCE RLS/RBAC e regressões existentes de grupos/perfis preservados. Nenhuma migration nova neste patch.
- Pint: nove arquivos PHP PASS; `git diff --check` PASS.
- `npm ci` e build Vite PASS.
- Navegador real: envio pela interface web e leitura do histórico Telegram, 1440/768/390, claro/escuro, doze combinações; sem overflow ou erros JavaScript. Avatares sintéticos privados copiados para o storage da instância LAB e imagens decodificadas na conferência final.
- Secret scan de todos os nove arquivos alterados/novos, padrões de chaves privadas, OpenAI, AWS e tokens Telegram: zero achados. Varredura local por padrões; scanner especializado não instalado. Nenhuma credencial exportada para Git; arquivo temporário de configuração cifrada usado no teste real removido.
- Imagem candidata `agent-center-central:conversation-polish-lab-20261111` construída sobre a imagem publicada. Seis arquivos de runtime conferidos por SHA-256 contra o código. Nenhum serviço de produção usa esta imagem.

Evidências sanitizadas em [evidence/conversation-polish-20261111](evidence/conversation-polish-20261111), incluindo causa real, transcrições, uso OpenAI, logs, hashes e capturas. Nenhuma alteração em Python Agent, Lifecycle, Observability, R2 ou equipamentos.

## Publicação e rollback propostos

Publicar este polimento exige autorização operacional específica; a autorização do usuário para publicar o trabalho anterior não foi estendida a ele.

Após autorização: conferir HEAD/imagem atuais, fazer backup local e verificar restauração, comparar os seis arquivos sobre a imagem compatível atual e preservar mudanças operacionais locais. Publicar o commit revisto e a imagem compatível em web, worker e telegram-scheduler; manter diagnostic-worker e demais serviços. Não há migrações, mudança de credenciais, rotação de bot, novo vínculo ou mudança de permissões. Validar TLS/readiness/queue, Health, teste social limitado, `/historico`, fontes RAG e uma confirmação sintética segura. Registrar imagem/commit e comparação dos dados protegidos.

Rollback do polimento: voltar exclusivamente web/worker/telegram-scheduler para a imagem `telegram-guided-company-profile-20261111` preservada e suas versões anteriores dos seis arquivos. Revalidar serviços e manter banco/histórico/grupos/perfis intactos. Não executar `migrate:rollback`, restaurar banco sobre produção, revogar vínculos ou reverter para uma imagem anterior à compatibilidade de grupos/avatar. O rollback do trabalho anterior possui plano próprio e guardas para preservar seus dados.

Resultado: **AGENT CENTER CONVERSATION POLISH LAB PASS**. Trabalho encerrado; polimento aguardando autorização específica de publicação.
