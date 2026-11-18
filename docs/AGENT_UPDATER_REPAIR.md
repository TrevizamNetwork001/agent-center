# Preparação do atualizador em instalação existente

O painel mostra somente **Atualizar Agent**. Quando há uma operação pendente e
nenhuma comunicação do companion, o link abre instruções sob Auth, sessão,
Policy e tenant da máquina. A visualização não executa comandos nem modifica a
operação. Operador/viewer recebem 403 e acesso por outro tenant recebe 404.

Na máquina do Agent, baixar novamente o instalador e executar:

```sh
curl --fail --show-error --location --proto '=https' --proto-redir '=https' -o instalar-agent.sh https://agente.trevizamnetwork.com.br/instalar-agent.sh
bash instalar-agent.sh
```

O wrapper reconhece a existência de identity.json e seleciona reparo. O modo
explícito `--reparar-atualizador` também existe, mas não aparece como outra ação
no painel. O wrapper verifica hashes fixos do kit e helper. O helper valida
instalação compatível, arquivos confiáveis root-owned, units, origin HTTPS,
freeze, identidade/configuração privadas e runtime 0.5.3 ou 0.5.4 aprovado.
Não há novo enrollment, credencial de tenant enviada pelo operador ou shell
recebido da Central. Instalações divergentes são recusadas para revisão.

O pacote 0.5.4 é reproduzido do zipapp 0.5.3 verificado e só pode ser usado se o
SHA-256 final for exatamente
`bb8c0274b9fc2121c283136b2ad66a9b67037c0c7d32e44f79f44b5d33f5cb19`.
Ele é disponibilizado no diretório root-owned releases e adicionado ao catálogo
local, preservando as entradas anteriores. O runtime não é substituído pelo
reparo. Somente o timer existente agent-center-updater.timer é ativado.

Após a primeira comunicação, o companion processa PRECHECK_REQUESTED existente.
A instalação ainda depende do plano PLANNED e de Confirmar operação no painel.
Sem afirmar instalação ou execução remota nesta sessão. A versão 0.5.4 passa a
integrar a allowlist de presença; freeze/heartbeat/poll e RLS continuam exigidos.

## Backup e rollback no host Agent

Antes de alterar catálogo/timer, o reparo cria snapshot root-only em
`/var/lib/agent-center-updater/repairs/<uuid>/`, com catálogo anterior e
rollback.json, ambos 0600. Configuração, identidade e runtime são preservados;
seus valores não são impressos. Falha de ativação restaura o catálogo e tenta
restaurar o estado anterior do timer. O pacote verificado é mantido para evitar
apagar um arquivo que um precheck concorrente possa estar lendo.

Para reversão manual, parar o timer somente se rollback.json indicar que estava
inativo; desabilitá-lo somente se antes estava disabled. Restaurar o catálogo
anterior com proprietário root e permissões do catálogo de releases. Não apagar
identidade ou state, nem restaurar banco. O runtime só muda por operação aprovada
do updater, que mantém seu mecanismo de backup e rollback separado.

## Publicação e rollback da Central

Imagem `agent-center-central:updater-repair`, derivada de agent-update-animation,
modifica controller/rota/guias, allowlist explícita 0.5.4 e instalador público.
Não há migrations nem alteração da operação pendente. Recriar somente web;
limpar views/rotas compiladas. Arquivos públicos são servidos pelo mount atual,
sem mudança de Nginx/firewall ou de serviços de outros projetos.

Snapshot privado `backups/agent-updater-repair-publication/` guarda compose,
templates/controller/rota anteriores e instalador/helper anteriores, estes
reconstruídos e conferidos contra os SHA-256 publicados originais. Para rollback,
restaurar os arquivos públicos instalar-agent.sh/instalar-agent-auto.py e
instalar/index.html, o registro docs/INSTALLER_DOWNLOAD_HASHES.json e voltar
somente a imagem web para agent-center-central:agent-update-animation; recriar web
e limpar view/route cache por scripts/compose.sh. Preservar qualquer alteração
posterior e todos os dados/identidades/operações.

## Validação

Sete testes Python PASS: segredo apenas no terminal privado preexistente,
reparo sem enrollment/credenciais novas, SHA-256 do candidato, preservação de
identidade/configuração/runtime, repetição sem duplicar catálogo, rollback após
falha e recusa de symlink/updater adulterado. Sem systemd ou rede reais no teste.
Laravel/PostgreSQL isolado: 17 testes / 216 assertions PASS, incluindo guia sob
Policy/tenant, acesso cruzado 404 e presença 0.5.4 sem relaxar freeze. Pint,
validação sintática Bash e diff check PASS.
