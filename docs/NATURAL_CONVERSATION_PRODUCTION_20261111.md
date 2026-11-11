# Natural Conversation V1 — publicação em produção, 2026-11-11

AGENT CENTER NATURAL CONVERSATION PRODUCTION PASS

## Imagem e integração

Central web publicada: `agent-center-central:natural-conversation-v1-20261111`.
ID efetivamente observado no container: `sha256:314435c174bde09744c4c09d59b2f9799c17b5c89be3a85fbf573b66a075201a`.

- `13bba46`: integração equivalente ao candidato `63db3d5`.
- `51c08ba`: integração equivalente ao candidato `d9bb765`.
- Base preservada: `10d2ed6`, incluindo `3f69753` (equivalente ao patch aprovado `d15c7b7`). Não houve reaplicação nem validação isolada desse patch.

Imagem construída como uma camada contendo somente 16 arquivos da Conversação Natural sobre a imagem operacional anterior. Todas as camadas anteriores foram preservadas. Ajustes preexistentes de Audit e da listagem de providers foram preservados na imagem e no checkout. A ramificação de confirmação, cancelamento e transições foi comparada e permanece idêntica. Task Engine, RAG e cliente OpenAI não foram alterados. Nenhuma migration.

Somente o serviço web foi publicado. Os demais serviços conservam suas imagens. O worker já havia reiniciado pelo `--max-time=3600` existente às 18:28:21Z, antes desta publicação; não foi recriado pelo deploy. Arquivos locais de outras tarefas foram preservados por comparação de hashes. Nenhuma mudança no Agent, Lifecycle, Observability, quarentena 0.6.3, R2 ou equipamentos.

Ativação: acrescentado exclusivamente `allow_conversation_language=true` ao binding autorizado existente do tenant 1. Provider, modelo, prompt, propósito, timeout e demais configurações foram preservados. O valor anterior está registrado em ledger privado para rollback. Outros tenants mantêm seu opt-in independente.

## Validação executada

- Laravel/PostgreSQL: **335 testes, 2443 assertions PASS**, incluindo confirmação integrada, negativas, cancelamento, idempotência, isolamento, autorização, contexto e fallback.
- Pint: 17 arquivos PASS; npm ci/build e sintaxe JavaScript PASS.
- Prechecks antes e depois: TLS PostgreSQL positivo, CA/hostname incorretos e plaintext rejeitados; sessão/configuração segura, queue e readiness PASS.
- Backup local sem pruning e sem acesso ao R2: geração `20261111T182621Z`. Restore isolado verificado: 16 tabelas protegidas, RAG FORCE RLS, chave do backup descriptografa credencial; banco de restore removido.
- Painel real por HTTPS verificado: desktop 1440, tablet 768, mobile 390, temas claro/escuro, sem overflow e sem erros JavaScript. Histórico persiste no refresh. Retry idêntico HTTP200, conflito HTTP409, ausência de CSRF HTTP419.
- Autenticação: sessão temporária criada pelo Auth/Session nativo para administrador existente, sem endpoint de bypass; acesso anônimo redirecionado ao login. Tela de login verificada. Login com senha não foi repetido em produção; testes automatizados cobrem autenticação/RBAC. Sessão temporária revogada ao concluir.
- RAG em produção: publicação/indexação/retrieval de documento sintético em transação revertida, fonte revalidada/renderizada, tenant incorreto negado, revogação e no_match PASS; zero chamadas externas nesse teste.
- Auditoria confirmou duas respostas ASSISTED, IDs de requisição e consumo. Conversas de validação encerradas/canceladas; histórico preservado.
- Final: 97 tarefas e 28 diagnósticos, iguais ao baseline. Nenhuma Skill, diagnóstico ou sondagem iniciada. 16 tabelas protegidas iguais após a ativação; em relação ao baseline, somente a alteração autorizada no binding. FORCE RLS/TLS e runtime sem superuser/BYPASSRLS confirmados.
- Secret scan do índice e diff check: resultados nos arquivos de evidência.

## Respostas reais observadas no painel

| Entrada | Resposta observada |
|---|---|
| Boa tarde, meu consagrado! | Boa tarde! Bom te ver por aqui! |
| Salve, calabreso! Tudo certo? | Opa! Pode chegar, estou por aqui. |
| Rapaz, o YouTube tá uma carroça hoje kkk | Vamos por partes: em que momento você percebeu a lentidão? Ainda não tenho medições para apontar uma causa. |
| Meu BNG está apresentando perda de pacotes. | Entendi o relato. Ainda não há evidências suficientes para atribuir uma causa. O destino youtube.com já está registrado. Nenhuma ação em equipamentos foi iniciada. |
| Obrigado, meu rei! | Por nada! O contexto está salvo; podemos continuar quando você quiser. |

Essas respostas foram locais e determinísticas; não são apresentadas como geração OpenAI. O destino youtube.com foi mantido no contexto e o relato BNG elevou o tom para TECHNICAL/SERIOUS. Confirmação sem pendência respondeu “Não há confirmação pendente válida. Nenhuma nova tarefa foi criada.” Nenhuma operação foi autorizada por uma saudação.

OpenAI real, modelo autorizado `gpt-6.1-sol`, duas chamadas pelo painel, sem retries: **1027 tokens de entrada + 108 de saída = 1135 tokens**. Limites existentes de timeout, saída e frequência preservados. Valor monetário não fornecido pela resposta da API.

Entrada: “E você, como é ajudar num plantão tranquilo?”

Resposta ASSISTED: “Boa tarde! Num plantão tranquilo, dá para conversar com mais calma e esclarecer dúvidas sem correria. Sou o assistente virtual do Agent Center. Como está o plantão por aí?”

Mudança de assunto em conversa formal também gerou resposta ASSISTED: “Boa tarde. Certamente, podemos mudar de assunto. Sobre qual tema gostaria de conversar?”

A aplicação continua decidindo estados e ações; o modelo somente produz linguagem. Fallback foi validado por falha controlada em laboratório, sem interromper o provider operacional.

## Evidências e screenshots

Diretório: [evidence/natural-conversation-production-20261111](evidence/natural-conversation-production-20261111).

- [Desktop com OpenAI real](evidence/natural-conversation-production-20261111/browser/openai-1440-dark.png)
- [Mobile: cinco mensagens solicitadas](evidence/natural-conversation-production-20261111/browser/social-390-light.png)
- [Desktop: conversa contextual](evidence/natural-conversation-production-20261111/browser/social-1440-light.png)
- [Operations Health](evidence/natural-conversation-production-20261111/browser/health-1440.png)
- [RAG](evidence/natural-conversation-production-20261111/browser/rag-1440.png)

## Health e rollback

Health final **VERDE**; web/nginx/PostgreSQL/worker/diagnostic-worker healthy. HTTPS e readiness aprovados. Zero tarefas novas e nenhuma regressão crítica observada.

Imagem anterior retida e verificada: `agent-center-central:conversation-confirmation-20261111`, ID `sha256:298c13106ae36db3659b8e3c8a9ece156f27939e8bdbc2583550a431f54e7ced`.

Rollback preparado em `/root/natural-conversation-production-20261111/rollback.sh` (0700), com ledger 0600. Procedimento: restaurar somente o flag do binding pelo serviço de auditoria existente, restaurar o JS público anterior, alterar exclusivamente web.image, recriar somente web e limpar views compiladas. Preserva histórico, banco, providers, prompts, RAG e confirmação. Override versionado em `deploy/natural-conversation/rollback.yaml`. Configuração do override e imagem anterior verificadas; restore de backup testado isoladamente. Rollback não foi necessário e não foi executado em produção. Após rollback, repetir readiness/HTTPS/Health e registrar a causa; não executar migrations down nem restauração integral do banco para reverter esta camada.

Riscos residuais: latência/disponibilidade do provider pode acionar fallback local; respostas geradas variam. Opção de linguagem habilitada somente no binding autorizado do tenant 1. Validação de senha em produção não repetida. Sem pendências impeditivas identificadas.
