# AGENT CENTER MIKROTIK READ-ONLY LAB PASS

Fonte: commit `25ff80a`. Nenhum deploy, nenhuma conexão real, nenhum CHR, nenhuma consulta em rede de gerência, nenhuma tarefa operacional criada. Agent, Lifecycle, Preflight publicado, ACLs e R2 não foram modificados por esta entrega. As alterações locais preexistentes ficaram fora do commit.

## Validação efetivamente executada

| Categoria | Resultado | Alcance |
|---|---|---|
| A — unitários | PASS | 5 testes finais: catálogo fechado, injeção, IPv6 indisponível, parsing, limites, unidades/números, CRLF, schemas e dados ausentes |
| B — SSH simulado | PASS | 10 testes especializados RouterOS + integração Telegram nativa, regressões do laboratório existente e duas provas de concorrência com processos reais |
| C — CHR real autorizado | NÃO EXECUTADO | Não houve autorização para instância real; não há evidência de compatibilidade operacional de firmware |
| D — hardware de produção | FORA DO ESCOPO | Nenhum acesso, sondagem, credencial ou alteração |
| Laravel — suíte completa | PASS: 583 testes / 5.389 assertions | Inclui regressões de RBAC, FORCE RLS, tenant isolation, CSRF, inventário, análise, Intent Planning, Preflight, Conversation Engine, Telegram, Task Engine e demais módulos |
| Laravel — regressão final | PASS: 54 testes / 1.281 assertions | Executada **depois** dos últimos reforços: datas de aprovação, total de registros, CPU malformada e distinção entre connections e configuração BGP completa. Inclui todas as áreas alteradas e transporte legado |
| Python Agent | PASS: 185 testes | Container sem rede; nenhuma edição de Agent nesta entrega |
| Pint | PASS: 21 arquivos PHP | Somente arquivos PHP da entrega |
| Ruff tooling LAB | PASS | `scripts/lab/device-readonly` |
| Ruff global | **FAIL preexistente: 2 I001** | `agent/src/agent_center/heartbeat.py:1` e `agent/src/agent_center/update/remote.py:8`; arquivos já modificados antes da missão, preservados e fora dos commits |
| Frontend build | PASS | Node 24, `npm ci` + Vite em diretório descartável; sem publicar assets |
| Navegador offline | PASS | 390 / 768 / 1440 px; claro/escuro; seis formulários tipados, foco por teclado, sem overflow ou erros de execução |
| Secret scan | PASS | Scanner básico do índice Git; não substitui scanner especializado |
| Diff / sintaxe Bash | PASS | `git diff --check`, cached check e `bash -n` |
| Rollback vazio | PASS | Migration up/down em PostgreSQL separado |
| Rollback populado | PASS | Recusa remoção de aprovações/credenciais e preserva dados |

Os 54 testes finais **não se somam** aos 583 como testes distintos: são uma regressão direcionada após os últimos ajustes. A suíte final contém dois casos novos adicionais aos da execução completa. Não foram ocultados os dois erros Ruff preexistentes nem alterado código do Agent para produzir PASS.

## Protocolo e cenários

Negociação SSH, autenticação por chave, pinning antes da autenticação, fingerprint diferente, credencial inválida, rotação/revogação, versão desconhecida, identidade divergente, comando fora do catálogo, shell/PTY/forwarding negados, BGP estabelecido/não estabelecido/sem sessões/sem connections, rota /24 para consulta de host /32, ausência de rota, tabela alternativa, interfaces ativas/desativadas, contadores acumulados/ausentes, CPU/memória elevadas sem limiar inventado, resposta incompleta, inválida, truncada, sensível, extensa, excesso de registros por resposta e acumulado, timeout, indisponibilidade, desconexão, política/data/Agent inválidos e idempotência.

A prova especializada de concorrência fez quatro solicitações duplicadas convergirem para um resultado e quatro solicitações distintas terminarem em quatro resultados. Cinco consultas de protocolo ficaram serializadas, sem sobreposição; uma solicitação excedente recebeu LAB_BUSY e foi repetida com a mesma chave após terminar a contenção. Zero AgentTasks. O teste legado também serializou quatro atores distintos e preservou a deduplicação.

O simulador não interpreta CLI geral: respostas pertencem à allowlist de fixtures; comandos não cadastrados retornam exit status 126. Isolamento provado sem sondagens: rede Docker internal, ausência de rota default IPv4/IPv6 e transporte compilado em loopback. Não se confunde SSH real contra servidor simulado com RouterOS/CHR real.

## Reprodução

```bash
TEST_DEVICE_PROTOCOL_CONCURRENCY=1 TEST_ROUTEROS_PROTOCOL_CONCURRENCY=1 \
DEVICE_READONLY_STATE=/tmp/ac-mikrotik-lab-UNIQUE \
./scripts/lab/device-readonly/run.sh
```

O caminho deve ser novo. O runner recusa recursos preexistentes, cria PostgreSQL/TLS/credenciais próprios e remove somente seus containers/rede/volumes efêmeros. Requer as imagens/tooling locais do projeto. Não usa `.env` ou banco de produção. Nunca substituir o loopback por equipamento real para reproduzir.

Regressão final executada:

```bash
TEST_DEVICE_PROTOCOL_CONCURRENCY=1 TEST_ROUTEROS_PROTOCOL_CONCURRENCY=1 \
DEVICE_READONLY_STATE=/tmp/ac-mikrotik-security-UNIQUE \
./scripts/lab/device-readonly/run.sh \
  --filter 'MikrotikProtocol|DeviceReadOnlyLabTest|DeviceAnalysis|NocInventoryTest|test_mikrotik_typed_protocol'
```

Python: `python:3.13-slim`, mount somente leitura, `--network none`, `PYTHONPATH=/src/src`, `python -m unittest discover -s tests`. Ruff: `ghcr.io/astral-sh/ruff:0.14.5`, `check --no-cache --config agent/pyproject.toml agent scripts/lab/device-readonly`; execução separada apenas do tooling LAB confirmou zero erros novos. Composer usou o lock existente e emitiu aviso de indisponibilidade da lista de filtros do Packagist por estar sem rede; não foi alegado audit de vulnerabilidades de dependências atualizado.

## Artefatos e limitações

- [Resumo estruturado](validation-summary.json), [manifesto de fonte e imagem](source-manifest.json).
- [Isolamento](network-isolation.json), [negações SSH](protocol-boundaries.json).
- [Concorrência RouterOS](mikrotik-concurrency-result.json), [concorrência legada](concurrency-result.json).
- [Resultados do navegador](browser-results.json).
- [Mobile claro](routeros-panel-390-light.png), [mobile escuro](routeros-result-390-dark.png).
- [Desktop claro](routeros-panel-1440-light.png), [desktop escuro](routeros-result-1440-dark.png); demais capturas também versionadas.
- [Arquitetura, contratos, permissões mínimas, homologação real futura e rollback](../../MIKROTIK_READONLY_PROTOCOL_V1.md).

Imagem é somente base de tooling local; código/fixtures são mounts versionados identificados por commit e SHA256. Não existe imagem operacional ou release de Agent nesta entrega. Fixtures representam um dialeto fechado de console 7.16.2, não são capturas reais; versão atual da documentação oficial e diferenças de firmware estão explicitadas na arquitetura. IPv6 não executável; velocidade/famílias negociadas/encaminhamento efetivo/taxas permanecem indisponíveis quando não comprovados. Contagem zero de connections não comprova ausência de templates ou instâncias BGP. Estados privados efêmeros contêm chaves descartáveis e não fazem parte destas evidências.

A revisão visual usou os assets públicos preexistentes do workspace, identificados por SHA256 no manifesto. Alterações locais anteriores de CSS/JS não foram incluídas nestes commits. Essa revisão offline não alega validar ou publicar a interface de produção.
