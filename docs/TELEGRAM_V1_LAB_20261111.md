# Telegram V1 — homologação, 2026-11-11

AGENT CENTER TELEGRAM V1 LAB PASS

Candidato: `/root/agent-center-telegram-lab`, branch `agent-center-telegram-v1-lab`, base operacional `c65d949`. Commit de implementação: `0a2d71e`.

## Resultado

Canal Telegram implementado na Central Laravel, reutilizando a Conversation Engine, Adaptive Tone, OpenAI, RAG/Knowledge e Task Engine existentes. Não há segundo motor de conversação/diagnóstico nem executor remoto. Produção não foi publicada ou migrada.

Incluídos administração criptografada do bot, verificação de webhook HTTPS/secret_token, inbox/outbox persistentes, jobs Laravel, recuperação agendada por tenant, rate limits, vínculo temporário de uso único com confirmação no painel, revogação, fontes revalidadas antes da entrega, acompanhamento de resultados oficiais e histórico compatível com a Web. A Web mostra histórico Telegram sem controles que enviariam mensagens por outra identidade de canal.

As quatro tabelas novas possuem FORCE RLS. Grants mantêm o runtime sem DELETE, superuser ou BYPASSRLS. Cadastros, aprovação, revogação, retries e configuração usam autenticação, Gates, sessões, CSRF e auditoria nativos. O bot é exclusivo por tenant, com unicidade global também pelo ID real retornado por getMe. Username/nome/telefone não comprovam identidade.

Diagnósticos Telegram exigem confirmação explícita mesmo no primeiro pedido, consumida pelo ramo existente da engine. Pendências possuem validade de cinco minutos não renovável por saudação. Trocar credencial ou desabilitar o bot troca a geração e revoga vínculos; reativar não revive confirmações ou updates antigos. Social não executa sondagem. Skills continuam limitadas ao Task Engine e suas políticas.

## Testes executados

- Laravel/PostgreSQL: **370 testes / 2580 assertions PASS**, com migrate e rollback em banco descartável. Inclui a regressão Web/Natural/RAG anterior e os testes Telegram.
- Cobertura Telegram: credencial criptografada/redigida, webhook inválido, payload, flood, replay e duplicação por update/mensagem, vínculo/expiração/aprovação por proprietário, operadores, dois tenants, SQL bruto sob FORCE RLS, revogação e usuário desabilitado, saudações e contexto técnico, OpenAI/fallback, RAG/fontes/revogação antes do envio, confirmação exata do operador, alvo diferente/ausente/privado, falta de Agent, negativas/cancelamento, resultado oficial único, /nova sem colisão de chave, bot bloqueado, timeout/429, recuperação de fila e reenvio administrativo com confirmação. Ciclo desabilitar/reativar cercado contra confirmação antiga.
- Pint: **33 arquivos PASS**. npm ci/build e sintaxe JavaScript PASS. Secret scan básico do índice e diff check PASS.
- Nginx: include de ingresso público restrito ao POST do webhook validado por nginx -t, sem instalar ou recarregar Nginx operacional.
- Browser real em Docker: login com senha sintética, código de vínculo, aprovação, mensagens e fila Laravel **database** reais. Telegram externo substituído por transporte explicitamente simulado. HTTP401 para segredo errado, CSRF419, retry200 e conflito409. Revogação pelo painel e posterior confirmação sem tarefa. Desativação pelo painel bloqueia ingresso com HTTP404.
- Interface: desktop1440, tablet768 e mobile390, temas claro/escuro, sem overflow da página e sem erros JavaScript. Histórico Telegram somente para leitura na Web e tenant B sem acesso ao bot/histórico A.
- Retenção privilegiada: limpeza de conversa expirada remove referências de link/outbox e preserva identidade do vínculo; nenhuma ampliação de grants do runtime.
- Rollback de schema com histórico: recusado pela constraint, mantendo migration, quatro tabelas e histórico. Rollback funcional por desativação/revogação validado; procedimento operacional documentado.

Nos fluxos sociais pelo webhook/fila: **zero diagnósticos e zero tarefas**. Diagnósticos dos testes automatizados usam Agent e dados sintéticos em PostgreSQL descartável; nenhum equipamento foi acessado.

## Exemplos observados

| Entrada Telegram simulada | Resposta da engine existente |
|---|---|
| Boa tarde, meu consagrado! | Boa tarde! Bom te ver por aqui! |
| Salve, calabreso! Tudo certo? | Opa! Pode chegar, estou por aqui. |
| Rapaz, o YouTube tá uma carroça hoje kkk | Vamos por partes: em que momento você percebeu a lentidão? Ainda não tenho medições para apontar uma causa. |
| Meu BNG está apresentando perda de pacotes. | Entendi o relato. Ainda não há evidências suficientes para atribuir uma causa. O destino youtube.com já está registrado. Nenhuma ação em equipamentos foi iniciada. |
| Obrigado, meu rei! | Por nada! O contexto está salvo; podemos continuar quando você quiser. |

Destino youtube.com preservado e tom TECHNICAL/SERIOUS no relato BNG. A repetição HTTP não acrescenta mensagens nem tarefas. Negação/cancelamento removem pendências. Mensagem afirmativa sem vínculo válido não alcança a engine.

## OpenAI real, com orçamento limitado

Uma requisição HTTPS real, pelo binding/modelo/prompt autorizados existentes, com dados sintéticos no banco `agent_center_telegram_lab`, store=false, sem ferramentas e teto de saída1024. Configuração operacional consultada somente em transação de leitura; chave existente montada somente para leitura no processo temporário. Nenhuma alteração no binding operacional.

Consumo: **501 tokens de entrada + 68 de saída = 569 tokens**. Identificador da requisição na evidência; valor monetário não retornado pela API. Não houve retries reais. Tentativas de preparação anteriores não emitiram requisições externas.

Entrada: “E você, como é ajudar num plantão tranquilo?”

Resposta ASSISTED real: “Olá! Num plantão tranquilo, posso ajudar a organizar ideias e esclarecer dúvidas na conversa. Sou o assistente virtual do Agent Center — não sinto a rotina como uma pessoa, mas posso acompanhar o papo por aqui.”

O Telegram permaneceu simulado nessa prova. A captura mostra o histórico persistido da Conversation Engine no painel, não o aplicativo Telegram real.

## Screenshots e evidências

- [Painel desktop](evidence/telegram-v1-20261111/browser/admin-1440-light.png)
- [Painel mobile](evidence/telegram-v1-20261111/browser/admin-390-light.png)
- [Histórico Telegram](evidence/telegram-v1-20261111/browser/history-390-dark.png)
- [OpenAI real, desktop](evidence/telegram-v1-20261111/browser/openai-real-desktop.png)
- [OpenAI real, mobile](evidence/telegram-v1-20261111/browser/openai-real-mobile.png)
- [Transcrito e fila](evidence/telegram-v1-20261111/functional.json)
- [Prova OpenAI](evidence/telegram-v1-20261111/openai-real.json)
- [Regressão completa](evidence/telegram-v1-20261111/regression.log)

## Pendências e limites objetivos

**Credenciais de bot de homologação não disponíveis nos locais de configuração consultados.** Não foi criado token fictício para uso externo nem solicitado token em texto no chat. Os valores de teste são fixtures sem saída real para api.telegram.org. Falta o ensaio externo Telegram → webhook público HTTPS → fila → Telegram, condicionado à disponibilidade de bot autorizado, como previsto no escopo. O painel já permite cadastro seguro.

Em laboratório, o painel usa HTTP somente na rede Docker interna com dados sintéticos. O webhook público HTTPS não foi exposto nem instalado na VPS. Publicação e teste externo devem seguir autorização operacional específica e TLS válido; não cadastrar credenciais reais nesse painel HTTP de fixture.

A entrega externa não possui garantia exactly-once: timeout/5xx ficam UNKNOWN e não são reenviados automaticamente. Reenvio administrativo avisa sobre possível duplicação da resposta e nunca repete diagnóstico. Fontes e permissões são revalidadas na entrega. Mensagens longas são abreviadas, com acesso ao histórico autenticado.

## Produção, publicação e rollback

Produção permanece em `c65d949`, imagem `agent-center-central:natural-conversation-v1-20261111`, ID `sha256:314435c174bde09744c4c09d59b2f9799c17b5c89be3a85fbf573b66a075201a`. Health final **VERDE**, 97 tarefas e 28 diagnósticos, configuração protegida idêntica ao baseline. Nenhuma migration Telegram operacional ou imagem nova publicada. RAG, OpenAI, confirmação publicada, Agent, Lifecycle, Observability, quarentena0.6.3, R2 e equipamentos preservados.

[Plano completo de cadastro, vínculo, fila, publicação e rollback](TELEGRAM_V1_OPERATIONS.md). Rollback preferencial desabilita canal/ingresso na imagem compatível, revoga vínculos e preserva schema/histórico. Não reverter cegamente para binário pré-Telegram depois de criar histórico, nem executar down ou restauração integral para remover o canal. Antes de qualquer reativação, trocar geração/revincular para descartar contexto antigo.
