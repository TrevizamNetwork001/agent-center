# Produção — RECOVERY-8

Laravel é a única Central; PostgreSQL é o banco. Host Nginx termina HTTPS e encaminha
somente para 127.0.0.1:18083 → Nginx não privilegiado → PHP-FPM. Workers e PostgreSQL
não têm portas publicadas. Não há FastAPI/upstream legado. DNS público e certificado
HTTPS existentes foram preservados. O acesso do operador 45.182.96.18 permanece na
allowlist; a abertura da web foi confirmada pelo operador. Login continua obrigatório.

## Limites da entrega

A aplicação de produção foi encontrada em RECOVERY-6, com 27 tabelas FORCE RLS.
O checkout RECOVERY-7/8 tem 28. Não promover novas funcionalidades/migrations sem
backup externo verificado. O hardening TLS pode ser aplicado isoladamente, mantendo
imagem, banco e schema de produção. Evidências distinguem produção de laboratório.
AGENTS.md foi lido e preservado; o pedido RECOVERY-8 supera suas restrições antigas
de escopo de fase, mantendo todos os invariantes de segurança.

## Controles

APP_ENV=production, APP_DEBUG=false; APP_KEY existente preservada. LLM master key
separada em arquivo 0600 fora da imagem/Git, montada somente no web que a utiliza.
Serviços PHP executam www-data, cap_drop ALL, no-new-privileges. Nginx usa UID 101,
filesystem read_only e tmpfs. PostgreSQL oficial inicia com root apenas para preparar
volume e reduz para UID 999; não é privileged. Não aplicar cap_drop ALL ao entrypoint
sem testar inicialização e ownership. Logs Docker têm 10 MiB × 3 por serviço.

Auth, Hash, Gates, Policies, Session, CSRF e rate limiting são nativos Laravel.
Sessões no banco, criptografadas; cookie de sessão Secure/HttpOnly/SameSite=Lax.
XSRF-TOKEN deliberadamente legível por JavaScript, Secure/Lax; não é cookie de sessão.
Headers incluem CSP, HSTS, nosniff, DENY e Referrer-Policy. HSTS não foi ampliado
indiscriminadamente para subdomínios. Limite de corpo 2 MiB, validação Laravel por rota,
throttles do login/enrollment/protocolo existentes. Health público expõe só alive/ready,
sem versões, erros SQL ou credenciais. Administradores continuam sujeitos a login e
RBAC; nenhuma senha foi redefinida nesta fase.

## Banco e TLS

Admin bootstrap/backups separado do migrator/owner. Runtime web e diagnostic-worker
compartilham role DML, sem superuser, CREATEDB, CREATEROLE, BYPASSRLS, memberships
administrativos, ownership ou CREATE no schema. Queue worker compartilha runtime;
não recebe senha admin/migrator. Separação de uma role só para fila é melhoria futura,
sem alterar grants de domínio durante recuperação. Grants e triggers imutáveis existentes
continuam; FORCE RLS nunca foi desativado. Testes executam como runtime, não como admin.

Base Compose RECOVERY-8 inclui ssl=on, mínimo TLS1.2, HBA hostnossl reject e hostssl
SCRAM, SAN DNS:postgres, verify-full e CA explícita em todos os clientes, incluindo
migrator e diagnostic-worker. Unix socket local trust foi preservado dentro do container
para bootstrap/backup; não é TCP e depende do acesso administrativo ao container.
Não publicar socket nem porta 5432. Docker socket equivale a acesso administrativo ao
host e nunca é montado em Laravel/Agent.

PKI de produção interna: CA e chave de assinatura em secrets/postgres-ca (0700),
chave servidor em secrets/postgres/server.key (0600/UID999), certificados públicos 0644.
A CA assinadora não é montada no PostgreSQL. Certificado interno servidor: 365 dias;
CA: 3650 dias. Proteger cópia de recuperação da CA; custódia externa ainda pendente.
Não substituir APP_KEY/master key ou CA durante restore sem compatibilidade e ensaio.
`provision-pg-tls.sh --lab` cria certificados de dois dias somente para laboratório.
`install-production-tls.sh --new-internal-ca` é operação fixa, recusa sobrescrita,
faz backup e rollback de configuração; não troca imagem da aplicação nem executa migration.

`verify-pg-tls.sh` exige sucesso válido e falha para hostname/CA errados e plaintext,
preservando socket. Healthcheck do banco negocia verify-full e consulta pg_stat_ssl;
`deploy-precheck.sh` verifica também PDO real e workers. Compose sem certificados falha
fechado. O overlay antigo agora é compatibilidade, não uma opção para desligar TLS.

## HTTPS, renovação e rollback

Certificado público SAN agente.trevizamnetwork.com.br, válido até 2027-02-02 no precheck.
Certbot.timer e deploy hook específico presentes; hook só recarrega após nginx -t.
A renovação real depende do próximo ciclo ACME; não emitir certificado novo sem necessidade.
CA interna não usa ACME: alertar/renovar antes de 30 dias de validade restante, distribuir
CA por sobreposição de confiança e testar clientes antes da troca. Não retirar CA antiga
até encerrar retenção de backups dependentes.

Rollback TLS: configuração anterior e envs em backups/recovery8-before-tls, volume PG
preservado. Parar clientes antes de voltar servidor/HBA e clientes juntos; executar up
com arquivo revisado e confirmar login/ready/worker. Isso reintroduz risco do TLS opcional;
preferir corrigir a PKI. Nunca down -v, restore ou rollback migration em produção.

## Riscos residuais

Sem destino externo, perda desta VPS ainda perde os backups locais. Sem clone remoto
privado ou restauração fora do host não existe DR completo. Monitor de falha em journal
é sinal local; recebedor externo precisa ser fornecido. Não há Agent real autorizado.
A aplicação nova e sua ampliação do Operations Health permanecem preparadas/testadas
em laboratório até promoção revisada com os pré-requisitos de recuperação.

Referências: [libpq verify-full](https://www.postgresql.org/docs/17/libpq-ssl.html),
[TLS servidor](https://www.postgresql.org/docs/17/ssl-tcp.html).

Status log sanitizado tem rotação diária exclusiva, sete gerações, compressão adiada e
nginx -s reopen. Timer agent-center-status-rotate; não altera logs de outros vhosts.
