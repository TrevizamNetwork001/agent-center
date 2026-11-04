# RECOVERY-8 — validação final

**RECOVERY-8 BLOCKED: R2 ainda sem upload criptografado real/readback, custódia externa
da chave e restauração em host independente autorizado não comprovados.** GitHub privado
já recebeu código/histórico e passou clone independente. Retenção R2 e receiver externo
de alerta ainda dependem de configuração/autorização. Nenhum Agent foi modificado ou
implantado nesta continuação. Não iniciar RECOVERY-9.

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
  Docker logs rotacionados. Logrotate não disponível no host: rotação específica usa o reopen nativo do Nginx e
  timer diário, sete gerações com compressão adiada. Nenhuma rotação de outro vhost foi alterada.

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
5. Escrow externo das chaves existentes.

Parte local implementada/testada e TLS/backup/monitoramento aplicados à produção. O projeto
continua vulnerável à perda total desta VPS enquanto esses itens externos faltarem.


## Fechamento operacional

Commit principal do checkout: fe95823. Hardening aplicado em produção: dbf6921;
commits adicionais selam rotação/evidências. Git log distingue as duas linhas de origem.
Bundle verificado/clonado em diretório independente, fsck --full e HEAD idêntico passaram
nesta VPS. Histórico foi examinado também contra os secrets atuais sem emitir seus valores.
O laboratório foi encerrado: somente containers/redes/volumes agent-center-recovery8
removidos após os dumps e provas; fixtures/logs privados preservados, kit0.5.3 conservado.
Self-check do artefato0.5.3 como UID65532 confirmou exatamente as quatro capabilities e freeze.
Master key foi recuperada do backup privado real e a prova AES-GCM repetida com sucesso.
Rotação específica do status log foi instalada/testada com Nginx reopen; HTTPS permaneceu200.
Timers de backup/monitor/rotação ativos; alerta de backup externo ausente continua visível.
A aplicação/volumes/dados de produção e todos os outros projetos foram preservados.

**Resultado permanece RECOVERY-8 BLOCKED. EXTERNAL BACKUP NOT CONFIGURED.**


## Continuação — GitHub privado e Cloudflare R2

O operador criou/confirmou privado `https://github.com/TrevizamNetwork001/agent-center`
e autorizou deploy key exclusiva com escrita. O antigo origin local foi preservado como
production-local. GitHub origin usa SSH443 com host key pinada. Primeiro push de main82dcd2c
e production-recovery8/6bbb4ba, ls-remote, clone limpo e fsck passaram; histórico main
comparado integralmente. Conta conectada é TrevizamNetwork001, mas o conector não tem
repos liberados e respondeu404; por isso não se afirmou ter lido private=true na API.
Privacidade confirmada pelo operador e corroborada por acesso SSH válido + API anônima404.
Nada foi reescrito, não houve first-commit novo, force, mirror ou upload de banco/backups.

Nova varredura percorreu16 commits/701 blobs e todas as árvores:0 secrets/assinaturas de
backup e0 caminhos históricos proibidos antes do primeiro push. Evidências e novos
commits são novamente verificados antes do envio final. A privada SSH fica em secrets
fora do Git, não é chave age de recuperação e não concede administração da conta.

Bucket **agent-center** criado pelo operador, privado, localização automática e Standard.
Account ID, Access Key ID/Secret Access Key e public recipient age ainda não chegaram ao
ambiente seguro. O helper já instalado em /opt recebe isso em TTY sem eco/argv/history,
com arquivos0600 e sem sobrescrever configuração. Nenhum valor foi pedido no chat.
Sem essa configuração não houve qualquer chamada real de escrita/listagem/exclusão R2.

Implementado adaptador S3 boto3/SigV4/auto com dependências pinadas/hashes, TLS validado,
allowlist do endpoint da conta/bucket/prefixo, age autenticado antes do PUT, nomes únicos,
If-None-Match, HEAD+tamanho+SHAmetadata e GET integral. Download para recuperação só busca
R2, sem fallback local; output existente/corrompido é recusado. Nenhuma privada age de
produção foi gerada/importada na VPS. Testes usam apenas dados/chaves sintéticos temporários.
Recibos locais não são prova de custódia/retention/restore. upload_completed/integrity
são separados dessas etapas, que permanecem falsos sem provas próprias.

Plano de lock/lifecycle por prefixo em deploy/r2-retention-plan.json; nenhum foi aplicado.
Diários30/31 dias lock/expiração, semanais84/85 e mensais366/367, sem retenção indefinida.
Credencial diária ObjectRW só no bucket, sem admin; essa permissão pode incluir delete,
por isso o controle de retenção deve ficar em Bucket Lock administrado fora da VPS.
Nenhum objeto/política existente é removido. Custos/capacidade precisam ser confirmados
pelo tamanho real e orçamento, sem preços inventados.

Tooling R2/age, helper, alertas por etapas e watch horário instalados sem trocar imagem,
PHP/Central, schema, credenciais existentes ou Agent. O watcher sinaliza journal; receiver
HTTPS opcional ainda não fornecido. Falha e atraso são NO-GO; ausência de configuração
é explícita. A geração manual pré-deploy usa BACKUP_PRUNE_ENABLED=false para preservar
todas as gerações existentes. Rotina local mantém retenção configurável em operação normal.

Testes repetidos: Laravel135/1.100 assertions e Python52 PASS; testes de tooling (incluindo
os4 anteriores) agora16 PASS. SDK testado com stubber, age real contra fixtures sintéticas,
tamper/corrupção/metadata/permissões e alertas de falha/atraso. Isso **não é upload R2 real,
criptografia de produção verificada, custódia externa nem restore independente**.
Ruff/compileall/shell e verificações finais registradas no fechamento. Agent sem diff.

Conciliação inventariada com base comumff168b9, produção6bbb4ba/recovery6/9migrations/27RLS,
candidato82dcd2c/11migrations/28RLS. Duas migrations e mudanças Central identificadas em
evidence/recovery8/closure/production-reconciliation.json. Snapshot/rollback são preparados;
promoção da aplicação continua bloqueada até proteção externa e ensaio independente.
Somente infraestrutura de backup segura foi aplicada. Detalhes em DEPLOYMENT.md.

Pendências atuais: provisionar R2 e public recipient em terminal seguro; comprovar custódia
externa; aplicar/verificar retenção específica sem afetar outros objetos; upload+HEAD+GET
real; host independente autorizado com privada recuperada fora da VPS; restore de todos
os domínios/dados e alerta recebido fora do host. Sem isso, manter RECOVERY-8 BLOCKED.

## Solicitações durante a continuação — painel R2 e horário

Operador pediu painel na aplicação para digitar dados R2 e corrigiu horário em /audit
para America/Sao_Paulo. Implementados com Laravel nativo, sem alterar AGENTS.md/Agent,
sem PHP/Python Central paralelo e sem migrations novas. Configuração de infraestrutura
é global e somente platform_admin ativo acessa; testes com tenant_adminA/B, operator,
viewer e anônimo negam leitura/escrita/solicitação. Secrets não retornam em HTML, campos,
flash de validação, console ou auditoria. Crypt autenticado nativo protege armazenamento.

É possível cadastrar API keys agora e acrescentar pub age depois; o botão de backup
exige configuração completa/declaração de custódia. Declaração não vira DR verificado.
Host executa só serviço fixo por solicitação Cache bool, sem Docker socket/root/shell
no web. Export root0:33 em diretório0700/files0600; tentativa de leitura como web33 foi
negada. Cópia/limpeza privada foi testada com credenciais sintéticas e SDKrun substituído
por stub, sem qualquer chamada Cloudflare. UI CLI antiga só é fallback se não existe
cadastro UI, nunca se o cadastro está incompleto ou falha.

Testes:141 Laravel/1.154 assertions PASS (6 novos testes/54 assertions); Chromium candidato
base produção passou cadastro com ciphertext, campos vazios pós-save, ausência de secrets,
CSRF419, desktop/mobile sem overflow e filtro/horário SãoPaulo. Fixtures são sintéticas.
Erro inicial do teste que alternava usuários sem limpar a sessão foi corrigido no harness,
sem relaxar gates; fluxo real de roles permaneceu403. Fixture CLI foi corrigida para
consulta administrativa explícita sob contexto de plataforma, sem desligar RLS.

Imagem de produção agent-center-central:recovery8-r2-ui é derivada de recovery6+patchUI,
commit candidato3c07ea1. Snapshot/rollback preparados, deploy pontual PASS,9 migrations e27RLS
preservadas; Agent Operations e suas duas migrations continuam sem promoção. PostgreSQL,
TLS/SCRAM/verify-full, fila funcional, Nginx/HTTPS e readiness revalidados. Nenhuma conta/senha
foi criada ou resetada em produção. Nenhum arquivo/fonte/runtime de Agent alterado.

Painel publicado em https://agente.trevizamnetwork.com.br/admin/backups; auditoria agora
exibe horário de SãoPaulo e aplica filtros no mesmo fuso. Instantes originais/relógio/NTP
preservados. Evidências sanitizadas em evidence/recovery8/backup-ui/.
R2 real, custódia externa, retention e restore independente continuam pendentes até
configuração/provas reais do operador. Não considerar UI publicada como RECOVERY-8 PASS.


### Confirmação de salvamento e andamento do R2 — 2026-11-05

A configuração de produção foi cadastrada pelo painel. Verificação somente por
summary (booleanos): account/credentials/recipient/custody_attested/ready=true.
Nenhum valor foi exibido. HEAD do bucket autorizado autenticou com TLS validado.
O primeiro upload real falhou com SSLError. Autenticação aceita não comprova upload:
backup externo continua sem prova de integridade. Verificação TLS permanece obrigatória.

Imagem `agent-center-central:recovery8-r2-ui-save-status`, candidato `0839cb5`,
publicada somente em web/worker/diagnostic-worker, sem migrations ou recriação do PG.
Rollback: Compose anterior em backups/ui-save-rollback-*/compose.yaml e imagem
recovery8-r2-ui-before-save-status. Aplicar o Compose anterior e recriar somente
os três workloads; preservar storage, ciphertext de configuração e banco.

O painel mostra confirmação persistente com saved_at em America/Sao_Paulo;
campos vazios preservam valores. Fragmento autenticado atualiza somente o status a
cada 10s, usando o mecanismo existente. O formulário em edição não é substituído.
Mensagens de estágio vêm de allowlist, sem valores/erros externos arbitrários.
Chromium isolado confirmou persistência após reload, polling e formulário preservado.
Laravel: 5 testes / 70 assertions PASS; Pint dos 3 PHP alterados PASS;
git diff --check PASS. Evidência: backup-ui/save-confirmation.json.

Geração da identidade usa age-encryption0.3.1 empacotado localmente, sem CDN;
a chave privada é baixada pelo navegador e nunca postada. Interoperabilidade com
age1.2.1 e ausência de identidade em DOM/POST verificadas com chave sintética.
O arquivo deve permanecer sob custódia externa do operador; declaração da UI não
substitui prova de recuperação independente. O defeito de diretivas Blade adjacentes
foi corrigido com quebras de linha e imagem com código legível pelo usuário do FPM.

RECOVERY-8 BLOCKED: upload/integridade R2 ainda não comprovados; custódia externa,
restore em host independente, retenção e alertas externos aguardam validação.


### Validação real do R2 solicitada pelo operador — 2026-11-05

Credenciais cadastradas no painel foram usadas sem exibição. Bucket agent-center:
PUT condicional, HEAD, GET integral e SHA-256 PASS para objetos sintéticos criptografados
com age de243bytes e2097848bytes. Isso comprova escrita/leitura da conta autorizada,
mas não é backup real de produção nem recuperação de desastre.

Pacote de produção criptografado de35249103bytes não concluiu o upload: conexão TLS
encerrada com SSLError/EOF. Reproduzido com streaming, remoção somente diagnóstica do
cabeçalho Expect e envio em memória limitado. Multipart diagnóstico falhou na primeira
parte de5242880bytes; somente seu upload multipart incompleto foi abortado, sem
DeleteObject e sem alteração de objetos existentes. Teste isolado de6MiB em TLS1.2
validado também falhou. Nenhuma dessas alternativas foi persistida em produção;
TLS/CA/hostname continuaram verificados em todas as tentativas.

O painel diferencia configuração salva e cópia externa NO-GO; a solicitação do botão
foi consumida. Nenhum backup completo remoto foi comprovado. Evidência sanitizada:
evidence/recovery8/closure/r2-real-transfer-validation.json.

RECOVERY-8 BLOCKED: interrupção TLS em uploads maiores impede verificar a cópia real;
retenção, custódia externa e restore independente continuam sem comprovação. Pequenos
objetos de diagnóstico não substituem banco/secrets/storage reais nem autorizam PASS.


### Correção do transporte R2

A tentativa controlada com curl/IPv4/retry completou PUT200 HTTPS1.1 do pacote real
criptografado de35250967bytes, SHA256
c52ef54b4f3779a9da0b2a2adfff68eb936d30f236d7e204f19fb550507884f1.
HEAD e download integral verificaram tamanho e hash. A causa específica de rede/TLS
não foi atribuída sem prova; o resultado é compatibilidade de transporte comprovada.
O ajuste mantém o formato age e a chave privada fora da VPS. Protótipo de divisão em
blocos foi descartado após os testes reais, sem promoção ou mudança de formato.

Testes do ajuste:22 Python PASS, incluindo URL assinada nunca em argv, arquivos0600,
parent0700, limpeza após timeout, rejeição de host/HTTP/redirect, e exigência de HEAD/GET
mesmo com HTTP200. Ruff e compileall PASS. Teste anterior de escrita parcial não vira
backup aprovado; somente recibos complete=true com hash conferido contam.
