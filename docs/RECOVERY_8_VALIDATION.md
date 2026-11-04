# RECOVERY-8 — validação final

**RECOVERY-8 BLOCKED: backup externo autorizado não configurado/verificado e nenhuma
restauração em servidor independente a partir dessa cópia.** Git remoto privado,
custódia externa de chaves e receptor externo de alertas também não foram fornecidos.
Não existe Agent real autorizado. Não iniciar RECOVERY-9.

## Inventário e escopo

AGENTS.md raiz/app lidos, preservados. Base 2f3fc8f, checkout inicialmente limpo em
/root/agent-center-recovery7. origin aponta /opt/agent-center, na mesma VPS.
Estado anterior/rollback: evidence/recovery8/INITIAL_STATE.md.
Debian 13.7, Docker 29.9.2/Compose 5.6.1, RAM 7.8 GiB, disco 99 GiB/22% inicial,
NTP sincronizado. Outros projetos Docker não foram alterados.

Produção permaneceu imagem agent-center-central:recovery6, 27 tabelas FORCE RLS e
schema existente. Checkout/laboratório: fonte RECOVERY-7 com hardening RECOVERY-8,
28 tabelas FORCE RLS. Não foi promovida implicitamente a aplicação nem suas migrations.
Nenhuma conta/senha/Agent foi criado em produção. Operador confirmou web abrindo.

## Produção aplicada

- PostgreSQL agora SSL on, mínimo TLS1.2, SAN postgres, CA interna de 10 anos e servidor
  de 365 dias, key UID999/0600, CA assinadora separada do mount. Não houve rotação de
  APP_KEY, master key LLM, credenciais PG ou Agent. Custódia externa da CA está pendente.
- HBA rejeita TCP plaintext e aceita hostssl SCRAM. Unix socket local necessário preservado.
  Web, queue worker, diagnostic-worker e migrator usam verify-full/sslrootcert explícito.
  Healthcheck PG exige conexão TLS autenticada e consulta pg_stat_ssl. Nenhuma porta PG/FPM
  foi publicada. Base Compose e overlay compatível preservam TLS numa recriação.
- Testes reais de conexão válida, hostname errado, CA errada, plaintext rejeitado e socket
  passaram em produção e laboratório. PDO da aplicação confirmou TLS. Job real fila→worker→cache
  e diagnostic-worker passaram após recriação. Volume/dados preservados.
- Primeira aplicação TLS passou na conexão e fila, mas checagem final chamou queue-health,
  ausente na imagem RECOVERY-6. Rollback automático restaurou configuração/ambiente anteriores.
  Corrigido precheck para job funcional compatível, repetido com PKI já criada e PASS.
  Logs privados conservam tentativa e rollback; não se apagou a falha do relato.
- HTTPS/SAN/validade até 2027-02-02, HTTP308, ready200 e login200; nginx -t real PASS.
  Certbot.timer ativo e hook exclusivo existente preservado; não foi forçada renovação ACME.
  IP 45.182.96.18 já estava autorizado: config existente validada e recarregada; usuário confirmou.
  Remoção ampla do gate foi rejeitada pela revisão automática e não executada. Allowlist mantida.
- Timer backup diário 02:15 UTC e collector a cada 30s instalados/ativos. Novo backup local
  passou; etapa externa sai 2 com EXTERNAL BACKUP NOT CONFIGURED. OnFailure registrou sinal
  local com sucesso. Isso é falha/pendência externa visível, não sucesso de réplica.
- Nginx tem status log sanitizado (timestamp/status/duração) sem IP/URI/cookies/secrets.
  Docker logs rotacionados. Logrotate não disponível no host: config específica preparada,
  instalação/rotação efetiva desse novo status log permanecem pendentes; acompanhar tamanho.

## Backups e recuperação

Backup antes de alterações: 20261104T210551Z. Gerações posteriores e seus manifestos
permanecem privadas em backups do checkout e de produção. Scripts finais incluem banco,
app_storage, kit quando presente, configuração real do vhost/timers, envs, LLM keyring,
certificados, PKI interna em secrets e bundle/commit. SHA-256 abrange todas as categorias.
Retenção local padrão7 dias, configurável1–365; limpeza só após geração íntegra.
Lock, diretórios0700 e arquivos0600; plaintext nunca enviado pelo adaptador externo.

Novo adaptador CMS→HTTPS PUT condicional→GET integral verifica tamanho/SHA256 e recusa
HTTP, URL com credenciais/query, redirects, CA não confiável, dados corrompidos e overwrite.
Token vem de arquivo0600, mensagens são fixas e não imprime URL/body/credencial/exceção.
Teste local HTTPS/descriptografia/arquivo completo: 4 testes PASS, com fixture sintética.
Não há destino externo nem certificado destinatário de produção, e nenhuma transferência real
externa ocorreu. Retenção externa30 diários/12 semanais/12 mensais e imutabilidade dependem
do serviço autorizado. O código não alega prova de retenção nem de restore externo.

Restore em database isolado passou: schema/dados,28 FORCE RLS, auditoria/imutabilidade,
views security_invoker, runtime sem ownership ou visibilidade sem contexto, Knowledge/Prompts,
proveniência LLM e AES-GCM com master key recuperável. Fixture usa provider local simulado;
nenhuma chamada a provider/LLM real. Nenhum restore sobre banco de produção. O backup real de produção também foi restaurado
em database separado, com o verificador preservado da RECOVERY-6 e suas27 tabelas RLS.
Clone independente de bundle/fsck/HEAD são verificados no fechamento. Clone Git remoto e
host limpo externo estão bloqueados. RPO24h/RTO4h são metas, não resultados medidos.

## Testes e evidências

| Verificação | Resultado / limite |
|---|---|
| Laravel/PostgreSQL/RLS/contratos/API/Diagnostics/Conversation/Knowledge/Prompts/LLM fallback/segurança | 135 testes,1.100 assertions PASS, migration up/down; execução com runtime sobre PG TLS isolado |
| Laravel primeira execução / repetição TLS anterior | 134 testes,1.095 assertions PASS; regressão de cobertura de backup acrescentada depois |
| Python Agent0.5.3 | 52 testes PASS em container UID65532, cap_drop ALL; nenhuma instalação real |
| Tentativas Python anteriores | namespace do executor e root sem setuid provocaram falhas; repetição correta sem privilégios passou; não eram regressões de runtime |
| Backup externo em laboratório | 4 testes PASS; criptografia, HTTPS/CA, readback/SHA, overwrite, key inválida e permissões |
| Compose lifecycle | startup/stop/restart/recreate web/worker/PG, volume persistente, TLS pós-recriação e falha parcial/recuperação PASS, projeto agent-center-recovery8 |
| Restore | dados/schema/RLS/grants/triggers/proveniência e master key/AES-GCM PASS em database separado |
| Chromium produção | login200, HTTPS, cookie sessão Secure/HttpOnly/Lax, CSRF sem token/origem confiável419, desktop/mobile sem overflow PASS |
| Pint / Ruff / compileall / pip check | PASS; Ruff nos arquivos Python alterados + Agent, config do projeto; regressão final1 teste/5 assertions PASS |
| Composer validate --strict / audit | PASS, sem advisories retornados na execução |
| Nginx host/container / deploy precheck | PASS; config válida, workers funcionais e PDO TLS |
| Secrets | scan do índice + varredura de secrets atuais/chave privada no histórico; sem correspondências; scanner básico, não prova universal |
| Git / bundles | diff --check, fsck, bundle verify e clone local independente no fechamento; nenhum push externo |

Browser inicialmente tentou testar CSRF com fetch same-origin e depois foi corrigido para
request sem metadados de origem confiável, como exige o mecanismo nativo da versão Laravel.
Cookies e screenshots não contêm credenciais. Não se desabilitou a proteção para passar o teste.
Primeira fixture de restore detectou permissão incorreta no keyfile copiado do checkout;
repetição com arquivo privado UID33 passou. Em produção o keyfile existente é legível pelo web.

Evidências sanitizadas: evidence/recovery8/. Logs integrais de tooling são conservados
em arquivo privado dentro de backups, sem publicação. Nenhum secret em screenshots/Git.

## Operations Health e prontidão Agent

VERDE só com fonte presente e critério satisfeito: web/FPM, PG TLS, workers funcionais,
HTTPS, Agent API básica/validação negativa, HTTP amostrado e integridade local recente.
ATENÇÃO: ausência de Agent real, heartbeat/poll sem cobertura real, backup externo ausente,
Git privado/custódia externa/restore independente e cobertura nova do dashboard não promovida.
NO-GO é reservado a falha operacional crítica comprovada; falha/idade de backup configurado
é registrada, não mascarada. Ensaio local nunca vira prova externa ou Agent ONLINE.

O probe inicial usou enrollment-status, rota RECOVERY-7 ausente na produçãoRECOVERY-6 (404).
Corrigido para enroll vazio422, contrato presente nas duas versões. Não se classificou endpoint
novo não implantado como queda do protocolo instalado. Prova completa do contrato0.5.3 é de lab.

Instalador0.5.3, unit systemd, TLS, enrollment/aprovação, atualização/rollback, journal e quatro
capabilities permanecem os artefatos já comprovados da RECOVERY-7, agora revalidados pela suíte
Python. Freeze mantido. Nenhum SSH/device/host real foi autorizado ou simulado como ONLINE.
Unit validada estaticamente; instalação com systemd real ainda depende de host autorizado.

## Pendências obrigatórias

1. Destino externo independente, credencial mínima, certificado público de criptografia e
   custódia separada da chave privada, retenção/imutabilidade e alerta externo autorizado.
2. Transferência verificada e recuperação em servidor limpo a partir dessa cópia externa,
   com dados/chaves/HTTPS/contratos completos e medição de RPO/RTO.
3. URL Git privada e credencial autorizada; push/clone independente e validação de privacidade.
4. Promoção revisada da aplicaçãoRECOVERY-7/8, cobertura nova no dashboard e implantação de
   Agent real somente em host expressamente autorizado, após pré-requisitos de recuperação.
5. Rotação efetiva do novo status log Nginx e escrow externo das chaves existentes.

Parte local implementada/testada e TLS/backup/monitoramento aplicados à produção. O projeto
continua vulnerável à perda total desta VPS enquanto esses itens externos faltarem.
