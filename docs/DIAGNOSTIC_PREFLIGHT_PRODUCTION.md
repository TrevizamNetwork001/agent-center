# Diagnostic Preflight V1 — publicação operacional

**DIAGNOSTIC PREFLIGHT PRODUCTION PASS**

## Implantação

Código da Central integrado por fast-forward: **5774f9e**, correspondente à integração restrita de **51da692/38530cd** sobre a base publicada. Agent homologado: **6320f7c, 9f4e3f0**; homologação operacional: **ad6bc1e**. A integração preservou por hash as alterações locais alheias no checkout operacional. O ajuste de Protocol 0.6.4 que já estava publicado foi guardado em stash; a única diferença nesse arquivo é a inclusão de 0.6.5. Não houve publicação de Observability.

Imagens Preflight retaggeadas dos candidatos homologados, sem rebuild de componentes não autorizados:

| Papel | Imagem | ID |
|---|---|---|
| Fila e scheduler | `agent-center-central:preflight-v1-web-20261112` | `sha256:84b15f232fea01aabe1987d97893be1b24401b6affb966c997707c94edc26e71` |
| Web, com a correção de foto posteriormente aprovada | `agent-center-central:preflight-v1-avatar-web-20261112` | `sha256:f2cfe2e21820890887f430229dbf88e6346bf4dc2b15952410c7c3bf813c5f7f` |
| Diagnóstico | `agent-center-central:preflight-v1-diagnostic-20261112` | `sha256:76db032a64e36647c27f7bae84b58bdb971337006520a5aee765c2e665f6addf` |

A Central manteve compatibilidade com o Agent 0.6.4 antes da atualização. A VM teste foi atualizada **pelo painel**, com upload do manifesto/assinatura/artefato e confirmação no formulário nativo. Operação Lifecycle **17e55b0b-365a-41c2-8457-b502080c9b1b**, `COMPLETED`, `postcheck=true`, versão final **0.6.5**. Não foi instalado arquivo de Agent por SSH e a VM não sofreu reboot.

## Release operacional e companions

Release **0.6.5, sequência 6**, acima da sequência instalada 5. Sequências 3 e 4 em quarentena permaneceram intactas. Ed25519 conferida com a autoridade operacional existente; a chave pública configurada na Central e na VM tem SHA-256 `5f16350383c280c1b39d4f7257f09ecbe1002b1d59a1721ceb8bf4d5091195e1`. Nenhuma chave de laboratório foi usada.

O runtime operacional tem exatamente os bytes homologados: `a2de84beb6cb519047c6123423fb420d9d410b41b85c6c9b4a49df63b562d315`. O manifesto operacional e a assinatura são novos, com sequência própria; a igualdade dos bytes do runtime com o laboratório foi comprovada antes da publicação.

A substituição automática autorizada dos companions foi realizada exclusivamente pelo Lifecycle existente, derivando-os do runtime assinado:

- `updater.pyz`: `c1bbbe6ba0dacde04387cf60ca30539b6a8b444f1e30bff2005c42e38d880ab1`.
- `remote-updater.pyz`: `be9027b4bf9f9bb30b541787cc8644ca5aa4d558eed0678302b7772005d4d8eb`.

Nenhum updater novo foi criado; não houve troca manual de companions, bypass de assinatura, alteração de privilégios, política, ACL de destino ou freeze.

## Backup e rollback

Backup local da Central **20261112T182655Z**, sem prune nem operação em R2. Restauração real em banco separado verificou 16 conjuntos protegidos, FORCE RLS, configuração dos Agents e decifragem da credencial com a chave respaldada. Banco de restauração removido ao final.

Na VM, backup preventivo verificou **58 arquivos** e uma cópia SQLite consistente do journal, além de identidade, configurações, políticas, helper, units e permissões. O Lifecycle também gerou seu backup nativo na operação, com manifesto e os companions 0.6.4 verificados por hash. Identidade atual e identidade respaldada são iguais. Nenhum evento do journal foi sobrescrito; contagens permaneceram em 103 execuções e 412 eventos durante as verificações.

Rollback automático transacional foi homologado anteriormente no laboratório; não foi acionado na produção, pois runtime, helper, políticas e serviços permaneceram saudáveis. Há compose e configuração HTTPS anteriores preservados. A Central compatível deve ser mantida enquanto houver Agent 0.6.5; não restaurar uma Central incompatível antes de recuperar o Agent. Backups não devem substituir identidade nem journal atual.

## Rota HTTPS e preflight

A validação real identificou uma omissão no roteamento HTTPS: o endpoint novo recebia 403 antes de chegar à Agent API. O artefato assinado e o helper estavam corretos. Foi acrescentada **somente** a alternativa exata `diagnostics/preflight` à lista de rotas POST da Agent API existente. O snippet de acesso ao painel, autenticação por token, limite de origem do Laravel, FORCE RLS e políticas de destino não foram alterados.

Antes da alteração, um listener HTTPS isolado e exclusivo de loopback comprovou: POST sem token exige autenticação (401), GET é negado (403), login e rota não aprovada de origem não permitida continuam negados (403). Syntax check passou e o Nginx foi recarregado sem reiniciar a VM.

Preflight real para **8.8.8.8/ICMP** respondeu **DENIED / policy_denied**, vinculado à revisão 4 e ao digest da política. Decisão vencida foi testada em transação revertida e retornou **UNKNOWN**. Nenhuma autorização desconhecida, vencida ou negada foi convertida em ALLOWED. Capability/helper instalados, versão e hashes foram conferidos separadamente; a checagem de helper é somente leitura.

Web e Telegram reconheceram `ROUTE_PATH_ANALYSIS`, escolheram **MTR_ICMP / mtr_icmp**, sem HTTP/HTTPS, não ofereceram confirmação e mantiveram objetivo NÃO EXECUTADO/INCONCLUSIVO. Zero tarefas e zero diagnósticos criados. Telegram percorreu ingresso/outbox/delivery nativos com transporte capturado e transação revertida, sem enviar mensagem de teste ao chat real e sem alterar seu vínculo original.

## Observação e intermitência externa

Os dois intervalos iniciais foram interrompidos e preservados, não contados como observação de 15 minutos concluída. A consulta de leitura `getWebhookInfo` teve `DELIVERY_UNKNOWN`; Agent, updater, filas e Operations Health permaneceram saudáveis.

A **imagem anterior** reproduziu falha de resolução DNS antes de TCP/TLS (`namelookup_time=0`, `connect_time=0`, `primary_ip` vazio, cerca de 4 segundos). O cliente Telegram tem exatamente o mesmo hash nas imagens anterior e publicada. Não se atribuiu essa intermitência ao Agent nem se modificou Telegram, DNS ou webhook.

A coleta final preserva todas as tentativas e repete, no máximo duas vezes, somente essa consulta de leitura quando há indisponibilidade de transporte. Negação, URL de webhook incorreta, falha persistente ou falhas de Agent/updater/política/journal continuam bloqueando a validação. Isso não altera execução, autorização, filas ou testes determinísticos. As ocorrências e reconsultas devem permanecer explícitas no resultado final.

## Encerramento

Observação completa: **18:43:43 UTC a 18:58:49 UTC em 12/11/2026**, **906,45 segundos**, **31 amostras**. Zero sondagens, zero erros novos de Agent/updater e nenhuma regressão crítica. Journal íntegro e invariável: 103 execuções/412 eventos. Boot ID igual em todas as amostras, comprovando ausência de reboot da VM. Identidade, tokens/configurações, política-base, política gerenciada e teto administrativo mantiveram os hashes anteriores; hardening do principal preservado.

Houve **4 tentativas de leitura ao Telegram sem resposta de transporte durante o intervalo final**, recuperadas por reconsulta limitada. Não são apresentadas como ausência de erros externos. Os 16 checks de health concluíram PASS, incluindo webhook correto/registrado, fila sem falhas, PostgreSQL/TLS/FORCE RLS, RAG READY_EMPTY e credenciais decifráveis. A consulta final também exigiu uma reconsulta e terminou PASS; ambas as tentativas foram preservadas. Operations Health final **VERDE**. O risco residual é a intermitência de DNS/tempo de conexão anterior ao TCP/TLS do Telegram, reproduzida na imagem anterior, sem alteração do cliente ou da infraestrutura DNS nesta tarefa.

O código em execução foi conferido por hash nos quatro papéis: 7 arquivos em Web/fila/scheduler e 5 no worker de diagnóstico. A função de autorização compartilhada do executor (`mtr.authorize`) foi chamada com configuração recarregada e confirmou DENIED para 8.8.8.8/ICMP, sem chamar coletor nem disparar tarefa. A política efetiva atual tem zero redes MTR autorizadas; portanto não se forjou um ALLOWED real nem se ampliou destino para produzir um teste positivo. ALLOWED e mudanças de política/executor permanecem comprovados pelos testes de laboratório homologados.

Validações disponíveis: **149 testes Python** da candidata exata, **503 testes Laravel** da homologação Preflight e nova regressão completa **505 testes/3.839 asserções PASS** na preparação separada do cache de avatar, com FORCE RLS e testes de concorrência de diagnóstico/conversas. Pint: 311 arquivos PASS. Build incremental das imagens e checks de TLS, readiness, filas, worker, assets e assinatura passaram. Não houve migration nova nesta publicação.

O commit de implementação Preflight publicado é **5774f9e**; a correção adicional de foto aprovada pelo usuário é **b7e5108**. O relatório registra a integração HTTPS adicional e sua prova isolada; nenhuma política de destino, privilégio, device freeze ou release em quarentena foi modificada. Backups/compose anterior e rollback transacional estão preservados; rollback não foi necessário na produção. Não se afirma ter exercitado rollback manual de uma operação concluída em produção.

## Solicitação adicional: lentidão da foto

A foto atual tem **493.276 bytes**, PNG 424×602, servida com `no-store`. Medição real no navegador mostrou download repetido de aproximadamente 493 KB em cada navegação. Em simulação de 1 Mbps/80 ms, o segundo carregamento do perfil levou **4,22 segundos**.

Correção separada pronta no commit **b7e5108** e imagem local **agent-center-central:avatar-revalidation-lab-20261112**, ID `sha256:f2cfe2e21820890887f430229dbf88e6346bf4dc2b15952410c7c3bf813c5f7f`. Apenas o avatar autenticado do próprio usuário passa a revalidar cache privado por ETag; páginas/erros continuam no-store. Testes garantem 304, troca/remoção, logout e proteção entre contas. Resultado: 505 testes/3.839 asserções, concorrência, Pint e build PASS.

A revisão automática inicialmente rejeitou essa publicação por estar fora da autorização expressa de Preflight. O usuário então autorizou especificamente: **“pode aprovar para ja testar”**. Após essa autorização, a imagem foi publicada exclusivamente no serviço Web, com rollback preparado; os demais serviços e o Agent não foram alterados.

Teste real pós-publicação na mesma simulação: segundo carregamento do perfil **4.220 ms → 297 ms**; tráfego da imagem **493.576 bytes → 300 bytes de cabeçalhos, corpo 0**, com **HTTP 304**. O primeiro carregamento ainda transfere a imagem original. A requisição condicional anônima foi redirecionada para login, sem fornecer a foto. Agent/Web/Telegram/Operations passaram no navegador; após a troca Web, o Agent manteve PID/boot, runtime, companions, política e journal, sem erros novos registrados.

As conversas exclusivas de validação foram encerradas e a última sessão temporária removida. Nenhuma tarefa, diagnóstico ou mensagem Telegram externa foi criada pela limpeza.

A coleta inicial de desempenho incluiu cabeçalhos de uma sessão temporária de teste. Essa sessão foi invalidada imediatamente e os cabeçalhos foram removidos da evidência. A sessão não é um token de Agent; identidade/tokens operacionais não foram alterados. Novas coletas filtram cabeçalhos sensíveis.

Health depois da publicação da foto: **PASS / Operations VERDE**, consulta Telegram OK na primeira tentativa, filas sem pendências/falhas, runtime PostgreSQL sem superuser/bypassrls. O usuário confirmou carregamento rápido após a publicação. A correção não elimina o custo do primeiro download de uma foto ainda não armazenada pelo navegador; evita o download integral repetido, mantendo revalidação autenticada.
