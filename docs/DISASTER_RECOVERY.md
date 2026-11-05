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

No fechamento inicial faltavam escrow, Git privado e destino externo. Na continuação,
GitHub privado já foi validado; escrow, R2 efetivo e receptor de
alertas ausente continuam dependências concretas do operador. Não rotacionar APP_KEY,
master key LLM, credenciais de Agent ou enrollment como atalho de recuperação.

## Continuação — recuperar do GitHub e R2 sem arquivos da VPS original

GitHub privado agora existe e o primeiro push/clone real passou. Banco/secrets/storage
continuam sem prova externa enquanto faltar configuração R2 e custódia da chave. Um
clone novo realizado **nesta VPS** não é teste em host independente.

1. Fora desta VPS, em computador/host confiável autorizado, instalar age padrão. Gerar
   `age-keygen -o agent-center-recovery.agekey`; proteger esse arquivo como segredo e
   guardar uma segunda cópia em cofre independente. `age-keygen -y` desse arquivo emite
   somente o destinatário público a provisionar na VPS. Nunca enviar a privada para cá,
   chat, GitHub ou objeto R2 desprotegido. Somente fixtures sintéticas usam identidades
   temporárias de teste aqui, apagadas ao final dos testes; não são chaves de produção.
2. Provisionar conta GitHub e credencial de leitura/R2 de recuperação fora da VPS.
   Obter clone privado e SDK/age pelos procedimentos versionados. Se a imagem Python
   de tooling não existir, reconstruí-la do Dockerfile existente; não depende de estado
   do Agent nem instala Agent. Alternativa de obtenção do ciphertext é o console R2
   autorizado, sem publicar o objeto ou enviar plaintext a outro serviço.
3. Criar config/credencial de recuperação0600 somente no host independente autorizado.
   Preferir token Object Read restrito ao bucket agent-center. Obter a chave exata do
   objeto pelo console/recibo externo; não depender de recibo localizado apenas na VPS.
4. Executar no host de recuperação:

```sh
python3 scripts/download_r2.py --config CAMINHO_CONFIG_PRIVADA --key CHAVE_DO_OBJETO --output recuperacao.tar.gz.age
```

   O comando faz HEAD+GET do R2, verifica tamanho/hash e grava0600, recusa sobrescrita,
   não usa cópia/cache de backup local e não recebe chave privada. A configuração contém
   o endpoint real e o bucket autorizado. A proof.json local é prova de download, não
   resultado automático de restore independente.
5. Recuperar a identidade age do cofre, **nesse host autorizado**. Descriptografar para
   arquivo parcial; só promover se a autenticação concluir com exit0:

```sh
umask 077
age --decrypt -i CAMINHO_IDENTIDADE_FORA_DA_VPS --output recuperacao.tar.gz.partial recuperacao.tar.gz.age
```

   Após sucesso, renomear manualmente para recuperacao.tar.gz, inspecionar caminhos tar,
   extrair em diretório vazio e conferir config/GERACAO.sha256. Jamais extrair stdout de
   decrypt antes de confirmar exit0; descriptografia em streaming pode falhar ao final.
6. Restaurar código/commit coerentes com runtime/schema gravados. Recuperar .env/APP_KEY,
   master key LLM, PKI, storage e roles; seguir passos1–18 deste documento. Produção atual
   é recovery6/schema27RLS; candidatoRECOVERY-7/8 tem duas migrations adicionais e28RLS.
   Não confundir as linhas nem aplicar rollback de schema em banco real.
7. No PG isolado validar migrations/RLS/grants, dados de autenticação, Knowledge/Prompts,
   descriptografia LLM, Diagnostics/Conversation/auditoria. Registrar hashes/contagens
   sanitizadas e comparações privadas. Nginx/HTTPS/worker/API/monitoramento devem passar
   antes da troca de DNS. Não instalar Agent em host não autorizado.
8. Registrar host autorizado, origem exclusivamenteR2, geração, commit GitHub, duração,
   resultados e provas de custódia/retention/alerta. Só isso pode fechar o critério de DR;
   testes locais sintéticos ou download feito nesta VPS não bastam.

### Perda ou troca da chave

Se todas as cópias privadas age forem perdidas, o destinatário público não recupera os
backups. Não há senha administrativa que contorne a criptografia. Manter responsáveis,
acesso ao cofre e duas cópias independentes testadas. Testar uma segunda identidade
pública de recuperação é possível (até5 destinatários explícitos), mantendo todas as
privadas fora da VPS. Rotação adiciona destinatário novo e exige teste; não retirar a
chave antiga enquanto houver gerações dependentes. Recriptografia de gerações antigas,
se autorizada, ocorre no ambiente independente e cria objetos novos; não sobrescrever
nem excluir backups sob lock. Nenhuma rotação foi executada nesta tarefa.

### Configuração salva pela UI

Recuperar app_storage e o APP_KEY original restaura o ciphertext
storage/app/platform-backups/r2.enc. O painel devolve somente flags de cadastro e os
campos ficam vazios. Credenciais de leitura do R2 para baixar o primeiro backup precisam
estar disponíveis fora da VPS (cofre/conta Cloudflare), pois o cadastro criptografado no
backup não pode ser o único modo de acessá-lo. Export administrativo não devolve secrets
em stdout e os temporários são root-only. A privada age continua exclusivamente fora da
VPS; o painel recebe apenas o destinatário público. Instalação Windows oficial:
`winget install --id FiloSottile.age`; gerar identidade no PC, guardar arquivo em cofre,
usar age-keygen -y para copiar somente a pública. Não adicionar a privada ao GitHub/UI.


Atualização do painel R2 (2026-11-05): a chave privada pode ser gerada e baixada
no próprio navegador usando a biblioteca age padronizada; ela não é enviada à
Central. Salvar mostra confirmação persistente com horário de São Paulo. O status
do backup atualiza automaticamente sem substituir os campos em edição. Configuração
salva e solicitação aceita não comprovam upload nem recuperação. A conexão ao bucket
autenticou, mas o envio real encontrou falha TLS; não desativar validação de certificados.


O ajuste do transporte R2 não muda a restauração: localizar o objeto.tar.gz.age no
recibo, usar scripts/download_r2.py no host de recuperação autorizado para HEAD/GET e
SHA-256, e só então usar a identidade age sob custódia externa para descriptografar.
A identidade privada nunca deve ser enviada à VPS original. O transporte curl é de
PUT; GET e a prova de integridade continuam pelo SDK. Nenhuma restauração independente
real foi realizada nesta validação, portanto integridade remota não equivale a DR PASS.


Existe cópia real criptografada em R2 com HEAD e download integral verificados em
2026-11-04T23:47:13.644987+00:00. O recibo privado da geração20261104T234246Z em
backups/r2-receipts informa a chave do objeto; a prova pública sanitizada omite conta
e credenciais. Ainda não houve recuperação em host independente autorizado nem
descriptografia dos dados reais. Essa etapa permanece bloqueada sem acesso ao host
de recuperação e à identidade sob custódia externa do operador.


### Arquivo completo BZip2 com data

Novas gerações usam agent-center-AAAA-MM-DD_HH-MM-SSZ-<nonce>.tar.bz2.age (UTC).
O único objeto contém o conjunto completo e é baixado pelo mesmo download_r2.py.
No host de recuperação autorizado, com identidade recuperada fora da VPS original:

```bash
age --decrypt --identity /caminho/seguro/recovery.agekey --output recovered.tar.bz2 downloaded.tar.bz2.age
mkdir -m 700 restored
python3 -m tarfile --extract recovered.tar.bz2 restored --filter data
```

Verificar o manifesto SHA256 antes de restaurar o dump e aplicar permissões segundo
os passos anteriores. O formato legado .tar.gz.age continua aceito pelo downloader;
selecionar a extensão de saída correspondente. Descriptografar/extrair dados reais
só no ambiente autorizado de recuperação; a VPS original não recebe a identidade.


Para reproduzir a versão operacional comprovada, obter a branch production-recovery8
do GitHub privado e comparar seu commit com o inventário incluído no backup, antes
de aplicar migrations. A branch main contém mudanças de laboratório cuja conciliação
com produção é uma operação separada. A exportação local em backups/export é uma
cópia cifrada manual, baixada do R2; não comprova restauração em host independente.


### Host independente reservado para etapa futura por decisão do operador

O operador pediu adiar o host independente e destiná-lo futuramente a testes e
implantações. Nenhum IP, provedor, chave de acesso ou orçamento foi inventado.
Nenhum ambiente externo foi provisionado e nenhum dado real foi enviado a terceiros
adicionais. A chave privada age foi declarada guardada fora da VPS; ela não foi
recebida nem utilizada nesta VPS. O teste independente continua NOT EXECUTED.

Quando o host for explicitamente autorizado, usar funções separadas de staging e DR:
primeiro clonar o GitHub privado por credencial própria de leitura, validar commits e
subir testes com dados sintéticos; em uma execução de recuperação autorizada, baixar
novamente o objeto.tar.bz2.age do R2, conferirSHA256 do recibo, usar a identidade do
cofre externo e restaurar banco/configuração em rede privada. Não reutilizar o arquivo
local da VPS original como fonte. Validar TLS/SCRAM/verify-full, migrations, FORCE RLS,
auth, Knowledge/Prompts/LLM, Diagnostics/Conversation/auditoria e backup antes de receber
tráfego. A restauração com dados reais exige autorização de host e de tratamento desses
dados; os testes sintéticos não contam como prova de recuperação da cópia real.

Staging futuro não terá deploy automático em produção nem Agent em host sem autorização.
Uma promoção futura exige código/artefato imutável, relatório de testes, snapshot e
rollback, atualização controlada de schema e validação operacional. RPO/RTO continuam
objetivos configuráveis, sem medição efetiva até esse teste independente.
