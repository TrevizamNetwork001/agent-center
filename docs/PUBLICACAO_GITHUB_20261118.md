# Conciliação e publicação no GitHub — 18/11/2026

**Atualização posterior:** o usuário autorizou a limpeza das quatro branches auxiliares. O [relatório de revisão](PENDENCIAS_REVISADAS_20261118.md) registra novos heads, preservação do código e scanner histórico aprovado após a limpeza. A ressalva abaixo descreve o achado anterior; não recolocar os heads antigos no remoto.

> Complemento posterior: as cinco branches locais auxiliares foram publicadas por solicitação do usuário. Isso mudou o alcance da publicação original de main: o bundle antigo descrito abaixo passou a ser alcançável no GitHub pelas quatro branches de desenvolvimento auxiliares. Consulte o [guia de retomada](RETOMADA_DO_PROJETO_20261118.md). O resultado histórico de scan aprovado desta publicação original não se aplica ao conjunto ampliado.

Escopo autorizado: corrigir pendências do repositório, consolidar documentação e publicar fonte/histórico no GitHub. Não é deploy da Central, release operacional assinada, atualização de Agent ou autorização de device.

## Bases preservadas

- Checkout inicial: `f227293ee7e01bcdb10e909a7fe0a40293bbd995`.
- GitHub inicial: `818d57bc022e07ecea3daa0a5d85316a4e92fbc3`.
- Base comum: `ff168b9c96397c7b7b1e6c180796792eb7e7c2ed`.
- Diferença inicial: 120 commits exclusivos locais e 19 exclusivos no remoto.
- As alterações de fonte, testes, interface e documentação que estavam sem commit foram preservadas no snapshot `01be574`.
- Conciliação dos históricos: merge `64bfeb0`, com os dois heads como ancestrais; os ajustes finais e evidências vêm no commit de fechamento.

A conciliação foi preparada em `/tmp/agent-center-publication-20261118`, sem alterar containers de produção. O merge conserva os dois históricos. Nos conflitos de Agent, Laravel, catálogo, workers e views, a implementação local posterior preserva Lifecycle, Preflight, observabilidade, freeze e contratos atuais. Arquivos exclusivos do remoto foram revisados e incorporados, incluindo testes de onboarding, telemetria, instalador e backup. Documentos RECOVERY continuam históricos, com indicação do fluxo consolidado.

## Correções desta revisão

- README e fluxo consolidado com links para os módulos e fechamentos de implantação, separando LAB, runtime e GitHub.
- Testes remotos usam a versão efetiva do pacote e o contrato atual de falha do self-check. Recuperação não verificada permanece UNCERTAIN, em vez de afirmar FAILED sem comprovar rollback.
- Runner de laboratório usa `grep -Fx` para verificar rede interna sem exigir `rg` instalado.
- `scripts/test.sh` verifica o manifesto frontend antes de criar banco e aceita `TEST_FRONTEND_BUILD_DIR` com mount somente leitura, evitando falha de dashboard por build ausente em clone limpo. No laboratório sem rede, Composer usa `COMPOSER_DISABLE_NETWORK=1`; fora dele, o comportamento de rede original permanece.
- Wrapper Compose preserva os overlays atuais e permite arquivo de ambiente explícito para validações isoladas.
- Descrição da unit do Agent não fixa uma versão obsoleta.
- Artefatos locais de build e pacotes foram excluídos pelo `.gitignore`; nenhum arquivo de `artifacts/` foi incorporado. Distribuição operacional continua pelo Lifecycle assinado.
- Whitespace de relatórios textuais novos e de quatro logs históricos Telegram transportados normalizado; conteúdo de resultados preservado. Patches mantêm seus espaços de contexto, com atributo Git específico para evitar tratá-los como erro de whitespace do documento.

## Verificações

Evidências desta rodada: [publicacao-github-20261118](evidence/publicacao-github-20261118/). Resultados antigos não substituem esta validação.

| Verificação | Resultado |
| --- | --- |
| Python Agent, runtime não-root e rede desabilitada | 202 testes PASS |
| Backup em fixtures locais | 19 testes PASS |
| Transporte R2 sintético, sem bucket real | 4 testes PASS |
| Assinatura/adulteração de candidata com chave efêmera LAB | 2 testes PASS |
| Build frontend limpo com lockfile | PASS |
| Ruff | PASS |
| Pint | 383 arquivos PASS |
| Compose com ambiente sintético | PASS |
| Links do README e fluxo consolidado | PASS |
| Chromium offline | 34 páginas, 272 combinações, 15 verificações de teclado PASS |
| Scan histórico e comparação de secrets em memória | Zero correspondências e zero caminhos proibidos no conjunto publicado |
| Laravel/PostgreSQL final | 604 testes, 5.563 asserções, zero falhas/erros e 5 skips opcionais; migrations up/down PASS |

Os cinco skips são três cenários `PanelNativeLifecycleTest` que exigem o runner systemd `PANEL_TRANSITION_LAB=1` e dois cenários `RouterOsTaskIntegrationTest` que exigem `ROUTEROS_TASK_LAB=1`. Não foram reexecutados nesta publicação de fonte; sua homologação histórica está documentada nos fechamentos de 16/11. Esta rodada não os apresenta como novos testes aprovados.

Inspeção visual adicional dos screenshots do dashboard mobile claro e desktop escuro: concluída. A checagem automática não substitui auditoria completa de acessibilidade com tecnologia assistiva.

Os dois cenários de autenticação/permissão que falharam por ausência do manifesto Vite passaram após disponibilizar o build (2 testes, 30 asserções), sem alteração de Policies/Gates. A captura de telas também passou (1 teste, 34 asserções).

A primeira execução Python revelou incompatibilidades em testes históricos; a primeira preparação Laravel não encontrou DNS para baixar dependências. Foram corrigidos os testes e usadas as dependências já instaladas, compatíveis com o lockfile, sem mudar versões. O build frontend usou instalação limpa via lockfile. A indisponibilidade de rede do Composer impede apresentar essa rodada como auditoria atualizada de advisories.

A varredura inicial de todas as referências do clone encontrou um bundle Git antigo em uma branch auxiliar (`faeda52`), ausente dos históricos de main local e remoto. As referências auxiliares não participam da publicação; nenhum histórico de main precisou ser reescrito. O bundle permanece apenas na referência local original, fora do conjunto enviado.

## Preservação e recuperação

Secrets, banco real, backups, credenciais, dependências vendorizadas e artefatos de build permanecem fora do GitHub. Não foram acessados equipamentos nem acionadas APIs Telegram/OpenAI/R2 reais. Os testes criam e removem recursos descartáveis próprios; migrations de teste não usam a base operacional.

A publicação usa push normal, sem force, seguido de consulta ao hash remoto. O estado anterior do GitHub é o commit `818d57b`, preservado como ancestral do merge. Uma correção futura deve ser outro commit revisado; não apagar histórico nem executar reset remoto. Para rollback operacional, seguir o relatório da imagem/release efetivamente implantada, com autorização própria e preservação de dados. Reverter fonte no GitHub não altera runtime nem banco automaticamente.

## Reprodução do build e dos testes isolados

Usar diretórios temporários novos. Os runners recusam recursos de laboratório preexistentes.

```bash
ROUTEROS_BUILD_STATE=/tmp/publication-build-new bash scripts/lab/routeros-tasks/build.sh
TEST_FRONTEND_BUILD_DIR=/tmp/publication-build-new/public/build DEVICE_READONLY_STATE=/tmp/publication-lab-new bash scripts/lab/device-readonly/run.sh
```

Esses comandos são de desenvolvimento/homologação. Não habilitam a integração de equipamentos em produção e não publicam releases do Agent. Dependências Composer já instaladas precisam corresponder ao lockfile; o runner mantém a validação Composer. Em clone limpo, instalar previamente pelo container tooling do projeto em ambiente de desenvolvimento com acesso aos repositórios de pacotes, antes de iniciar o laboratório sem egress. Não copiar envs ou secrets de produção para isso.

## Versões de fonte e runtime

A fonte base em `agent/src` permanece na versão 0.6.4 e a candidata RouterOS 0.6.6 continua separada em `agent/candidates/routeros-operational-v1`. A versão 0.6.6 instalada na VM teste foi distribuída pelo fluxo assinado documentado em 16/11. Esta publicação de fonte não altera essas versões nem transforma a candidata em uma autorização de equipamento físico.

## Estado do workspace

O alinhamento local preserva o head inicial na referência `pre-publication-local-20261118`, conserva o snapshot `01be574` no histórico e mantém secrets, dados e artefatos locais. A cópia dos arquivos versionados exige conferir que o workspace original não mudou desde o snapshot; o índice é alinhado sem reset hard e sem remover arquivos locais. Nenhum serviço de produção é recriado.

## Complemento de preservação e retomada

Após a base documental `4453457`, foram publicadas por push atômico as branches `dashboard-react-vite`, `mtr-central-ready`, `mtr-path-analysis-lab`, `mtr-secure-executor` e `pre-publication-local-20261118`. Consulta direta ao GitHub confirmou os cinco hashes; o [guia de retomada](RETOMADA_DO_PROJETO_20261118.md) registra todos os heads e as pendências. Nenhuma branch foi fundida, excluída ou publicada com force.

O scanner histórico existente foi executado nesta continuação: **159 commits, 2.999 blobs, 1 correspondência de secret ou backup e 1 caminho histórico proibido; exit 1**. A inspeção da árvore identifica `agent/dist/recovery8-source.bundle` no histórico auxiliar, já alertado no relatório original. O resultado não comprova uma credencial exposta e não permite afirmar ausência universal de secrets. A tentativa inicial do scanner falhou por permissão de leitura do material de comparação; a execução posterior com permissão concluiu e produziu as contagens acima, sem emitir valores. O scan básico do índice passou; ele não substitui o histórico e não invalida essa ressalva.

O envio remoto funcionou, mas a atualização dos caches locais `refs/remotes/origin/*` foi recusada pelo filesystem somente leitura de `.git`. Por isso a confirmação usou `git ls-remote`, e não o cache de tracking. A gravação deste complemento exige a permissão de escrita Git disponível ao processo autorizado; não alterar permissões nem identidade SSH para contornar a proteção.

Este complemento modifica somente documentação: guia, índice e links de entrada. Não reexecuta a suíte de aplicação, não inspeciona serviços, não publica release assinada nem acessa equipamentos. O índice permite localizar relatórios, evidências e arquivos de apoio versionados; dados reais, backups e secrets não são documentação para GitHub. A limpeza do bundle remoto continua pendente de decisão específica; não a descrever como concluída.

Validação documental deste complemento: **1.354 links locais conferidos, zero destinos ausentes**, e `git diff --check` aprovado. O índice foi gerado a partir dos caminhos versionados de `docs/`, acrescido do guia novo; não presume completude de informações que nunca foram registradas no repositório.
