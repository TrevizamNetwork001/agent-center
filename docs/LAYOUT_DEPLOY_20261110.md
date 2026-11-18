# Publicação do layout — Agent Center — 10/11/2026

Status: publicado e validado em produção, com autorização do usuário nesta conversa.
URL: https://agente.trevizamnetwork.com.br

## Versão

Imagem: agent-center-central:layout-review-20261110
ID: sha256:61729f0049b42771bf8e11782031bcc2135bb794d62eda9aafcf13445efc1b37
Base preservada: agent-center-central:openai-provider-visibility-20261109
ID anterior: sha256:e95d935e304dc7ce65e606c4757bcf53cf67f7979b45b4ecbff14c145e1068cf

Imagem criada como overlay restrito da imagem anterior. Inclui as correções de interface anteriormente preparadas e as melhorias adicionais aprovadas: navegação móvel e foco, tabelas responsivas, releases integrado ao layout, títulos, textos em português, badges, cabeçalho com nomes longos, alvos móveis e redução de movimento. Auth, Policies, rotas, API do Agent, dependências e demais funcionalidades da imagem anterior foram preservados. O JSON do dashboard mantém user.role e recebe user.role_label adicional.

O patch incremental foi aplicado ao checkout de /opt/agent-center após conferir os hashes de origem. Assets foram sincronizados com o diretório servido pelo Nginx, preservando bundles antigos para páginas já abertas. A imagem web está registrada em deploy/compose.yaml. Publicação por scripts/compose.sh up -d --no-deps --no-build --pull never web. Somente web foi recriado. Views compiladas foram limpas com artisan view:clear. Nenhuma migration executada.

## Validações

- Pré-publicação: build Vite aprovado; 24 testes Laravel/237 assertions; captura final adicional 1 teste/34 assertions; 272 combinações visuais iniciais e 24 adicionais para os últimos ajustes; 15 verificações adicionais de teclado, cabeçalho e tabelas aprovadas.
- Imagem final: compilação de todos os templates Blade aprovada em container isolado sem rede.
- Produção HTTPS: /login, /health/live e /health/ready respondem 200.
- Cinco assets públicos conferidos por hash: CSS, JavaScript compartilhado, manifest, bundle React e CSS do dashboard.
- Cookie de sessão com Secure, HttpOnly e SameSite=Lax.
- /dashboard, /agents e /users sem sessão redirecionam para login.
- POST /login sem CSRF: 419.
- Chromium na rede do host: login em 360, 768, 1366 e 1920 px, temas claro/escuro; oito combinações aprovadas, sem overflow ou erros JavaScript. Uma tentativa anterior pela rede bridge recebeu 403; a política de acesso não foi alterada.
- Web, worker, diagnostic-worker, PostgreSQL e Nginx do projeto principal healthy. IDs dos quatro serviços além de web permaneceram iguais.
- Sem marcadores de erro de aplicação encontrados nos logs do novo web durante a conferência.
- git diff --check aprovado.

As páginas autenticadas foram validadas com fixtures de teste, sem usar senha de operador em produção. Não foram executadas submissões operacionais, acesso a equipamentos ou atualização de Agents. Lighthouse e medições de latência não fazem parte desta entrega.

## Backup e rollback

Backup restrito: /root/agent-center-layout-deploy/backup/
Contém dump PostgreSQL custom, assets anteriores, manifest anterior, compose anterior e lista dos containers anteriores. Listagem pg_restore validada. SHA256 do dump: 366a78991da0d9fb48246096b26de073294c940f8b11d4e4e30bf7b1f1109d8b.
O dump contém dados privados e permanece local, em diretório 0700 e arquivo 0600. Não foi restaurado nem enviado externamente.

Rollback preparado em /root/agent-center-layout-deploy/rollback.yaml:

1. Confirmar que o tag da imagem anterior corresponde ao ID acima.
2. Usar scripts/compose.sh -f /root/agent-center-layout-deploy/rollback.yaml up -d --no-deps --no-build --pull never web.
3. Restaurar CSS, JS e manifest do backup, preservando bundles antigos e quaisquer alterações posteriores. Reverter apenas a entrada services.web.image em deploy/compose.yaml para manter a configuração persistente compatível com o rollback.
4. Executar scripts/compose.sh exec -T web php artisan view:clear e conferir readiness HTTPS.
5. Se necessário reverter o checkout, usar layout-improvements.patch em modo reverso somente após conferir hashes de destino em changes.json, preservando trabalho concorrente e modificações anteriores à revisão.

Nenhuma restauração de banco, migration down ou alteração em Agent é necessária para este rollback de interface.

Artefatos e evidências: /root/agent-center-layout-deploy/ e /root/agent-center-layout-followup/.
