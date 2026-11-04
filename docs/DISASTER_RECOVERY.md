# Disaster Recovery — RECOVERY-8

**EXTERNAL BACKUP NOT CONFIGURED. DR NÃO CONCLUÍDO.** Existe recuperação local testada;
não existe prova de sobrevivência à perda completa da VPS. Nenhum Agent real foi instalado.
RPO 24h/RTO 4h são objetivos configuráveis ainda sem teste em servidor independente.
Política e configuração do adaptador HTTPS: BACKUP_POLICY.md. Arquitetura/roles/TLS:
PRODUCTION_HARDENING.md e DATABASE_ROLES.md. Git privado: GIT_REMOTE.md.

## Recuperação em host limpo

1. Provisionar host explicitamente autorizado, SO suportado, disco/RAM conforme inventário,
Docker/Compose, Nginx, Certbot, OpenSSL, Python para tooling, NTP sincronizado e firewall
mínimo. Não instalar PHP global. Não alterar outros serviços nem apontar DNS antes dos testes.
2. Recuperar repositório do Git privado ou bundle externo. Verificar digest por registro
confiável separado. `git clone ARQUIVO.bundle agent-center` e `git fsck --full`; conferir
commit gravado no backup. Usar imagem/fonte compatível com migrations restauradas: produção
RECOVERY-6 tem 27 tabelas RLS; checkout RECOVERY-7/8 tem 28. Não aplicar restore-check
RECOVERY-7 sobre dump RECOVERY-6 e chamar a divergência de perda de dados.
3. Obter a geração **do storage externo**, por credencial independente, incluindo CMS e
seu digest registrado. Nunca usar um arquivo só localizado na VPS original como prova
contra perda da VPS. Registrar geração, origem autorizada, hash, tamanho e duração sem secrets.
4. Em ambiente protegido, recuperar certificado e chave privada destinatária do cofre
separado. Descriptografar com `openssl cms -decrypt -binary -inform DER -in GERACAO.cms
-recip CERTIFICADO.crt -inkey CHAVE_PRIVADA -out geracao.tar.gz`. Não colocar passphrase
em argv. Manter temporários 0600 e diretórios 0700, sem screens/logs. Essa chave não deve
ficar permanentemente na VPS recuperada.
5. Inspecionar lista do tar antes de extrair em diretório vazio. Conferir `sha256sum -c
config/GERACAO.sha256` a partir da raiz extraída. Recusar nomes absolutos, traversal,
symlinks inesperados ou manifesto divergente. Preservar o original para investigação.
6. Recuperar configuração e secrets da mesma geração. Restaurar GERACAO.env como .env
0600, LLM master key/keyring e PKI PG nos caminhos documentados. Não gerar APP_KEY nova;
isso perde acesso a payloads criptografados. Derivar envs mínimos via
`python3 scripts/create-migrator-env.py`. Recuperar identidade/journal de Agents somente
no host autorizado, sem clonar uma identidade simultaneamente.
7. Restaurar CA pública, server.crt, server.key UID999/0600 e HBA. A chave assinadora fica
fora do mount do PG. Validar SAN postgres/validade antes de subir. Renovar certificado
vencido com CA recuperada/autorizada, sem desabilitar verify-full. Restaurar secrets LLM
com proprietário UID33/0600 para web. Diretório secrets pai 0700 protege acesso no host.
8. Subir apenas PostgreSQL em **volume novo**. Bootstrap recria admin/migrator/runtime
sem senhas no Git. Não reaproveitar PGDATA de versão incompatível nem fazer down -v em
volume existente. Verificar socket, SCRAM e `scripts/verify-pg-tls.sh` antes do restore.
9. Em database vazio/isolado com owner migrator, restaurar custom dump com
`pg_restore --no-owner --no-privileges --exit-on-error -U ADMIN --role MIGRATOR -d BANCO`.
Credenciais somente por ambiente/arquivo privado; nomes reais vêm da configuração recuperada.
Se o database inicial do bootstrap já contém objetos, criar outro e apontar clientes
para ele após validação. Nunca executar sobre banco de produção existente.
10. Reaplicar grants explícitos com db-grants.sh, respeitando allowlist de databases.
Conferir FORCE RLS/policies, runtime sem ownership/DDL/BYPASSRLS, views security_invoker,
triggers imutáveis e FKs compostas. Comparar contagens e identificadores esperados do
manifesto privado, e testar isolamento tenant/no-context. `restore-check.sh` do commit
correspondente automatiza o ensaio em database separado e o remove ao terminar.
11. Restaurar app_storage da mesma janela, UID/GID33, modos restritos e diretórios
framework necessários. Inspecionar/eliminar apenas caches de código incompatível por
comando Artisan nativo. Não carregar sessões/jobs antigos sem revisar consequências.
12. Revisar leases/generations/fencing e trabalhos desconhecidos antes de ligar writers.
Manter Agents parados até conciliar estado; não reexecutar tarefas incertas automaticamente.
Validar migrations com `migrate:status`; migration adicional só após revisão de compatibilidade,
backup recuperado íntegro e rollback previsto. Não fazer rollback automático de schema/dados.
13. Iniciar Laravel/FPM, queue worker e diagnostic-worker. Executar ready/live,
queue-smoke.sh e deploy-precheck.sh. Confirmar progresso funcional, não só PID/container.
14. Configurar vhost exclusivo, allowlist e HTTPS. Restaurar Certbot/renovação ou reemitir
certificado após autorização do DNS e teste ACME. `nginx -t`, HTTP→HTTPS, SAN/validade,
cookies e sessão/CSRF. Não copiar upstream legado nem expor PostgreSQL/FPM.
15. Em tenant de laboratório autorizado, validar Agent API/enrollment com aprovação,
heartbeat/poll, allowlist/freeze e leases/fencing. Ausência de Agent permanece ATENÇÃO,
nunca ONLINE. Não instalar em host não autorizado nem tocar devices.
16. Validar Diagnostics e Conversation determinísticos, Knowledge/Prompts/versionamento,
LLM com chave recuperada e fallback seguro sem provider externo real, e auditoria
imutável. Suíte Laravel/PostgreSQL e contratos cobrem esses caminhos em isolamento;
registrar também leitura de dados restaurados e descriptografia real do keyring recuperado.
17. Ativar monitor e timer de backup; gerar nova cópia externa criptografada e verificar
com outra máquina. Confirmar retenção/imutabilidade e recepção de alerta externo.
Trocar DNS somente após todos os critérios, preservando host/volume anterior para rollback.
18. Registrar hora do último dado recuperado e duração completa; comparar com RPO/RTO.
Conservar evidência sanitizada com origem externa, commit, hashes, resultados, falhas e
responsável. Sem recuperação a partir da cópia externa não emitir RECOVERY-8 PASS.

## Ensaios desta fase e rollback

Os testes desta VPS usam rede/volumes agent-center-recovery8 e database de restore
separados; não alteram dados reais. Incluem TLS, recriação de containers, persistência,
falha parcial, worker, dump/restore e bundle clone. Não equivalem a host limpo externo.
A aplicação de produção mantém sua versão/schema; hardening TLS tem backup de config
e rollback em backups/recovery8-before-tls. Serviços de outros projetos permanecem intactos.

Secrets sem escrow externo, Git privado ausente, destino de backup ausente e receptor de
alertas ausente continuam dependências concretas do operador. Não rotacionar APP_KEY,
master key LLM, credenciais de Agent ou enrollment como atalho de recuperação.
