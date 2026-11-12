# Naturalidade conforme o interlocutor — laboratório, 12/11/2026

NATURAL CONVERSATION CONTEXT FIX LAB PASS

Base: produção `3341c3f`. Correções: `c816559` e `b5a09f8`.
Nenhuma publicação desta rodada foi realizada.

## Causa e correção

O caminho social já reconhecia a intenção, mas o gerador recebia uma orientação de tom genérica. O fallback não aproveitava o vocabulário recente do usuário, e o detector podia herdar informalidade mesmo depois de uma mensagem escrita em português padrão. Não era necessário criar uma Skill operacional.

Somente `Language.php` e `ConversationToneDetector.php` mudaram no runtime. O tom segue a mensagem mais recente; mensagens formais ou em português padrão interrompem a herança de gírias. Preferência explícita e incidentes graves continuam tendo precedência. As últimas três mensagens do usuário fornecem até seis expressões permitidas ao gerador, sem inferir região ou idade e sem transferir vocabulário entre conversas. O prompt e os fallbacks aceitam informalidade moderada, evitam apresentação institucional repetitiva e não impõem bordões. A palavra “barril”, acompanhada de assunto de rede, recebe uma pergunta útil sem inventar diagnóstico.

“Marcha” e “bora” são respostas sociais: não consomem confirmação operacional. `IntentDetector`, `Engine`, Task Engine e controles Telegram permanecem idênticos à base. Não há ferramentas disponíveis ao LLM nem alteração de limites de custo.

## Exemplos com OpenAI real

Mensagens separadas, mesma conversa, identidade nativa Telegram em laboratório, sem envio ao Telegram real:

| Usuário | Resposta observada | Caminho |
| --- | --- | --- |
| boa noite ce ta bao? | Boa noite! Tudo certo por aqui. E cê, tá bão? | ASSISTED / RELAXED |
| e ai jovem da internet | Opa, jovem da internet! Pode chegar, estou por aqui. | LOCAL / RELAXED |
| Boa noite. Como você está? | Boa noite! Tudo certo por aqui. E você, como está? | ASSISTED / CASUAL |
| Internet hoje ta barril kk | Internet assim atrapalha mesmo. Tá lenta o tempo todo ou vai e volta? | ASSISTED / RELAXED |
| marcha | Marcha! 😄 | LOCAL / RELAXED |

Três chamadas autorizadas, 3.112 tokens informados pelo provedor, `store=false`, sem tools, sem tarefas ou diagnósticos. A credencial temporária exportada para o laboratório foi removida; os registros de prova foram revertidos por transação. Respostas são exemplos observados, não promessas de frases fixas.

## Verificação

- Regressão completa: 430 testes, 3.032 assertions, 41 arquivos, PostgreSQL com FORCE RLS; concorrência do Task Engine aprovada.
- Pint nos quatro PHP alterados e `git diff --check`: PASS.
- Sequência de cinco mensagens testada pela interface Web e ingresso/Runner/Deliver nativos Telegram, com falha LLM simulada. Texto entregue no Telegram igual ao histórico correspondente do painel; sem rodapé automático “Histórico:” nas mensagens sociais; `/historico` preservado; zero tarefas/diagnósticos.
- Doze capturas: Web e histórico Telegram em 1440, 768 e 390 px, claro/escuro, sem overflow e sem erros JavaScript. Avatares renderizados.
- Imagem candidata `agent-center-central:adaptive-speaking-style-lab-20261112`: hashes dos dois arquivos iguais ao commit `b5a09f8`.
- Sem alteração de frontend; bundle de produção preservado. Sem alteração de dependências, migrations, Agent, Lifecycle, Observability, R2 ou equipamentos. Varredura local de padrões de secrets: PASS; não equivale a uma auditoria com gitleaks.

Evidências sanitizadas em `docs/evidence/adaptive-speaking-style-20261112/`; logs completos e capturas adicionais em `/root/agent-center-speaking-style-20261112`.

## Publicação e rollback preparados

Somente após autorização operacional específica: rever o diff, preservar a proteção de revogação de colaboradores publicada em `3341c3f`, executar backup e validar restauração, promover a imagem compatível a Web/worker/scheduler e conferir saúde, hashes, conversa social e `/historico`. Não recriar credenciais, vínculos, grupos ou banco.

Rollback desta correção de linguagem: restaurar apenas `Language.php` e `ConversationToneDetector.php` da base `3341c3f` em imagem compatível com a produção atual. Nenhuma reversão de dados ou de migrations. Não utilizar imagens antigas que removam a proteção de revogação de membros. A funcionalidade de encerramento por inatividade solicitada depois está sendo tratada em branch separada e exige sua própria validação.
