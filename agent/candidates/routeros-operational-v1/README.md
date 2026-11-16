# Candidata 0.6.6 — somente laboratório RouterOS

Derivada da fonte assinada 0.6.5, sequência operacional 6. `base/` e
`provenance.json` permanecem imutáveis. `reconciliation.json` registra os arquivos
reconciliados e seus hashes; `preflight.py`, identidade, journal, políticas e
executor da base não foram substituídos. Nenhuma publicação operacional é feita
por estas ferramentas.

A reconciliação acrescenta observabilidade e seu worker limitado, o snapshot da
configuração, inspeção compartilhada com a autorização do executor e pedidos
`observe/check` do helper. O protocolo original `preflight` e a chamada do
heartbeat a `diagnostics/preflight` permanecem presentes e testados.

## Pacote fechado e transação existente

`packaging/build.py OUTPUT agent WHEELS` incorpora somente os seis wheels do
catálogo fechado, com versões e SHA-256 do lock. O manifesto Ed25519 assina o
zipapp inteiro. Não há comando, caminho de instalação ou pacote selecionável
pelo manifesto. O schema de componentes MTR permanece fechado; `routeros-ssh`
não é um novo componente remoto ou um novo updater.

O precheck de download do companion usa o Engine existente para materializar as
dependências em um diretório temporário antes do self-check de versão. O Engine
valida o inventário fechado e extrai os wheels offline dentro de `runtime/ssh`.
Não usa pip, rede, scripts de instalação, `.pth` ou dependências globais.

O estágio inteiro, incluindo o Python Agent e suas bibliotecas nativas, participa
dos renames existentes de runtime/previous/failed. O bootstrap verifica as versões,
bytes e permissões antes de importar. O rollback recupera o diretório anterior e
os companions pelo Lifecycle existente, sem restaurar identidade ou journal.
Estágios interrompidos são removidos somente sob os locks de atualização e
instância, com serviço parado, nome fechado, dono e permissões verificados.

O destino homologado é Debian 13 / CPython 3.13 / amd64. Outros destinos são
recusados. O campo histórico `lifecycle_installation_validated: false` do lock
original não é uma afirmação sobre a execução atual: ele é preservado para não
alterar o inventário reproduzível inicial. O resultado de homologação está no
relatório e nas evidências finais, não nesse campo histórico.

## Ferramentas de laboratório

- `scripts/lab/routeros-operational/suite.sh`: executa todos os testes originais,
  sem alterações, com o builder e a fonte candidatos no mesmo caminho de pacote.
- `prepare.py`: construção reproduzível e assinatura descartável de laboratório;
  chave privada fora do bundle, modo 0600, nunca usada em produção.
- `lifecycle-proof.py`: Manager/RuntimeAdapter/Engine reais, systemd não-root,
  instalação offline, staging assinado, falhas, interrupção real, recuperação e
  rollback de runtime e companions.
- `integrated-lifecycle.py`: supervisor de fixture em namespace systemd
  descartável. Usa os mesmos componentes nativos; não é incluído na release nem
  constitui outro updater. O Agent 0.6.5 autentica na Central de laboratório antes
  da atualização; a mesma instância executa as consultas e retorna a 0.6.5.
- `scripts/lab/device-readonly/run.sh`: runner existente, com os modos opcionais
  `ROUTEROS_SYSTEMD_LAB=1` e `ROUTEROS_SYSTEMD_UPGRADE_LAB=1`. Exige bundle assinado
  temporário e baseline local verificada. Não aceita destinos operacionais.

Os métodos de integração que comprometem fixtures precisam de bancos e processos
Agent novos, um método por execução. Não combinar o transporte SSH direto legado
em Laravel com o modo que exige Python Agent; ambos são validados separadamente.

O freeze permanece obrigatório. O adaptador só anuncia sua capability no
namespace simulado fechado. Não instalar este diretório em serviços operacionais,
não publicar release e não conectar equipamentos físicos. Consulte
`docs/MIKROTIK_READONLY_066_LAB_COMPLETION.md` para resultados e limites finais.
