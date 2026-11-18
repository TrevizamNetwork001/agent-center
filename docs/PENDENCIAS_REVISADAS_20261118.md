# Revisão e correção das pendências restantes — 18/11/2026

## Escopo autorizado

O usuário adiou expressamente backup externo e certificado do laboratório backup-ui, solicitando verificar, corrigir, testar, documentar e publicar as demais pendências. Autorizou os testes necessários e a limpeza das quatro branches auxiliares após ser informado da reescrita. Não houve intervenção de backup/certificado operacional, novo deploy, atualização de Agent real ou conexão com equipamento físico nesta revisão.

## Bundle nas branches auxiliares

Removido `agent/dist/recovery8-source.bundle` de todos os commits alcançáveis pelas quatro branches afetadas. A limpeza foi preparada em repositório bare descartável, a partir de cópia local restrita ignorada pelo Git. Publicação por push atômico com quatro leases exatos, sem alterar main ou outras branches. O mecanismo Git nativo foi necessário para preservar metadados e publicar as quatro referências atomicamente; a API de commits do conector não representa essa operação completa.

| Branch | Antes | Depois | Commits conferidos |
| --- | --- | --- | --- |
| dashboard-react-vite | 12fec521c31d0055999018a3815de30446d57119 | 71d98ed1325a856070c72d4d1109c4a7e294e636 | 7 |
| mtr-central-ready | 89ba0f5bd39b8fc6ee1ba7af6e4f55daf4cc58e7 | 7b748caf6364c351b8f63538dd2b4228463b5013 | 6 |
| mtr-path-analysis-lab | f301555a9ea8f83255fe21bb922865bc7777cc60 | efbceeb4c2c23fde23df90eb559f5086ec756b0f | 4 |
| mtr-secure-executor | 4c99a12bc38812a3b09d2c41750a924e1e78b9cf | 52dfa34004e6367d14fae3941b10f56d398af085 | 5 |

Cada commit anterior/posterior foi comparado: mesmos arquivos, modos e blobs, exceto o caminho retirado; mesmos autores, datas, mensagens e relações entre pais remapeadas. Contagens de commits preservadas, inclusive o commit que introduziu o snapshot. Nenhum código foi removido nem fundido em main.

Referências remotas conferidas após a publicação e branches locais sincronizadas. Scanner histórico existente, após sincronização: **161 commits, 3033 blobs, zero correspondências e zero caminhos históricos proibidos**, exit 0. Esse resultado cobre o grafo alcançável pelas referências locais conferidas com o remoto, não a eliminação física de objetos sem referência nos servidores, caches, reflogs ou clones antigos. Não foi identificada credencial exposta pelo achado anterior: o achado era o bundle proibido.

Cópia restrita de segurança: `backups/git-history-cleanup-20261118/original.git`, com manifesto `verification.json`. Contém deliberadamente o histórico anterior e não deve ser publicado ou usado como origem de novos pushes. Reversão dessa limpeza exige decisão explícita: restaurar as referências antigas recolocaria o bundle no GitHub. Não fazer rollback genérico dessa ação.

Quem mantiver clones antigos deve conservar alterações locais e usar as branches limpas; não voltar a enviar os heads antigos. A cópia local de segurança permanece fora das referências do repositório de trabalho e do scan do histórico publicado.

## Testes e ferramentas

O recorte integrado também revelou colisão entre fixtures quando dois métodos com callbacks HTTP reais eram executados na mesma base: `DB::commit()` é necessário para o processo externo enxergar os registros, portanto o rollback transacional de teste não remove esses dados entre métodos. O novo [acceptance.sh](../scripts/lab/routeros-operational/acceptance.sh) executa cada cenário em banco, rede, volume de identidade e serviço novos, sem truncar dados ou relaxar RLS. Os modos fixos `tasks` e `panel` cobrem respectivamente os dois cenários SSH/Task Engine e os três cenários Lifecycle/systemd. Exige assets frontend explícitos e artefatos assinados de laboratório.

Preparações iniciais incorretas foram recusadas: o builder requer diretório contendo somente wheels do catálogo, não o tar de distribuição; o pacote com SSH requer Debian 13/Python 3.13 e recusou a imagem antiga. Corrigidos os inputs e usado o runner Debian 13/systemd existente, preservando `ssh_runtime_incompatible`. Materialização das dependências usa hashes do catálogo e código nativo. Uma tentativa com caminho de bundle incompleto terminou antes dos testes; outra foi interrompida após a incompatibilidade. Os recursos descartáveis foram removidos antes da repetição.

A revisão encontrou um defeito reproduzível no runner [suite.sh](../scripts/lab/routeros-operational/suite.sh): ausência de `packaging/install-agent.py` no snapshot descartável e diretório de execução herdado da imagem. A primeira execução apresentou seis erros de infraestrutura em 200 casos. Corrigidos a cópia do instalador e o workdir `/agent`; nenhuma validação da aplicação foi relaxada. Nova execução: **202 testes Python aprovados**, zero erros, rede desabilitada e usuário não-root.

Testes adicionais de Telegram, DeviceReadOnlyLab e MikrotikProtocolLab: **115 testes, 940 asserções, nenhum skip e zero falhas**, em PostgreSQL TLS e servidor SSH simulador de rede interna. Inclui os dois testes Telegram ignorados na suíte anterior. Nenhuma mensagem real, destino externo ou equipamento físico foi consultado. Logs locais: `/tmp/agent-center-pending-protocol.log` e `/tmp/agent-center-pending-agent-suite.log`.

Modo `tasks` do novo runner: **dois cenários aprovados, 225 + 41 asserções**, sem skips. Agent 0.6.6 empacotado e assinado em Debian 13/systemd, HTTP real na API de laboratório e SSH real no simulador. Primeiro cenário verifica conversa, Task Engine, validação do protocolo, falhas, revogação, isolamento e serialização; segundo valida somente identidade e nega outras capacidades, mantendo SIMULADO/freeze. Guards de rollback populado aprovados, sem descarte das evidências. Logs privados em `/tmp/routeros-acceptance.eI9eUyou/`.

Modo `panel`: atualização assinada a partir do companion antigo **aprovada, 284 asserções**; falha deliberada no pacote final com rollback automático **aprovada, 19 asserções**. Ambos executados pelo fluxo nativo da Central, API HTTPS e timer systemd, sem SSH de manutenção. A restauração é verificada em cenário separado. Logs privados desta repetição em `/tmp/routeros-acceptance.ff45zEne/`.

Restauração no cenário separado: **aprovada, 352 asserções**, totalizando os três cenários `panel` e os dois `tasks` aprovados, sem skips. A restauração do fixture legado 0.6.5 verifica conteúdo original, identidade/configuração e ausência de tarefas de equipamentos; não é autorização para downgrade do helper MTR na VM atual. As proteções do [alinhamento Agent/helper](MTR_HELPER_ALIGNMENT_066_20261118.md) permanecem obrigatórias.

As cinco pendências de cenários condicionais da revisão anterior foram efetivamente reexecutadas. Somadas aos 202 casos Python e 115 casos de protocolo/Telegram, foram 322 casos/cenários aprovados nesta revisão, em suítes distintas. A suíte Laravel de 237 casos da correção anterior não foi recontada como execução desta revisão.

Reprodução, com fixtures assinados de laboratório e dependências materializadas pelo catálogo existente:

```sh
TEST_FRONTEND_BUILD_DIR=/tmp/lab-assets/public/build \
ROUTEROS_PACKAGED_CANDIDATE=/tmp/lab-candidate/agent.pyz \
./scripts/lab/routeros-operational/acceptance.sh tasks

TEST_FRONTEND_BUILD_DIR=/tmp/lab-assets/public/build \
ROUTEROS_BASELINE_BUNDLE=/tmp/lab-baseline \
PANEL_TRANSITION_PACKAGES=/tmp/lab-panel-good \
PANEL_TRANSITION_FAULT_PACKAGES=/tmp/lab-panel-fault \
./scripts/lab/routeros-operational/acceptance.sh panel
```

São caminhos ilustrativos: precisam conter os artefatos verificados conforme [prepare.py](../scripts/lab/routeros-operational/prepare.py), [prepare-transition.py](../scripts/lab/routeros-operational/prepare-transition.py) e [runner existente](../scripts/lab/device-readonly/run.sh). Baseline exigida pelo teste do painel: runtime 0.6.5 de hash `a2de84beb6cb519047c6123423fb420d9d410b41b85c6c9b4a49df63b562d315`; não é nova release operacional. Os containers systemd são privilegiados somente no laboratório descartável, sem mount de gerenciamento do host; a unit do Agent não possui capabilities e o servidor SSH só oferece o simulador interno. Assinaturas desta rodada usam chaves efêmeras de laboratório; não usar credenciais ou chaves privadas operacionais para gerar fixtures novos.

## Auditoria de dependências

O novo runner também recusa bundles incompletos ou com symlinks antes de criar recursos. A primeira preparação do painel copiou somente o runtime antigo, sem seu manifesto/assinatura/chave pública, e o launcher recusou iniciá-lo. A fixture foi completada somente com metadados públicos e validada pela função nativa `verify`, incluindo assinatura, artefato, plataforma e sequência. Nenhuma chave privada operacional foi copiada. Essa tentativa não foi contada como teste aprovado.

`npm audit --package-lock-only --ignore-scripts --json`, com somente os manifests montados em container Node 24 e consulta à fonte de advisories: zero vulnerabilidades de todas as severidades, exit 0. Nenhum lockfile, dependência ou imagem operacional alterado por essas auditorias. Não garantem ausência de vulnerabilidades futuras.

`composer audit --locked --no-plugins --no-scripts --format=json`, em container sem credenciais, com acesso à fonte de advisories: zero advisories e zero pacotes abandonados, exit 0. Lockfile do web em execução e do checkout têm o mesmo SHA-256: `923e0fbb10b9fded280d86acf08b94196a8a8764b9bc85008d67cefb6b875393`. Isso encerra a falta de consulta atualizada do Composer para esse lockfile; não equivale a auditoria de todo o sistema operacional ou de todas as stacks.

## Separação entre pendência e escopo futuro

Fonte e runtime continuam diferentes porque módulos LAB existentes no fonte não foram promovidos. A correção publicada selecionou classes e foi validada; não há justificativa para implantar o checkout inteiro apenas para igualar arquivos. Estado e imagens no [fechamento Telegram/MTR](TELEGRAM_MTR_FECHAMENTO_20261118.md).

Aceite físico MikroTik exige equipamento/destino, Agent, tenant/ator, fingerprint previamente aprovado, credencial dedicada, janela e limites. Esses dados não foram fornecidos nesta solicitação; foram solicitados enquanto a revisão independente continuava. A autorização geral de testes não identifica esses vínculos e não suspende o freeze. Os testes de simulador não serão descritos como aceite físico. Outros fabricantes e WhatsApp permanecem escopo futuro.

Backup externo e certificado de laboratório estão **adiados por decisão do usuário**, sem inspeção ou alteração nesta revisão. Seus registros históricos são preservados.

## Publicação e reversão do código

Evidências sanitizadas versionadas: [resultados dos testes](evidence/remaining-review-20261118/tests.json), [preservação e limpeza do histórico](evidence/remaining-review-20261118/history-verification.json), [auditoria Composer](evidence/remaining-review-20261118/composer-audit.json) e [auditoria npm](evidence/remaining-review-20261118/npm-audit.json). Não incluem logs brutos, dumps, identidades, credenciais, chaves privadas ou binários.

Validações finais do código/documentação: `bash -n` nos dois runners, parse de todos os JSON de evidência, links locais sem destinos ausentes, `git diff --cached --check` e scan básico do índice aprovados. Os testes de aplicação desta rodada estão descritos acima; não foi necessário reimplantar produção.

Conferidos 1355 links locais, zero destinos ausentes. As três chaves privadas efêmeras usadas para assinar os fixtures desta rodada foram removidas após os testes; manifestos, assinaturas e chaves públicas permanecem verificáveis. Nenhuma chave operacional foi modificada.

Nova comparação somente de hashes em `app`, `resources`, `routes` e `config`: 335 arquivos no web, 358 na fonte, 20 arquivos comuns diferentes, 23 exclusivos da fonte e nenhum exclusivo do runtime. Os exclusivos incluem observabilidade e a integração DeviceReadOnlyLab/RouterOS. Inventário local em `/tmp/agent-center-pending-runtime-comparison.json`; nenhuma configuração secreta foi lida por essa comparação. Não é comparação de banco, dependências, assets compilados ou outros workers.

A correção do runner e esta documentação são publicadas em main após validação. Não recriam containers de produção nem alteram banco, filas, política ou identidade. Podem ser revertidas por commit inverso, preservando as mudanças anteriores. A limpeza das branches tem reversão separada e não deve ser desfeita por esse procedimento.
