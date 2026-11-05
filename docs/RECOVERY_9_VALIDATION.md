# RECOVERY-9 — validação

**RECOVERY-9 PASS** no escopo documentado e autorizado. Produção publicada, testes e backup R2 de fechamento verificados; lacunas históricas sem evidência e pendências RECOVERY-8 continuam explicitamente preservadas.

## Escopo e paridade

Continuação de bf54d1a, sem novas subfases. Matrizes em RECOVERY_9_PARITY.md e RECOVERY_9_VISUAL.md. Funcionalidades de backend existentes foram preservadas e revalidadas, sem reescrita. Nenhuma migration, código de Agent ou AGENTS.md alterado. Nenhum device acessado, diagnóstico externo disparado ou flag habilitada globalmente.

## Testes executados

- Laravel completo em PostgreSQL isolado: **142 testes, 1.187 assertions PASS**, incluindo migrations up/down, RLS/tenant isolation, autenticação/CSRF, RBAC, Agent API/operations, Task Engine/leases/fencing/recovery, Diagnostics/Profiles/Skills, Knowledge/Prompts, LLM fallback/segredos/proveniência, Conversation/Adaptive Tone, auditoria e Operations Health.
- Test runner atualizado para aceitar explicitamente container/rede/envs do laboratório; createdb recusa base existente e cleanup remove somente base criada pelo runner. Papel runtime sem superuser/bypass/ownership. Não executado contra banco de produção.
- Primeira rodada: três falhas/um erro por timezone PostgreSQL UTC e SESSION_ENCRYPT ausente no laboratório. Corrigidos para configuração coerente Europe/Berlin e sessões cifradas. Segunda: novo teste alternava usuários sem limpar sessão; corrigido o harness usando flushSession, mantendo auth.session intacto. Rodada final integral PASS.
- Pint: 176 arquivos PASS. Composer validate --strict e audit PASS, zero advisories retornados.
- Frontend: npm ci + Vite build da pipeline existente PASS; painel usa assets public locais, também verificados no Chromium; package-lock criado para dependências já existentes. Primeira tentativa npm ci sem lock falhou; corrigida com geração do lock e repetição.
- Chromium real: 313 verificações em 19 telas × quatro viewports × dois temas; 12 renders adicionais de plataforma/tenants/backups. Capturas desktop/mobile, forms, tabelas, modal, erro e loading em evidence/recovery9/screenshots/.
- Teclado: primeira Tab alcança skip link, Enter leva ao conteúdo; Escape fecha sidebar. Harness anterior de foco após click no body falhou por posição de navegação; teste corrigido e repetido em rodada específica, sem alteração de proteção.
- Interações adicionais: sete checks PASS, incluindo erro de login, sistema escuro como padrão, persistência de tema por usuário, loading aria-busy e falha de refresh preservando conteúdo.
- Contraste calculado em superfícies opacas de cinco telas por tema: 20 achados iniciais, zero após correção. Não é certificação integral de acessibilidade.
- Browser inicial de laboratório apontou assets não servidos pelo router PHP de teste; adotado scripts/lab/router.php existente, sem alteração de aplicação para mascarar o problema.
- Bash/JS syntax, diff check, secret scan de índice e histórico executados. Ruff não aplicável: nenhum Python versionado alterado. Scanner histórico do checkout com branches: 43 commits/876 blobs na rodada inicial; scan posterior da linha de produção com secrets atuais: 27 commits/818 blobs, zero correspondências e zero caminhos históricos proibidos. Scanner básico não prova ausência universal de secrets.

## Produção e rollback

Antes: recovery8-final / revisão OCI 7d6bbc0. Consulta real confirmou 11 migrations, 28 tabelas FORCE RLS, zero Agents e zero tenants com Conversation habilitada.
Backup pré-deploy **20261105T113903Z**, 35.550.554 bytes, age/PUT/HEAD/GET/SHA256 verificados. Snapshot privado **backups/recovery9-deploy-20261105T114333Z** com assets, Compose, revisão anterior e overlay de rollback.

Publicada imagem **agent-center-central:recovery9**, revisão OCI **3f0fab51548735b1ad472894cf64c14ba38225fb**. Somente web/worker/diagnostic-worker recriados. PostgreSQL/Nginx não recriados, nenhuma migration executada. Contagens e fingerprint dos registros de usuários/tenants/Knowledge/Prompts/providers/Conversation preservados no deploy; isso não é restore independente nem comparação de todos os bytes do banco.

Falha real detectada no smoke: assets públicos 403 porque umask0077 do snapshot foi herdada pelo git checkout. Executado rollback real para imagem recovery8-final e assets anteriores; republicação com CSS/JS0644. Precheck agora valida leitura e HTTP dos assets através do Nginx sem privilégios. Secrets mantêm permissões restritas.
Rollback manual: docker compose --env-file .env -f deploy/compose.yaml -f backups/recovery9-deploy-20261105T114333Z/rollback.yaml up -d --no-deps --no-build --wait web worker diagnostic-worker; restaurar somente os dois assets públicos do snapshot, preservando 0644. Não executar migrations down nem remover volumes. Overlay deve permanecer em futuras recriações enquanto operando em rollback.

Operations Health lido após publicação: ATENÇÃO coerente com ausência de Agents. Web, PG/TLS, fila funcional, worker diagnóstico, Task Engine, HTTPS, Nginx, integridade de backup e fencing VERDE. Não declarar sistema com Agent ONLINE. Evidências sanitizadas em production-health.json/deployment.json.

Smoke Chromium de produção: **22 checks PASS** (login HTTPS público; 16 rotas autenticadas; dashboard sem Agent; Conversation desabilitada; desktop/mobile e ambos os temas; logout da sessão temporária). Precheck real PASS: TLS válido, hostname/CA errados e plaintext rejeitados, socket local preservado, PDO verify-full, job fila→worker→cache, worker diagnóstico e assets servidos por Nginx.

Validação web de produção usa login público e sessão temporária nativa Laravel de conta existente para GETs autenticados; não foi fornecida senha do operador, não houve reset/criação de usuário nem submissão de formulários de negócio. Login com senha/erro/CSRF/logout foi exercitado no laboratório sintético.

## Pendências deliberadas fora desta fase

RECOVERY-8 permanece BLOCKED: restore em VPS independente, retenção/Bucket Lock administrativo e destino de alertas externos adiados. Nenhum foi resolvido ou reaberto. Agent real/self-update continuam sem autorização de instalação; apenas implementação e laboratório verificados. Capabilities antigas não documentadas e módulos sem evidência continuam explicitados na matriz, sem promessa de recuperação integral.

## Fechamento de Git e evidências

Branches de entrega: recovery9 e production-recovery8 (linha operacional existente, sem criação de subfase). Hash remoto b92f7a6730298c7c2b5793394cb0ab57b312b120 confirmado para ambos antes do selo final. main foi preservada; para inspecionar/restaurar esta entrega usar explicitamente recovery9 ou production-recovery8. Clone limpo do GitHub com checkout recovery9, fsck --full e HEAD idêntico passou na mesma VPS; não equivale a restore independente.

Commit de UI daeac69; pin de imagem 3f0fab5; precheck de assets 75aaf03; documentação/screenshots 06284a0; normalização b92f7a6. Revisão OCI da aplicação permanece 3f0fab5 mesmo quando documentação/tooling avançam.

95 screenshots PNG reais; resumo em evidence/recovery9/browser-summary.json. production-final.json confirmou novamente contagens/schema/flags e fingerprint dos registros selecionados após rollback e smoke; os bytes CSS/JS por HTTPS coincidem com o candidato. Laboratório descartável RECOVERY-9 encerrado, sem remover qualquer laboratório preexistente ou volume de produção.

Backup de fechamento comprovado: geração **20261105T114815Z**, **57995116 bytes**, SHA-256 **dd631674772ff41b21e060361e6bb1187a9cfa3ddf85a925af89eee54bcf408f**; age/PUT/HEAD/GET integral PASS. Evidência r2-closure-verified.json conserva os indicadores de restore/retention não verificados. O selo documental posterior é sincronizado e incluído em uma geração adicional; recibo operacional mais recente permanece em backups/external-status.json e backups/r2/ (fora do Git).

Riscos residuais: ausência de Agent real/autoupdate em host autorizado; Conversation permanece desabilitada por tenant em produção; capacidades históricas sem evidência não recuperadas; preferência de tema local ao navegador; auditoria de contraste automatizada não é certificação WCAG integral; três pendências externas RECOVERY-8 adiadas. Nenhuma delas é apresentada como concluída. Nenhuma próxima fase iniciada.
