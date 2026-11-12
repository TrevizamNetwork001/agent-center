# Continuidade contextual Web/Telegram — laboratório

Resultado: **CONVERSATION CONTEXT FIX LAB PASS**. Produção não publicada.

Base: `54cf48f`. Correção: `ea9ccf3`; manutenção do tom na confirmação: `63b14db`; regressão de conversa intercalada: `c4d3242`. Branch isolada: `diagnostic-experience-v2`.

## Causa demonstrada

O histórico de mensagens existia, mas a Conversation Engine não conservava uma referência estruturada à pergunta pendente. Uma resposta como “15 dias” era classificada como `UNKNOWN`; por ter menos de nove caracteres, o gerador também a tratava pelo caminho econômico determinístico, chegando a “Pode continuar, estou acompanhando o contexto”. Não era necessário presumir uma resposta da OpenAI para explicar o defeito.

O novo teste reproduz a pergunta exata e as mensagens subsequentes separadamente. Um cliente LLM de teste responde deliberadamente com o texto genérico na segunda chamada: o validador rejeita essa saída e conserva a resposta contextual determinística.

“Pode encerrar” já era reconhecido pelo parser. Foram acrescentadas variantes ancoradas como “valeu, pode fechar” e “pode finalizar a conversa”; a confirmação operacional continua sendo interpretada pelo código existente.

## Alteração mínima no fluxo existente

- `ContextualTurn` conserva tipo, pergunta sanitizada e sequência em `context_json.pending_question`. Tipos: tempo de espera, início do sintoma, clientes afetados, retorno recebido e sintoma.
- Respostas compatíveis usam essa pergunta antes do fallback. “15 dias”, “desde ontem”, “só alguns”, “todos os clientes”, “não respondem” e “começou hoje” possuem regressões.
- O modelo recebe a relação pergunta/resposta, assunto e até quatro respostas conhecidas, além do histórico limitado existente. A aplicação rejeita incentivo genérico, empatia artificial e medições inventadas nesse caminho.
- A memória limita a distância a 16 posições de mensagem; não serve como destino, autorização ou plano operacional. Perguntas antigas compatíveis podem ser recuperadas do histórico sem migration.
- Uma interjeição breve, como “kkkk”, preserva a pergunta e o assunto. Uma mudança explícita de assunto ou intenção operacional limpa a pergunta pendente. Fechamento também a limpa.
- Tenant e proprietário são revalidados. Não há cache compartilhado de perguntas, nova tabela, alteração de RBAC/RLS ou autoridade do LLM.

## Exemplos executados

Mesma sequência, fallback contextual, pelos dois canais nativos:

| Mensagem | Resultado |
| --- | --- |
| Agent: “Há quanto tempo você está esperando resposta?” | Pergunta pendente registrada como `waiting_duration` |
| Usuário: “15 dias” | “Você está esperando resposta há 15 dias. Chegou a receber algum retorno nesse período?” |
| Usuário: “não respondem” | “Você continua sem retorno. O que estava tentando resolver com esse contato?” |
| Usuário: “valeu, pode fechar” | Estado `CLOSED`, histórico preservado, nenhuma tarefa nova |

Na prova adicional com OpenAI real e o binding conversacional já autorizado, a pergunta foi inserida como fixture no banco separado e a resposta foi gerada pelo cliente nativo:

- Web, `ASSISTED`: “Você já está esperando uma resposta há 15 dias. É uma espera longa. Sobre qual assunto você aguarda retorno?”
- Telegram, `ASSISTED`: “Esperar 15 dias por uma resposta é um transtorno. Sobre qual assunto você está aguardando retorno?”

Foram duas chamadas, limite de 1.024 tokens de saída e dez segundos por chamada, `store=false`, sem ferramentas. Uso registrado: 1.036 tokens de entrada por chamada; 50 e 48 de saída. As importações de configuração e conversas dessa prova foram revertidas por transação. Nenhuma mensagem foi enviada ao Telegram real. O assunto da pergunta é uma fixture; não representa atendimento real nem medição de rede.

Com diagnóstico ativo, “valeu, pode fechar” mantém `DIAGNOSTIC_RUNNING` e pede a confirmação de cancelamento existente. Só “Sim, confirmo” executa o cancelamento autorizado. Mensagens sociais não confirmam operações.

## Validação e evidências

Suíte completa: **471 testes, 3.466 asserções, 44 arquivos**, PostgreSQL separado com papel de runtime e FORCE RLS. Inclui Conversation Engine, Natural Conversation, RAG, OpenAI, Telegram, confirmações, isolamento, idempotência, Task Engine e concorrência do encerramento por inatividade. O teste contextual focado também passou: seis testes, 88 asserções; é um subconjunto da suíte, não uma contagem adicional de cobertura.

Pint: 16 arquivos PHP alterados aprovados. Build frontend e checagem de diff aprovados. Nenhuma migration.

- [Sequências nativas e prova de ausência de tarefas extras](evidence/diagnostic-experience-v2-20261112/scenarios.json)
- [Prova OpenAI real, sem credenciais](evidence/diagnostic-experience-v2-20261112/live-context.json)
- [Histórico Web](evidence/diagnostic-experience-v2-20261112/context-web-390-dark.png)
- [Histórico Telegram no painel](evidence/diagnostic-experience-v2-20261112/context-telegram-390-dark.png)
- [Resumo de validação](evidence/diagnostic-experience-v2-20261112/validation.json)

## Publicação e rollback preparados

A correção integra a candidata de laboratório descrita no [relatório V2](DIAGNOSTIC_EXPERIENCE_V2_LAB_20261112.md). Antes de eventual publicação, reconciliar os commits com o HEAD vigente em checkout isolado, preservar alterações posteriores, conferir prechecks, backup verificável e autorização operacional específica. Não reutilizar credenciais ou fixtures de laboratório em produção.

Rollback da continuidade: restaurar as versões anteriores de `Engine`, `Language`, `IntentDetector`, `Text` e `ConversationToneDetector` em imagem reconciliada. Os campos adicionais de contexto podem permanecer no histórico: não precisam ser apagados e não conferem autorização. A imagem de rollback V2 já restaura esses arquivos e mantém a entrega segura de notificações pendentes. Não executar `migrate:rollback`, apagar conversas ou cancelar diagnósticos como parte do rollback.

Agent, Lifecycle, Observability, release em quarentena, R2 e equipamentos não foram modificados. O commit e as imagens em produção foram conferidos e permanecem anteriores a esta tarefa. Trabalho encerrado em laboratório.
