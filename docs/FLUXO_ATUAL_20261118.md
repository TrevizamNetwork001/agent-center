# Agent Center — fluxo atual e situação das entregas

Consolidação documental em 18/11/2026. Fonte: código e relatórios deste checkout. Os resultados de produção e testes abaixo são evidências históricas dos documentos citados; não constituem nova inspeção de containers, banco, equipamentos ou chamadas externas.

## Visão geral

A Central usa Laravel e PostgreSQL. O Agent Python é um cliente separado. Não existe autorização para FastAPI, shell arbitrário ou decisões operacionais pelo LLM.

```text
Usuário Web → HTTPS/Nginx → Laravel Auth/Session/CSRF → tenant + RBAC/Policies
Telegram → webhook validado → identidade/vínculo do tenant → ingress/Runner
                                      ↓
                            Conversation Engine
                                      ↓
                    intenção determinística + contexto
                         ↙                         ↘
           Knowledge/RAG/LLM                 pedido de diagnóstico
          resposta informativa             catálogo/Profile/Skills
          sem poder operacional              ↓
                                  Preflight + autorização + confirmação
                                              ↓
                                   Diagnostics → plano/steps
                                              ↓
                                   Task Engine → task/attempt/lease
                                              ↓
                                Agent API autenticada ← Python Agent
                                              ↓
                               política local + execução permitida
                                              ↓
                                  resultado estruturado + journal
                                              ↓
                                  classificação determinística
                                              ↓
                              Web/conversa/Telegram outbox + auditoria
```

O fluxo de equipamentos RouterOS é uma extensão comprovada em laboratório com simulador; não deve ser interpretado como liberação para a borda física.

## 1. Entrada, identidade e isolamento

Web usa autenticação, sessão, Hash, CSRF, Policies e Gates nativos Laravel. Existem platform_admin, tenant_admin, operator e viewer; administrador de plataforma seleciona o tenant explicitamente. Scopes, contexto de conexão, constraints e PostgreSQL FORCE RLS reforçam o isolamento. Workers e tarefas devem estabelecer seu próprio contexto de tenant, sem herdar contexto de outra execução.

Ver [identidade e tenancy](AUTH_AND_TENANCY.md), [roles do banco](DATABASE_ROLES.md) e [arquitetura](ARCHITECTURE.md). Esses documentos contêm fases antigas: afirmações como “Agent futuro”, HTTP local ou tarefas bloqueadas descrevem aquelas fases, não substituem os fechamentos posteriores.

## 2. Conversa Web e Telegram

O Conversation Engine preserva histórico, alvo, Agent, intenção, confirmação e estado técnico. O pedido passa por validação determinística; confirmação e idempotência evitam disparos repetidos. Cancelamento usa o Task Engine e não apaga resultados. A apresentação pode adaptar o tom sem alterar autorização ou conclusão técnica.

No Telegram, ingress, vínculo de identidade, Runner, outbox e entrega usam os componentes existentes. O fechamento de 12/11 registra publicação, webhook verificado e política de inatividade de 15 minutos. Não há WhatsApp entregue ou autorizado por esta consolidação.

Ver [Conversation Engine](CONVERSATION_ENGINE.md), [operação Telegram](TELEGRAM_V1_OPERATIONS.md) e [fechamento de produção](TELEGRAM_V1_FINAL_PRODUCTION_20261112.md).

## 3. Diagnóstico e execução

A solicitação é convertida em intenção e catálogo permitido. Profiles definem o escopo; Skills precisam de allowlist. Preflight verifica disponibilidade e autorização; UNKNOWN, DENIED ou STALE não equivalem a permissão. Conforme o fluxo, a confirmação vincula a operação antes de materializar plano e tarefas.

O Task Engine controla claim, START, lease, renovação, resultado, cancelamento e reconciliação. O Agent autentica-se na API Laravel, revalida a política, executa o contrato fechado e mantém journal para impedir replay inseguro. A Central valida evidências e produz classificação determinística; o LLM só auxilia na apresentação.

Ver [Task Engine](TASK_ENGINE.md), [Diagnostics](DIAGNOSTICS.md), [Profiles e Skills](PROFILES_AND_SKILLS.md), [Agent API](AGENT_API.md), [preflight em produção](DIAGNOSTIC_PREFLIGHT_PRODUCTION.md) e [planejamento de intenção](DIAGNOSTIC_INTENT_PLANNING_FINAL_PRODUCTION.md).

## 4. Knowledge, RAG e LLM

Knowledge e Prompts são versionados. O RAG recupera fontes do PostgreSQL no contexto autorizado; a resposta deve respeitar publicação, revogação e proveniência. O provider é administrado por tenant e as credenciais ficam criptografadas, fora do Git em sua forma secreta. Sem fonte/provider disponível, aplica-se o fallback previsto. O modelo não recebe autoridade para executar tarefas, shell ou acesso a equipamentos.

O relatório de 11/11 registra RAG V1 publicado, migration Laravel específica, FORCE RLS e prova de recuperação em banco separado. Registra também teste real limitado e smoke de produção; isso não comprova qualidade universal das respostas.

Ver [Knowledge](KNOWLEDGE.md), [Prompts](PROMPTS.md), [LLM](LLM.md), [RAG V1](RAG_V1_SPEC.md) e [fechamento de produção RAG](RAG_V1_PRODUCTION_PASS_20261111.md).

## 5. Agent e Lifecycle Manager

Enrollment e credenciais vinculam o Agent ao tenant. Heartbeat/capabilities informam disponibilidade. Atualizações passam pelo Lifecycle existente: catálogo autorizado → compatibilidade → assinatura Ed25519/hash → precheck → aplicação → postcheck → conclusão ou recuperação/rollback. Pacote parcial não autoriza instalação; downloads limitados podem ser retomados.

O fechamento de 16/11 registra Agent **0.6.6 na VM teste**, protocolo de pacote 3, sequência 10 e observação de aproximadamente 901 segundos. A transição pelo painel usou ponte assinada e pacote final; ambas as operações terminaram COMPLETED. Isso não representa atualização de todos os Agents nem homologação de equipamento físico.

Ver [arquitetura do Agent](AGENT_ARCHITECTURE.md), [operações](AGENT_OPERATIONS.md), [transição assinada](AGENT_PANEL_SIGNED_TRANSITION_066.md) e [rollout 0.6.6](AGENT_PANEL_ROLLOUT_066_20261116.md).

## 6. MikroTik: laboratório separado da operação física

Fluxo homologado: Conversation Web/Telegram → seleção LAB no inventário → Preflight e confirmação vinculada → Diagnostics/Task Engine → Agent Python não-root → adaptador RouterOS → SSH no simulador → evidência validada → Web/outbox Telegram. O Laravel não abre SSH nesse fluxo integrado.

As seis leituras de laboratório são identidade, peers BGP, consulta específica de rota, interfaces, contadores e recursos. Não há configuração remota nem CLI livre. O adaptador foi homologado contra simulador RouterOS 7.16.2; isso não certifica a versão/modelo da borda real.

O relatório final registra 190 testes Python, suíte Laravel com 588 testes/5.151 asserções e 21 skips condicionais, além de E2E systemd com 225 asserções e rollback. São resultados históricos, não testes executados nesta consolidação. O rollout posterior publicou a infraestrutura 0.6.6 somente na VM autorizada.

**Equipamentos físicos continuam sob freeze.** A primeira leitura física exige autorização operacional específica e escopo próprio, inclusive tenant, ator, equipamento, Agent, destino, fingerprint, credencial, janela e limites. Não inferir autorização a partir do cadastro ou do PASS de laboratório.

Ver [implementação integrada](MIKROTIK_READONLY_INTEGRATION_V1_IMPLEMENTED.md), [aceite 0.6.6 LAB](MIKROTIK_READONLY_066_LAB_COMPLETION.md), [rollout posterior](AGENT_PANEL_ROLLOUT_066_20261116.md) e [preparação física](MIKROTIK_PHYSICAL_READONLY_PREPARATION_V1.md).

## 7. Operação, backup e rollback

Auditoria, Operations Health e observabilidade registram o estado técnico com sanitização. Secrets, tokens, cookies, dumps e chaves privadas não pertencem ao GitHub. PostgreSQL, FPM e workers não devem ser publicados externamente.

Backup e recuperação têm procedimentos próprios; GitHub não substitui backup de banco, configurações e identidades. Não concluir que o backup externo está funcionando hoje a partir de um relatório antigo. Uma recuperação precisa conferir manifesto, schema, chaves, integridade e restore separado.

Rollback deve usar a imagem/pacote anterior autorizado e preservar dados, identidade e journal. Não executar migration down ou restaurar dump por cima da base atual como reversão genérica. A documentação do rollout 0.6.6 descreve a restauração preparada e suas condições; sua execução exige autorização operacional.

Ver [deploy](DEPLOYMENT.md), [backup](BACKUP_POLICY.md), [recuperação](DISASTER_RECOVERY.md), [saúde](OPERATIONS_HEALTH.md) e rollback nos relatórios específicos de publicação.

## 8. GitHub e estado de conclusão

Repositório configurado: https://github.com/TrevizamNetwork001/agent-center, remoto SSH pela porta 443. A privacidade foi registrada pelo operador no histórico; não foi reconfirmada por API nesta revisão.

Na consulta read-only de 18/11/2026:

- HEAD local: `f227293` (fechamento do rollout 0.6.6).
- `main` no GitHub: `818d57bc022e07ecea3daa0a5d85316a4e92fbc3`, retornado por `git ls-remote`.
- Existem muitas modificações locais e arquivos não rastreados de código, documentação, evidências e artefatos.
- Portanto, **não é correto afirmar que tudo foi enviado ao GitHub**. Hashes distintos exigem conciliar o histórico antes de qualquer push; alterações locais precisam de revisão e varredura, sem inclusão indiscriminada de artifacts ou secrets.
- GitHub armazena fonte e histórico. Um push não significa deploy da Central ou instalação de Agent.

O fetch inicial foi impedido pela proteção de escrita de `.git/FETCH_HEAD`; após execução autorizada, a comparação confirmou **120 commits exclusivos locais e 19 exclusivos no remoto**. O histórico remoto inclui fechamentos de backup R2 e recuperação; não foram promovidos para este checkout nesta revisão. Nenhum force push é apropriado para resolver essa diferença. A documentação desta consolidação permanece local, sem commit ou push, até a conciliação segura dos históricos.

Ver [política do remoto e verificações antes de push](GIT_REMOTE.md). Esta consolidação não publica pacote, não altera serviços e não acessa dispositivos.

## Matriz de situação

| Frente | Situação sustentada pelas evidências consultadas |
| --- | --- |
| Central Laravel/PostgreSQL, identidade e tenancy | Implementadas; relatórios de produção posteriores à fundação |
| Task Engine, Diagnostics, Profiles/Skills | Implementados; execução limitada por catálogo e autorização |
| Conversation Web e Telegram | Publicação documentada; não revalidada ao vivo nesta revisão |
| Knowledge/RAG/LLM | Publicação RAG documentada em 11/11; LLM sem autoridade |
| Lifecycle e Agent 0.6.6 | Rollout documentado em 16/11, restrito à VM teste |
| RouterOS por Task Engine/Python Agent | LAB PASS com simulador; infraestrutura publicada na VM teste |
| Consulta a MikroTik físico | Não homologada por estes relatórios; freeze mantido |
| WhatsApp e novas integrações | Fora deste escopo; nenhuma entrega declarada |
| Todo o workspace no GitHub | Não: alterações locais e diferença de hashes confirmadas |
| Saúde e backup externo atuais | Exigem verificação operacional específica; sem nova atestação |
