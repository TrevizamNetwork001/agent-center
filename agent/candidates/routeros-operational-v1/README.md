# Candidato RouterOS operacional V1 — INCOMPLETO / NÃO PUBLICAR

Base recuperada do artefato operacional 0.6.5, sequência 6. A ferramenta
`../../../scripts/lab/routeros-operational/reconcile.py` fixa o SHA-256 publicado
e o hash da chave pública previamente registrada e usa a verificação nativa do
Lifecycle antes de extrair qualquer fonte. Sequências 3 e 4 não são usadas.
`provenance.json` contém os hashes individuais e a comparação com o workspace
local; `base/` deve permanecer byte a byte igual ao arquivo verificado.

`src/` preserva todos os arquivos dessa base, exceto a identificação do candidato
0.6.6 a ligação aditiva do adaptador no dispatcher de capabilities, o self-check sem
sondar helpers opcionais e a conversão de JSON recursivo em erro tipado da API. O módulo
RouterOS existente foi copiado sem remover os bloqueios de simulador, escopo,
identidade, destino, parâmetros, timeout, saída e concorrência. O Agent
operacional e o runtime local não foram substituídos.

Não é release utilizável: a Central ainda não negocia esta versão e o Lifecycle
preservado não possui instalação/rollback do componente SSH. Não assinar nem
publicar este diretório como release completa. Não adicioná-lo ao PYTHONPATH de
serviços operacionais. O novo nome de versão evita confundir fontes diferentes
com a release operacional 0.6.5.

## Dependências offline

`ssh-dependencies.lock.json` fixa seis wheels para CPython 3.13 / Debian 13 amd64,
SHA-256, requisitos Python e arquivos de licença presentes nos wheels. A licença
é preservada integralmente no bundle; seus hashes não substituem revisão jurídica
nem avaliação de vulnerabilidades. Outras arquiteturas não foram homologadas.

Construir a imagem técnica com `scripts/lab/routeros-operational/Dockerfile.systemd`.
Executar `scripts/lab/routeros-operational/fetch-wheels.sh` para baixar somente os
wheels com os hashes do lock e gerar um TAR determinístico fora do Git. Esse passo
é tooling de build, nunca um comando de tarefa. O bundle não é distribuído pelo
Lifecycle atual e não deve ser instalado manualmente em produção.

`bundle.py` recusa inventário incompleto, versão divergente, licença ausente e
alteração do lock existente. Não instala dependências. O teste offline instalou o
bundle em um venv temporário de contêiner isolado, com `--no-index --require-hashes
--no-deps`; nenhuma instalação global foi feita no host.

## Verificações

`PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s scripts/lab/routeros-operational/tests -v`

O probe systemd inicia somente imports e self-check sem identidade, credencial,
heartbeat ou tarefas. As evidências em `docs/evidence/mikrotik-operational-v1/`
comprovam Debian 13, Python 3.13, PID 1 systemd e execução como UID 65534, com
NoNewPrivileges e conjuntos de capabilities vazios. O contêiner de init exigiu
modo privilegiado, cgroup privado, rootfs somente leitura, nenhuma montagem do
host e rede `none`. Isso não homologa o sandbox da VM operacional.

## Rollback

Não houve implantação. Descartar exclusivamente os contêineres e arquivos
temporários deste laboratório e este candidato restaura o estado anterior;
identidade, journal, configurações e catálogo operacionais nunca foram alterados.
Não há ainda evidência de rollback de dependências SSH pelo Lifecycle. Essa
condição bloqueia a homologação física, mesmo que o bootstrap offline funcione.
