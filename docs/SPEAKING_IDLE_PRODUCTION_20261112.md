# Naturalidade e encerramento por inatividade — PUBLICADO

NATURAL CONVERSATION AND IDLE PRODUCTION PASS

Autorização operacional específica: “pode publicar”, após entrega do laboratório. Publicação em 12/11/2026, aproximadamente 00:56 UTC (11/11/2026, 21:56 em São Paulo).

Imagem `agent-center-central:speaking-idle-v1-20261112`, id `sha256:61dd61c7ee090cae68da68c624b92a819a314ed78bda3806230f3aacd850a90a`. Fonte de release `e0d5ec8`; naturalidade `c816559` e `b5a09f8`; inatividade `42d0d36` e `f1bfa30`. Os oito arquivos de runtime conferem por SHA-256 entre fonte, candidata aprovada e produção.

## O que está ativo

A conversa acompanha moderadamente o jeito de falar do usuário, incluindo gírias, e retorna ao português padrão quando a mensagem muda de registro. “Marcha” e “bora” não autorizam tarefas. Parser, confirmação explícita, Task Engine, RAG/fontes, RBAC/FORCE RLS e limites OpenAI permanecem preservados.

No tenant 1, Web e Telegram encerram a conversa depois de 15 minutos sem interação, com agradecimento e histórico preservado. O agendador verifica a cada minuto. Testes pendentes/em execução são preservados; a chegada do resultado inicia nova contagem. No retorno, uma nova saudação ou assunto abre outra conversa. Uma confirmação atrasada não inicia teste. Vínculos Telegram, grupos, colaboradores e `/historico` permanecem válidos.

A configuração administrativa de colaboradores anteriormente publicada continua ativa. Nenhuma credencial, ID real, vínculo, associação ou papel de usuário foi recadastrado nesta publicação.

## Preservação e rollback

Backup local `20261112T005356Z`; restauração isolada aprovada com dezesseis tabelas protegidas idênticas, FORCE RLS e credencial OpenAI decifrável com a chave do backup. Sem replicação externa adicional e sem remoção de gerações anteriores.

Atualizados Web, worker e Telegram scheduler. O migrator aponta à imagem compatível, mas não foi executado. Nenhuma migration, mudança de grants ou reversão de dados em produção. Diagnostic-worker permaneceu na imagem `agent064-workers-20261110`, sem reinício. Python Agent, Lifecycle, Observability, R2 e equipamentos preservados. Alterações locais de outras tarefas conferem com os hashes anteriores, exceto o ajuste de compose explicitamente necessário para esta publicação.

Rollback preferencial pronto em `/root/agent-center-speaking-idle-production-20261112/disable-idle.sh`: desativa somente o encerramento automático, conserva a naturalidade e a abertura de nova conversa no retorno. Rollback completo compatível em `rollback.sh`, no mesmo diretório: restaura o compose anterior e a imagem `context-colleague-v1-20261112`, conservando o guard de revogação de membros e dados existentes. Scripts validados por sintaxe. Não houve necessidade de executar rollback. Não restaurar dump nem reabrir/apagar conversas em massa; com rollback completo, usar Nova conversa ou `/nova` para chats já encerrados.

## Verificações após publicar

- Web e worker saudáveis; scheduler em execução. Readiness, TLS com verify-full, fila database→worker→cache e CSS/JS servidos pelo Nginx: PASS.
- Os dois comandos agendados executaram com `DONE` em minutos consecutivos: `telegram:drain 1` e `conversations:close-idle 1`.
- A operação real já encerrou três conversas Web e uma Telegram por inatividade. O aviso Telegram teve status `SENT`. Essas são conversas antigas elegíveis, não registros sintéticos de smoke.
- Smoke no runtime publicado: sequência de cinco mensagens separadas em Web e Telegram; três chamadas OpenAI reais limitadas a 1.024 tokens de saída por chamada, timeout de até dez segundos, `store=false`, sem tools. O Telegram respeitou a quota compartilhada do usuário e selecionou o fallback esperado, sem ampliar limites. Transporte de teste capturou a formatação nativa sem enviar mensagens sintéticas ao Telegram real.
- Encerramento, aviso único, confirmação tardia sem tarefa e nova conversa no retorno aprovados nos dois canais. Somente os chats temporários do smoke foram envelhecidos dentro de uma transação revertida; nenhuma conversa existente foi alterada artificialmente.
- Navegador autenticado: dezesseis combinações de páginas/tamanhos/temas, incluindo histórico, configuração Telegram e perfil, sem overflow ou erros JavaScript. Sessão temporária e arquivo privado de cookie removidos.
- As dezesseis tabelas protegidas, credenciais e configuração do Agent foram preservadas. Tarefas 97 e diagnósticos 28, sem alteração pelo smoke ou publicação.

Exemplos observados no runtime publicado:

| Mensagem | Resposta | Caminho |
| --- | --- | --- |
| boa noite ce ta bao? | Boa noite! Tudo certo por aqui. E cê, tá bão? | Web, ASSISTED / RELAXED |
| Boa noite. Como você está? | Boa noite! Tudo certo por aqui. E você, como está? | Web, ASSISTED / CASUAL |
| Internet hoje ta barril kk | Pelo que você contou, a internet tá barril. É lentidão, quedas ou outro tipo de dificuldade? | Telegram, FALLBACK / RELAXED |
| marcha | Marcha! 😄 | Ambos, LOCAL / RELAXED |

Laboratório anterior: 442 testes, 3.115 assertions, concorrência de inatividade e Task Engine, Pint, build e varredura local de padrões de secrets aprovados. Relatórios: [naturalidade](ADAPTIVE_SPEAKING_STYLE_LAB_20261112.md) e [inatividade](CONVERSATION_IDLE_15M_LAB_20261112.md).

Evidências sanitizadas: [evidence/speaking-idle-production-20261112](evidence/speaking-idle-production-20261112). Artefatos privados e rollback em `/root/agent-center-speaking-idle-production-20261112/`. Secrets, sessões e arquivos de ambiente não estão no Git.
