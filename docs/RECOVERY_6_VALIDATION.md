# RECOVERY-6 — validação

Base: `0bc1379`. Laravel Central, Agent 0.5.2 preservado. Nenhum FastAPI, canal externo real, tool de LLM, nova capability ou device action. AGENTS.md preservados.

## Resultado por camada

Implementação, testes, laboratório, migration, imagem, restore e HTTPS restrito: PASS.

**RECOVERY-6 BLOCKED: publicação externa pendente de destino autorizado/verificado de backup.**

Não existe destino externo autorizado configurado. Foi solicitada sua definição, sem pedir credenciais em chat. Produção estava sem tenants/usuários/Agents. Após o usuário fornecer o e-mail, o Artisan nativo criou um tenant inicial e um platform_admin com senha forte temporária em arquivo privado e troca obrigatória. Não foram inventadas identidades externas nem validação de Agent de produção; continuam zero Agents. Login HTTPS, bloqueio do dashboard até troca e logout CSRF passaram no Chromium de produção, sem alterar a senha. Nenhum tenant de produção foi ativado automaticamente.

O certificado TLS está emitido, válido e aplicado. O gate do vhost permite apenas loopback/IPs desta VPS. A aplicação não foi liberada para acesso externo. O código completo da fase está implantado, sem dividir a entrega em subfases.

## Implementação

Migration aditiva com quatro novas tabelas FORCE RLS: conversation_settings, conversations, conversation_messages e conversation_events. Models correspondentes, scopes, constraints, índices, histórico imutável pelo runtime e Policies de tenant/ownership. Total: 27 tabelas FORCE RLS.

ChannelAdapter/WebAdapter estabelece identidade via sessão Laravel. Engine determinístico reutiliza criação/cancelamento do Diagnostics Engine existente. Nove estados, doze intents, pending target, confirmação, encerramento natural, continuidade com preservação de evidências e cancelamento nativo. Conclusão/proveniência opcionais reutilizam RECOVERY-5. LLM nunca cria tarefas ou muda classificação.

Adaptive Tone AUTO usa até quatro mensagens, regras fechadas e fallback natural. Regionality=NOT_INFERRED. Não há seletor nem perfil permanente. Testes provam que tom não muda plano/digest/thresholds nem autorização.

UI em Diagnósticos → Conversar, sem redesign do dashboard/sidebar. Histórico paginado, transcript ordenado, novo contexto após CLOSED, atualização leve, etapas DNS agrupadas, links para evidências e interpretação assistida separada. Credenciais usuais são removidas antes de persistir texto. Idempotência usa HMAC e locks PostgreSQL, com conflito em chave reutilizada para corpo diferente.

## Matriz de validação

| Check | Evidência |
|---|---|
| Laravel suite completa | 124 testes / 993 assertions PASS |
| PostgreSQL migrations up/down | Nove migrations PASS, DB isolado |
| RLS / Policies / ownership | Cross tenant e mesmo tenant/outro usuário negados |
| A: YouTube → resultado → obrigado → CLOSED | PASS em suite |
| B: continua travando, contexto preservado | PASS; confirmação antes de novo escopo |
| C: pedido sem alvo → alvo curto | PASS na mesma conversa |
| D/E: cancelar / valeu parceiro | PASS, nativo e idempotente |
| F/G: bão/trem e formalidade | PASS, mirror leve sem atributo sensível |
| H/I: refresh/histórico e novo pedido após CLOSED | PASS |
| J/K: isolamento e LLM ausente | PASS, fallback/classificação intactos |
| L/M: duplicação e concorrência | Suite + dois processos PHP/PostgreSQL reais PASS |
| N/O: invariância técnica e Skill indisponível | PASS, nenhuma execução fictícia |
| Redaction / prompt injection | PASS, sem execução de shell/tools/device |
| Retenção / payload imutável | Prévia sem delete; runtime impedido de editar histórico |
| Chromium HTTPS | Onze checks PASS; login/logout e CSRF reais em lab; bootstrap/login/logout verificados em produção |
| Viewports | 1366x768 e 390x844, sem overflow horizontal mobile |
| Screenshots | Seis renders reais em docs/evidence/recovery6 |
| Agent real autorizado | Python 0.5.2 em lab, DNS localhost → NORMAL |
| Agent API/Task Engine/Diagnostics | Suite + Agent Python real em lab PASS |
| Frontend build | Vite / Node24 efêmero PASS |
| Pint | 157 arquivos PASS |
| Composer validate --strict / audit --locked | PASS / nenhum advisory |
| Compose config / Nginx config | PASS |
| Live / ready HTTPS IPv4 e IPv6 | 200; verificados a partir desta VPS |
| HTTP redirect | 308 para HTTPS |
| Recovery gate | Origem loopback alternativa não autorizada recebeu 403 |
| Cookies | Secure, HttpOnly, SameSite=Lax; APP_DEBUG=false |
| Certificado | Let's Encrypt, domínio correto, válido até 02/02/2027 |
| Renovação específica --dry-run | PASS, CA staging; timer existente ativo |
| Backup/restore isolado | Dados/versões/histórico e chave recuperada PASS |
| Prova com chave errada | Rejeitada, exit 1 |
| Secret scan / diff check | PASS no índice Git; scanner básico |

Screenshots usam somente fixture do lab, sem secrets, tokens, dados reais ou senha preenchida no login. Fluxo HTTPS usa o mesmo certificado válido em listener privado de laboratório, sem ignorar validação TLS. DNS localhost limita probes à própria máquina do Agent do lab. Não houve teste de conectividade em device, terceiro ou Agent de produção.

## Restore e correção do verificador

Dump do DB isolado inclui identidade, Agent/enrollment, diagnostics/tasks/plans/attempts, Knowledge e versões, Prompts, provider/model/binding e secret AES-GCM, interpretação/proveniência e Conversation com mensagens/eventos. Restaurado em agent_center_restore_check, nunca sobre produção. Contagens, 27 FORCE RLS, views security_invoker e grants foram conferidos; contexto de runtime sem tenant não vê dados e não possui tabelas.

A prova lê uma cópia da chave do backup de secrets e compara digest do secret recuperado sem emitir seu valor. Confere também conversa CLOSED, vínculo ao diagnóstico e contagem do histórico. Erro inicial de permissões revelou falso positivo no script existente: a execução PHP podia encerrar sem sinal útil e o shell anunciar PASS. Corrigido: exceptions capturadas com erro sanitizado, exit 1 e shell exige marcador explícito PASS. Restore repetido com permissões privadas corretas passou; chave errada confirmou a falha fechada.

Backups incluem dump integral, configs, Git bundle/commit, APP_KEY/env, master key/key ring e material TLS somente deste projeto, em secrets separados do Git. A cópia externa não está configurada; não se declara proteção contra perda total da VPS.

## Operação e limites

Cinco serviços da Central saudáveis após deploy. Operations Health do tenant inicial: ATENÇÃO por ausência de Agent; PostgreSQL e marcador funcional do worker saudáveis, zero tarefas ativas/stale leases. Worker de diagnósticos mantém marcador monotônico funcional próprio; nenhuma métrica de COMMIT foi usada. Agent lab anunciou quatro capabilities efetivamente disponíveis, heartbeat age 19s/poll age 1s na amostra final, 7 FDs, 3 threads e cerca de 30 MiB RSS, sem tarefas ativas ou leases vencidas. Isso é uma observação operacional, não um ensaio de leak prolongado desta fase. Continuidade consulta capabilities antes de oferecer aprofundamento. Agent lab manteve heartbeat/poll durante testes; seu runtime 0.5.2 não foi alterado. Sem Agent em produção, não existe heartbeat/poll de produção a declarar saudável.

Imagem recovery6; rollback preservado recovery6-rollback-0bc1379 e overlay. PostgreSQL e serviços não relacionados não foram reiniciados. Somente vhost deste domínio e hook de renovação limitado ao seu lineage foram adicionados ao Nginx compartilhado.

Regras de intenção/tom têm vocabulário finito. Ambiguidades ficam sem execução ou pedem confirmação; não há NLU irrestrito. Detecção de secrets por padrões não cobre toda credencial em texto livre. Histórico deve ser tratado como dado privado e backups precisam de política externa. Repetição explicitamente confirmada pode produzir novo diagnóstico; reenvio do mesmo request_key não.

Conversas abertas são preservadas até encerramento; retenção de fechadas exige purge privilegiado explícito e tem janela de backup de sete dias. Falha/cancelamento local de LLM não garante cancelamento remoto, conforme limites da RECOVERY-5. Sem provider externo de produção configurado, testes LLM usam fakes/TLS local; nada foi cobrado a provider real.

Senha temporária do administrador: `secrets/bootstrap-admin-password`, modo 0600, diretório root 0700, fora do Git e sem mount persistente no web. Recuperar somente via acesso administrativo à VPS e remover esse arquivo após a troca. Nenhum envio de e-mail foi realizado.
