# Preparação CHR real V1 — evidências offline

Data: 2026-11-13. HEAD revisado: `487ed691622d64614d383c91c9f50f8bd152168f`, candidato `25ff80afe570900bf1a652791d4d892255154742`. Escopo de execução: somente simulador SSH e PostgreSQL descartáveis, rede Docker internal, sem consultas reais ao CHR ou redes operacionais.

**BLOCKED:** transporte real com Task Engine/Preflight não implementado; autorização e atestados da instância não fornecidos. [Plano completo](../../MIKROTIK_CHR_REAL_HOMOLOGATION_PLAN_V1.md).

## Validação executada

```bash
TEST_DEVICE_PROTOCOL_CONCURRENCY=1 TEST_ROUTEROS_PROTOCOL_CONCURRENCY=1 \
DEVICE_READONLY_STATE=/tmp/ac-chr-preparation-20261113 \
./scripts/lab/device-readonly/run.sh \
  --filter 'MikrotikProtocol|DeviceReadOnlyLabTest|DeviceAnalysis|NocInventoryTest|test_mikrotik_typed_protocol'
```

Runner terminou com exit code 0 e removeu seus containers/rede/volumes pelo cleanup existente. Não utilizou arquivos de ambiente/banco de produção. Estado privado temporário não foi copiado para o repositório; somente os quatro JSON sanitizados relacionados abaixo. Nunca copiar o diretório temporário inteiro, pois contém credenciais efêmeras.

| Verificação | Resultado efetivo |
|---|---|
| Regressão Laravel dirigida | PASS — 54 testes, 1.281 assertions; PHPUnit 12.5.43, PHP 8.4.27; 1 min 22,378 s |
| Isolamento | PASS — rede internal, sem rota default, zero probes externos, transporte loopback, IP de gerência não usado |
| Fronteira SSH simulada | PASS — shell, PTY, forwarding e comandos fora do catálogo negados em ambos os endpoints simulados |
| Concorrência RouterOS | PASS — quatro duplicatas → um resultado; quatro solicitações distintas serializadas, sem sobreposição; um LAB_BUSY; zero AgentTasks |
| Concorrência legado LAB | PASS — quatro atores distintos serializados e duplicatas deduplicadas; zero AgentTasks |
| Rollback com dados | PASS — guards RouterOS/legado recusam perda de aprovações/dados |
| Comparação fonte do protocolo com 25ff80a | PASS — sem diferenças em ReadOnlyLab, contratos RouterOS e dois testes especializados |
| Sintaxe Bash dos runners | PASS — bash -n |
| Whitespace | PASS — git diff --check; documentos novos também verificados com git diff --no-index --check |
| CHR/RouterOS real | NÃO EXECUTADO |
| Suíte Laravel completa / Python | NÃO REEXECUTADAS; 583/185 são resultados históricos, não desta preparação |

Composer reutilizou dependências do lock sem instalar/atualizar pacotes. Emissão observada: lista de filtros Packagist não acessível por ausência de resolução de rede no container isolado. Não é evidência de audit de vulnerabilidades atualizado. Nenhum download/deploy de Agent, operação Lifecycle, R2, equipamento ou migração operacional foi realizado. Migrations executadas pelo runner ficaram no PostgreSQL descartável.

## Artefatos sanitizados

- [Isolamento](network-isolation.json).
- [Negativas SSH](protocol-boundaries.json).
- [Concorrência RouterOS](mikrotik-concurrency-result.json).
- [Concorrência LAB legada](concurrency-result.json).

## SHA256 da fonte revisada

```text
6ccd97279d45fc9e314aca3d89c32bf72996281ccd70b72af946a686fe9b67a5  app/resources/routeros7-protocol-contracts.json
3503468dea0836b6213cfcdcc76a53734950ae77e3ac9eb5b9f9a684860aabbd  app/app/Noc/ReadOnlyLab/RouterOs7/Operation.php
4fb42b5de00124f59dbbfe58bba2b252a0430fd3ca91b4cc37a266b8d2521e16  app/app/Noc/ReadOnlyLab/RouterOs7/Protocol.php
bd3f307e5d8afc046a8795576201180ca6d0e69da868363b8fa5770ad8632d9e  app/app/Noc/ReadOnlyLab/RouterOs7/Evidence.php
5fea4ef3221412b6c4eb456b4b2402794546796848625b3d396c1cd19d198c4a  app/app/Noc/ReadOnlyLab/RouterOs7/Approval.php
812e851aa43a2d9a751631e228f71c6dbfa848d13512724d04ead3e678b10e8a  app/app/Noc/ReadOnlyLab/RouterOs7/Query.php
a76cdaae323fddf9b066040c3fc4496c20dbef0d21813c27c1c64076a65d6bc9  app/app/Noc/ReadOnlyLab/Ssh.php
```

Somente documentos/evidências foram adicionados nesta preparação. Alterações locais anteriores foram preservadas. Não há commit nem alteração de runtime nesta rodada. Device freeze permanece obrigatório; este material não autoriza conectar.
