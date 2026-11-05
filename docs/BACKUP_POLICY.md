# Política de backup

**EXTERNAL BACKUP NOT CONFIGURED.** Nenhuma URL, conta, token ou custódia de chave
externa foi fornecida. Ensaios HTTPS locais não são réplica independente. A perda da
VPS continua sendo risco crítico. RPO/RTO são objetivos, não garantias alcançadas.

| Classe | Frequência / retenção | Executor |
|---|---|---|
| PostgreSQL, storage, configuração e secrets locais | diário e antes de deploy; 7 dias padrão | backup.sh |
| Cópia externa criptografada | após cada geração; 30 diários, 12 semanais, 12 mensais | backup-external.py + retenção no destino |
| Integridade local | em cada geração, SHA-256 + pg_restore --list + bundle verify | backup.sh |
| Integridade externa | upload condicional + GET integral e SHA-256/tamanho | backup-external.py |
| Restore de dados/chaves | mensal em ambiente isolado | operador autorizado |
| Recuperação de host limpo | trimestral, a partir da cópia externa | operador autorizado |

RPO alvo inicial 24 horas, RTO alvo inicial 4 horas. Configurar conforme criticidade e
medir após ensaio fora da VPS; nenhuma medição local comprova esses objetivos.
Retenção local configurável via BACKUP_LOCAL_DAYS (1–365). Alerta de envelhecimento
do collector: 26h; ajustar junto com a frequência/RPO. Alertar disco em 80% e capacidade
antes de ampliar retenção. Não reduzir a única geração válida para liberar espaço.
O cliente externo não executa DELETE, prune ou limpeza remota.

## Cobertura e consistência

Dump custom-format captura schema, policies, índices, triggers e dados de um snapshot PG.
Storage persistente inclui artefatos/uploads/framework do volume app_storage, sem copiar
PGDATA ao vivo. Tar de storage não é snapshot transacional com o dump: para uploads
que dependam de linhas do banco, colocar aplicação em manutenção/parar escritores durante
a janela e registrar início/fim. Cron normal não promete atomicidade entre banco e filesystem.

Config inclui deploy/scripts/docs, inventário, templates e commit, além de imagem/digest
realmente implantados e inventário de migrations/RLS; fonte do checkout e runtime
implantado podem estar em versões diferentes e nunca devem ser confundidos. Bundle --all preserva
histórico; ambiente real e arquivos de secrets são privados, 0600/0700. APP_KEY, LLM keyring,
credenciais PG, certificados HTTPS e CA PG interna precisam ser recuperáveis. A CA assinadora
é confidencial, mas não é a chave privada destinatária que descriptografa o backup externo.
Agents reais exigirão backup separado de identidade/state/journal/runtime após parada ou
snapshot consistente; nenhum host real está autorizado nesta entrega. Artefatos offline do
Agent são reproduzíveis pelo Git; agent/dist, quando presente, também é arquivado na
categoria storage, sob a mesma criptografia e retenção. Configurações reais do vhost,
allowlist, monitor e timers específicos do projeto são capturadas separadamente.

Lock impede dois dumps concorrentes. Arquivos parciais não têm manifesto final; export
aceita apenas a geração mais recente, completa, íntegra e com menos de 24h. Limpeza local
só acontece depois da validação e remove extensões controladas nas quatro categorias.
Não apagar backups antigos manualmente por glob livre. O status local contém escopo do
projeto; prova de laboratório não é VERDE de produção. Uma falha da geração atual aparece
NO-GO, não reutiliza silenciosamente o timestamp de sucesso anterior.

## Habilitar destino externo autorizado

1. Prover serviço HTTPS que suporte PUT condicional If-None-Match:* e GET, com prefixo
exclusivo e token de mínimo privilégio (criar/ler; sem DELETE/lista global/admin).
Este adaptador usa Bearer HTTPS/WebDAV; não simula assinatura S3. Para S3 escolher um
cliente compatível com assinatura e repetir os mesmos ensaios; não inserir chaves S3 como Bearer.
2. Configurar retenção server-side: 30 diários, 12 semanais e 12 mensais, versionamento e
Object Lock/imutabilidade quando disponível, e administração em conta independente.
A credencial da VPS não pode remover a retenção. Documentar uma prova desse controle.
3. Gerar certificado/chave destinatária em máquina de custódia autorizada fora da VPS.
Manter somente certificado público aqui. Guardar chave privada/passphrase em cofre separado,
com duas pessoas autorizadas e procedimento de recuperação testado. Nunca incluir chave
privada destinatária nos secrets desta VPS nem no mesmo storage de backup sem proteção.
4. Copiar deploy/external-backup.example.json para secrets/external-backup.json, trocar
placeholders apenas por valores autorizados e chmod 600. Token em arquivo separado 0600.
Não usar URL com usuário, query/token ou HTTP; não desabilitar verificação TLS. CA privada
do storage, se necessária, em tls_ca_file explícito, após validar fingerprint fora de banda.
5. Executar backup-run.sh. CMS AES-256 com certificado público é aplicado antes de PUT;
SHA-256 interno e comparação integral externa detectam alteração/corrupção. CMS EnvelopedData
não é assinatura digital: preservar digest recebido em canal independente confiável. Redirects
e fallback HTTP são recusados. O cliente não inicializa buckets nem concede permissões.
6. Verificar objeto através de segunda máquina/credencial, recuperar e descriptografar,
validar manifesto e restaurar conforme DISASTER_RECOVERY.md. Só então comprovar DR.
O status de upload deixa retention_verified e restore_from_external_verified falsos;
não converter um PUT/GET bem-sucedido em PASS global.

## Automação e alertas

agent-center-backup.timer prepara diário 02:15 UTC com jitter de 5min e Persistent.
backup-run.sh mantém log local privado e emite códigos fixos; ausência de destino sai 2,
falha sai 1. OnFailure registra BACKUP_FAILED_OR_EXTERNAL_NOT_CONFIGURED em daemon.err.
Collector observa idade/status local e externo. Instalação/estado real estão em RECOVERY_8_VALIDATION.
Um alerta só no journal não protege contra perda do host: encaminhamento a recebedor externo
é pendência, sem enviar mensagens a terceiros não autorizados. Verificar timers diariamente,
alertas e restore mensalmente. Não interpretar ausência de amostras como sucesso.

## Continuação RECOVERY-8 — GitHub e Cloudflare R2

Esta seção prevalece sobre a preparação HTTPS/WebDAV anterior. O operador criou o
bucket privado **agent-center**, localização automática, classe Standard. Ainda faltam
Account ID, credenciais e destinatário público no ambiente seguro; criar o bucket não
comprova upload nem disaster recovery. `EXTERNAL BACKUP NOT CONFIGURED` permanece até
configuração e readback reais.

### Transporte e criptografia atuais

`backup-run.sh` mantém a geração local já validada e seleciona `backup_r2.py` quando
secrets/r2-backup.json existir. Uma configuração R2 inválida falha; não é substituída
silenciosamente pelo adaptador antigo. `configure-r2-backup.py` exige TTY real, recebe
campos sem eco, grava0600 em secrets0700 e recusa sobrescrita. Não colocar chaves em
chat, argv, shell history, Git, prints ou logs. Nenhum token de administração é necessário
no job diário. O helper não recebe nem gera chave privada age.

O R2 usa endpoint HTTPS da conta validada, bucket exato, regiãoauto, SDK boto3 e SigV4;
credenciais explícitas de arquivo, sem reutilizar IAM/credenciais de outros projetos.
Lock de dependências com versões/hashes, age1.2.1 obtido do pacote oficial Debian e
SHA-256 verificado. Ferramentas ficam em tools/ fora do Git/imagens da Central; podem ser
reinstaladas por install-backup-tools.py. Esse bootstrap pressupõe Debianamd64, Docker
e a imagem de tooling Python existente/reconstruída pelo Dockerfile versionado.

Formato age v1, criptografia autenticada com destinatário público X25519, aplicado ao
tar contendo dump, storage, secrets e manifestos **antes** de qualquer PUT. Somente
recipients públicos são aceitos; identidades/passphrases/plugins SSH não são aceitos.
A VPS não precisa da privada de descriptografia. CMS/WebDAV anterior não é usado como
substituto de assinatura S3 nem como formato da rotina R2.

Cada objeto recebe geraçãoUTC+UUID, prefixo exclusivo e If-None-Match:*. Não há código
DELETE, alteração de bucket, lifecycle ou lock no uploader. HEAD verifica tamanho e
metadata SHA-256 do ciphertext; GET integral recalcula o hash e os bytes. ETag não é
tratado como SHA-256. R2 tem diferenças em checksums S3; o readback integral evita
inferir integridade por um campo não suportado. Arquivo incompleto/corrompido falha.
PUT único limitado a5GiB; exceder falha fechado e exige implementação/ensaio multipart
antes de ampliar, sem uploads parciais abandonados. Recibos privados em backups/r2-receipts
registram objetos e etapas; não há dados/config/manifestos sensíveis em objetos plaintext.

### Credenciais e retenção

Token R2 **Object Read & Write**, restrito ao bucket agent-center. Essa permissão nativa
pode incluir exclusão de objetos; não afirmar que é uma credencial write-only. O código
não exclui e Bucket Lock administrado separadamente é o controle para retenção. Não
conceder Admin Read & Write ao job. Conta administrativa da política fica fora da VPS;
recuperação deve usar credencial independente, preferencialmente Object Read apenas.

Plano em deploy/r2-retention-plan.json é **plano de revisão**, não payload API aplicado:

| Prefixo sob o prefixo exclusivo autorizado | Criação | Lock | Expiração planejada |
|---|---|---|---|
| daily/ | cada backup |30 dias |31 dias |
| weekly/ | domingos |84 dias |85 dias |
| monthly/ | primeiro dia do mês |366 dias |367 dias |

São janelas de tempo, não limite exato de objetos. Snapshots manuais adicionais aumentam
quantidade/custo. Estimar pelo tamanho real do ciphertext: aproximadamente30 diários,
12 semanais e12–13 mensais, mais margem e snapshots pré-deploy. Confirmar orçamento e
ocupação da conta antes de ativar políticas; não inventar preço ou capacidade disponível.
A expiração fica após o lock; regra global/mais longa existente pode prevalecer. Rever
regras atuais e preservar outros prefixos/buckets. Não aplicar lock indefinido ou regra
sem prefixo. Nenhum objeto/regra existente é apagado nesta tarefa. Locks/lifecycle ainda
não foram aplicados nem comprovados sem acesso/autorização administrativa ao bucket.

### Alertas e provas separadas

OnFailure continua nativo systemd. backup-alert.py distingue execução local, upload,
integridade, retenção, custódia, restore independente, falha e atraso. Watch horário
confere idade26h. Sem receiver autorizado, sinal fica somente no journal e isso é
registrado como ALERT_RECEIVER_NOT_CONFIGURED. Opção HTTPS com token protegido está
preparada em deploy/backup-alert.example.json, sem destinatário inventado nem mensagens
para terceiros. Não há integração Telegram/WhatsApp.

Sucesso de PUT/HEAD/GET torna a transferência íntegra; não torna retenção/custódia/restore
VERDE. Esses campos permanecem falsos até provas próprias. Ausência de configuração é
ATENÇÃO; falha real de transferência ou backup configurado atrasado é NO-GO. RPO24h e
RTO4h continuam objetivos não medidos em recuperação independente.

Referências: [R2 S3](https://developers.cloudflare.com/r2/api/s3/api/),
[tokens e escopo](https://developers.cloudflare.com/r2/api/tokens/),
[Bucket Lock](https://developers.cloudflare.com/r2/buckets/bucket-locks/),
[age](https://age-encryption.org/).

### Painel administrativo R2

A pedido do operador, credenciais também podem ser cadastradas em `/admin/backups`,
via HTTPS e Laravel Auth/Session/CSRF/Gate. Acesso exclusivo a platform_admin ativo;
tenant_admin de qualquer tenant, operator e viewer não podem ler, gravar ou solicitar.
Este é um controle de infraestrutura global da instalação (backup de todos os tenants),
sem criar recurso de domínio tenant nem conceder a um tenant exportação de outro.
Cache/lock de solicitação usam namespace platform:backups; auditoria global mantém RLS.

Account ID/Access Key ID/Secret Access Key ficam em arquivo autenticado e criptografado
pelo Crypt nativo Laravel no volume persistente, fora de public/Git/imagem. API keys
podem ser salvas antes da chave pública; campos em branco preservam valores anteriores.
Nenhum valor cadastrado volta preenchido, em HTML, old_input ou auditoria. A privada age
não tem campo de cadastro e é recusada. offsite_ack é declaração do operador, não prova
automática de custódia/DR. APP_KEY existente permanece necessária para recuperar esse
cadastro; não foi rotacionada. Backup de app_storage inclui o ciphertext.

O botão de backup só libera solicitação estruturada após configuração completa. Gate,
CSRF e rate limiting protegem o pedido; o processo web não executa shell e não recebe
Docker socket ou privilégios de host. Timer administrativo consulta um booleano nativo
Laravel Cache e mapeia exclusivamente para agent-center-backup.service; preserva pedido
se já houver backup em andamento. Nenhum comando/argumento livre vem da UI.

No job, comando Artisan executado por operador0:33 lê o ciphertext e exporta somente
para /run/agent-center-r2-export, root0700/files0600; web33 não lê esse plaintext. O host
copia para diretório temporário0700, usa o SDK/age já aprovado e remove temporários.
Export nunca imprime credenciais. Não há shell originado dos campos nem conexão R2 no
request que salva a configuração. UI é a fonte autoritativa quando existe; configuração
incompleta falha explícita, sem voltar silenciosamente a credenciais CLI antigas.
Transferência verificada continua distinta de retenção, custódia e restore independente.


Atualização do painel R2 (2026-11-05): a chave privada pode ser gerada e baixada
no próprio navegador usando a biblioteca age padronizada; ela não é enviada à
Central. Salvar mostra confirmação persistente com horário de São Paulo. O status
do backup atualiza automaticamente sem substituir os campos em edição. Configuração
salva e solicitação aceita não comprovam upload nem recuperação. A conexão ao bucket
autenticou, mas o envio real encontrou falha TLS; não desativar validação de certificados.


### Transporte HTTPS de produção para R2

Novos pacotes são arquivos únicos tar.bz2.age, criptografados antes do envio.
Objetos legados tar.gz.age continuam suportados para download. PUT usa
/usr/bin/curl com IPv4, negociação HTTP/2 (fallback HTTPS1.1 permitido), sem Expect,
TLS/CA/hostname verificados e tentativas limitadas para interrupções de transporte.
Nenhuma opção insecure ou redirecionamento é habilitada. A URL assinada pelo SDK,
válida por20min e restrita ao objeto/PUT/cabeçalhos esperados, fica apenas em arquivo
0600 dentro de diretório0700 temporário; nunca em argv, Git ou logs. O curl ignora
configurações pessoais (-q/--disable), usa somente essa configuração privada e não
recebe a Secret Access Key. Respostas e arquivos temporários são removidos ao terminar.

If-None-Match:* impede sobrescrever objetos. Mesmo HTTP200 ou um retorno perdido não
comprovam sucesso: HEAD deve conferir tamanho/metadata SHA-256 e GET completo deve
conferir bytes e SHA-256 contra o ciphertext local. O recibo guarda apenas resultado
sanitizado do transporte. O caminho SDK de PUT permanece opção técnica de laboratório;
a UI fixa produção no padrão curl. O cliente de download continua SDK e aceita .tar.bz2.age e o legado .tar.gz.age. Blocos/índices experimentais não foram promovidos à produção.
Limites de transporte: conexão10s, tentativa300s, retry600s, processo950s e objeto5GiB.


A rotina automática foi comprovada em 2026-11-04T23:47:13.644987+00:00, geração
20261104T234246Z: local/age/PUT/HEAD/GET/SHA256 PASS, exit0; timer diário ativo.
Retenção externa/lock e receiver externo de alertas não foram configurados com as
credenciais S3 de envio. Alertas locais e watcher permanecem ativos. Não confundir
integridade de transferência com restauração ou custódia da chave verificadas.


Por solicitação do operador, o novo arquivo completo usa nome datado UTC:
agent-center-AAAA-MM-DD_HH-MM-SSZ-<nonce>.tar.bz2.age. O nonce evita colisões e
sobrescritas em execuções concorrentes. Banco, configurações, secrets, dados persistentes,
Git bundle e manifestos entram no mesmo TAR compactado por BZip2 e cifrado por age.
A compactação usa tarfile/bz2 da biblioteca padrão Python, em stream direto para age,
sem pacote global bzip2 e sem arquivo agregado em claro no disco. A identidade privada
continua fora da VPS. A política diária/semanal/mensal mantém gerações anteriores.


### Fechamento — retenção no bucket autorizado

Destino comprovado: bucket agent-center, prefixo exclusivo agent-center/. A credencial
S3 diária retornou403 AccessDenied ao GET da configuração de lifecycle; não foi tentado
PUT/DELETE de política. Não há token administrativo de bucket fornecido. A leitura de
objetos do próprio prefixo funciona. Portanto a retenção e o Bucket Lock permanecem
NÃO VERIFICADOS; nenhuma política remota foi substituída ou objeto removido.

Plano concreto para o proprietário em Cloudflare → R2 → agent-center → Settings:

| Prefixo | Bucket lock por idade | Expiração lifecycle |
| --- | --- | --- |
| agent-center/daily/ | 30 dias | 31 dias |
| agent-center/weekly/ | 84 dias | 85 dias |
| agent-center/monthly/ | 366 dias | 367 dias |

Adicionar as regras por prefixo, preservando todas as regras existentes e a limpeza
padrão de multipart incompleto. Não usar prefixo vazio nem retenção indefinida. Os
fragmentos em deploy/r2-bucket-lock.proposed.json e r2-lifecycle.proposed.json NÃO são
payloads substitutivos: revisar/mesclar a configuração atual primeiro. A aplicação
dessas regras requer acesso administrativo fora da credencial usada pelo backup diário.
Não realizar remoções nesta tarefa. Antes de ativar expiração, revisar objetos elegíveis;
a proteção por lock deve ser lida de volta e confirmada primeiro. Validar por leitura da
configuração; não testar DELETE em objetos existentes. Ainda falta leitura autenticada
das duas políticas e confirmação do escopo do token antes de marcar retention_verified.

Referências oficiais: https://developers.cloudflare.com/r2/buckets/bucket-locks/ e
https://developers.cloudflare.com/r2/buckets/object-lifecycles/ . Locks prevalecem sobre
lifecycle; as expirações propostas excedem a duração do lock. Política de retenção não
foi aplicada implicitamente pelo cadastro das credenciais no painel Laravel.


Backup posterior à conciliação publicado e verificado em2026-11-05T00:35:14.844803+00:00:
geração20261105T003005Z, schema11/28, 35549938bytes,
SHA2560521d75340e6fc1e8259db4196a118422cebae911ac99ef81931d9576f4432af. A geração inclui unitsUI reais e os artefatos aprovados
estacionados em artifacts/agentkit, além de banco, storage, configurações e secrets.
Retenção/BucketLock e alerta externo continuam sem prova; monitor local não substitui
entrega externa. O teste independente foi explicitamente adiado pelo operador.


## Fechamento definitivo — proposta administrativa não aplicada

Somente bucket agent-center. Sem acesso administrativo Cloudflare comprovado nesta sessão; configuração atual de lock/lifecycle não foi lida. Credencial S3 de upload não comprova permissão administrativa.
No painel Cloudflare: R2 Object Storage → agent-center → Settings → Bucket Lock rules e Object lifecycle rules. Antes de salvar, exportar regras existentes e confirmar prefixos reais, orçamento e autorização específica. Não substituir regras existentes.
Proposta: prefixos exclusivos daily/ (lock 30 dias, expiração 31), weekly/ (84/85), monthly/ (366/367). Seleção semanal domingo e mensal dia 1 precisa ser implementada/autorizada no produtor: lifecycle não cria cópias nem classifica objetos automaticamente. Não aplicar esses prefixos ficticiamente aos objetos atuais. Não mover/sobrescrever/excluir backups existentes.
Com tamanho observado de 35,55 MB, 31+13+13 gerações representam cerca de 2,03 GB sem crescimento; confirmar volume real, duplicação e orçamento antes de aplicar. Custos não verificados. Readback administrativo e tentativa controlada de exclusão prematura de fixture autorizada ainda necessários.
Referências: https://developers.cloudflare.com/r2/buckets/bucket-locks/ e https://developers.cloudflare.com/r2/buckets/object-lifecycles/ . Lock protege contra exclusão prematura e prevalece sobre lifecycle; alteração administrativa continua exigindo autorização específica.

Alerta simples existente: scripts/backup-alert.py e watcher, receiver HTTPS opcional via secrets/backup-alert.json fora do Git. Não há receiver configurado. Preparar destino administrativo e token autorizado sem inventar valores; usar payload sanitizado. Exigir cobertura e recebimento externo de falha de backup, upload, atraso, integridade e restore de verificação. O script atual sinaliza integridade/restore como estados, mas não comprova evento de tentativa de restore falhada; essa cobertura permanece não verificada. Não declarar PASS com journal local ou fixture.
Rollback desta continuação: reverter somente o commit documental; nenhum ajuste operacional foi aplicado.
