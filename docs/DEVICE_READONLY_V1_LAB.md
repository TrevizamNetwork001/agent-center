# Device Read-Only V1 — laboratório SSH

Escopo: Laravel Central, Huawei NE8000 M4 / V800R023C00SPC500 e CCR2004-16G-2S+ / RouterOS 7.16.2 **SIMULADOS**. Não há homologação de hardware, conexão real, publicação, release do Agent ou liberação de device operations.

## Arquitetura e fluxo

1. Inventário NOC conserva UUID, tenant, fabricante, plataforma, modelo, versão, IP de gerência, POP, ambiente e revisão. Cadastrar um equipamento não cria credencial ou aprovação.
2. `DeviceLabAccess` acrescenta uma referência UUID protegida: tenant/equipamento/revisão, pin público SSH explicitamente aprovado, credencial cifrada, validade, estado e revisão. O IP do inventário nunca é usado como destino.
3. Web autenticada ou uma solicitação explícita “Simule ssh-bgp no NE8000” entra no fluxo existente de simulação do inventário. Gate `simulate`, tenant, ambiente, revisão, contrato e parâmetros são verificados antes de I/O.
4. Dentro da transação existente, o advisory lock do ator e o lock do equipamento preservam idempotência e serializam consultas. A espera por lock neste fluxo de laboratório é limitada a 2 segundos. Não foi criado scheduler, daemon operacional ou executor de tasks.
5. O preflight exclusivo do laboratório exige ambiente `testing`/`local`, as duas allowlists de tenant, habilitação explícita, equipamento ativo em laboratório, credencial aprovada/não expirada/não revogada e revisão correspondente.
6. `Platform`/`Operation` selecionam operações fechadas. `Ssh` conecta somente a `127.0.0.1:2222` ou `127.0.0.1:2223`, verifica a assinatura SSH e compara o blob completo da chave com o pin aprovado **antes** da autenticação. Não há argumento de endereço, hostname, porta, usuário ou CLI vindo da interface/LLM.
7. Autenticação somente com chave, usuário fixo `lab-readonly`. Identificação da plataforma precede a consulta. Não há PTY, shell interativo, script, forwarding ou descoberta de rede.
8. `Parser` normaliza texto de fixtures em `Evidence`/schemas existentes. `Interpretation` produz fatos determinísticos. O resultado fica em `noc_simulations`, imutável, `source=FIXTURE`, `transport=SSH_LOOPBACK_LAB`, `SIMULADO`, `real_evidence=false`, `task_eligible=false` e `health_eligible=false`.
9. O painel existente mostra fatos e evidências tipadas. Conversation Engine e Telegram reutilizam o apresentador e outbox existentes. A entrega do Telegram revalida habilitação do laboratório e RBAC. OpenAI pode selecionar somente opções de apresentação aprovadas; não fornece comandos ou medições.

Os 25 itens do catálogo e 34 cenários anteriores permanecem. Schemas receberam apenas campos opcionais tipados: família BGP, bytes/erros acumulados de interfaces e percentual de memória disponível sem total em bytes. Percentual e bytes de memória contraditórios são rejeitados.

## Contratos de consulta

Todos os contratos reutilizam schemas de entrada/saída, compatibilidade declarada e limites de Device Analysis V1. A matriz de protocolo desta entrega é mais estreita: somente os dois modelos/releases sintéticos acima. Outras releases declaradas no catálogo não estão homologadas por estes testes.

| Contrato | Huawei, operação fixa | RouterOS 7, operação fixa |
| --- | --- | --- |
| `DEVICE_BGP_PEERS_READ` | `display bgp peer` | `/routing bgp session print detail without-paging` |
| `DEVICE_ROUTE_LOOKUP_READ` | `display ip routing-table 8.8.8.8` | `/ip route print detail without-paging where dst-address=8.8.8.8/32` |
| `DEVICE_INTERFACES_READ` | `display interface` | `/interface print stats-detail without-paging` |
| `DEVICE_RESOURCES_READ` | `display health` | `/system resource print` |

Identificação fixa: `display version` ou `/system resource print`. Nenhuma operação de configuração possui representação no enum.

- BGP: filtro opcional `peer` aplicado à evidência; peer, estado, uptime, família e transições realmente presentes. Não são inventados ASN, histórico ou causa raiz.
- Rota: `destination=8.8.8.8`, tabela `main`. Qualquer outro destino/tabela é recusado antes do transporte. A fixture RouterOS contém uma rota /32 exata; não comprova seleção LPM, FIB, ECMP ou encaminhamento real. Consulta de tabela é diferente de `ROUTE_PATH_ANALYSIS`/MTR do Agent.
- Interfaces: filtro opcional `interface`; estado administrativo/operacional, velocidade, bytes e erros acumulados. Contadores diferentes de zero sem janela não classificam falha. Flaps sem medição permanecem `null`.
- Recursos: CPU e memória quando fornecidas. Huawei informa percentual, sem inventar bytes. RouterOS fornece total/livre. Sem referências e janela, a interpretação é inconclusiva; referências opcionais do operador são explicitamente identificadas como de laboratório.
- Limites: sessão SSH total de até 5 segundos (além da espera limitada pelo lock), conexão/negociação inicial de 1 segundo, máximo de 65.536 bytes por canal e 128 linhas estruturadas. Canal com excesso de saída, timeout, status de saída não zero, vazio, truncamento, duplicatas, schema inválido ou identidade divergente falha sem resultado de saúde.
- Auditoria: aprovação/rotação, revogação, conclusão pelo evento de simulação e falha sanitizada; tenant, ator, equipamento, contrato, revisão e digest dos parâmetros quando disponíveis. Nunca persistir saída CLI bruta, chave privada ou texto de exceção da biblioteca.

## Credenciais e isolamento

`SecretVault` existente cifra o material com AES-256-GCM e vincula o envelope ao UUID da referência e tenant. A chave mestra permanece fora do Git, com permissões restritas; também são reutilizados os mecanismos de chave anterior. `SensitiveParameter` protege argumentos de segredo nas fronteiras adicionadas.

Aprovação/rotação e revogação são métodos de provisionamento do laboratório, autorizados pelo gate administrativo existente. Não há formulário/endpoint para receber credenciais, nem TOFU. A aprovação vale por uma hora; mudar a revisão do inventário invalida a aprovação. Chaves privadas, identificadores de chave de cifragem e pin não são serializados pelo modelo na interface. O usuário SSH simulado não tem shell, PTY, configuração ou encaminhamento.

`device_lab_accesses` tem FORCE RLS, filtro Eloquent por tenant, FK composta para inventário e bloqueio de exclusão. Grants de instalação cobrem apenas SELECT/INSERT/UPDATE nessa tabela nova, sem acesso a equipamentos. Nenhum grant ou migration foi aplicado na produção.

O runner gera **novos** secrets efêmeros em diretório 0700 fora do Git. Não lê `.env` ou credenciais da produção. Sobrepõe o dotenv da aplicação com um arquivo vazio no container de teste. Cria PostgreSQL próprio com TLS e papéis separados, rede Docker `--internal`, sem publicação de portas, SSH somente em loopback e execução PHP no mesmo namespace de rede do simulador. O simulador não possui subprocessos, sockets de saída ou encaminhamento. Não altera firewall, ACL, políticas publicadas ou rede de gerência. Composer roda sem rede no modo desse laboratório.

## Reproduzir

Pré-requisitos: imagens locais de tooling do projeto e PostgreSQL 17 Alpine, Docker e OpenSSL. O build instala Paramiko somente na imagem descartável de tooling de protocolo. Nunca integra Python à Central ou ao Agent.

```sh
# Diretório novo a cada execução; não reutilizar secrets antigos.
DEVICE_READONLY_STATE=/tmp/device-readonly-run-novo scripts/lab/device-readonly/run.sh

# Protocolo, formulário e regressões relacionadas, com contenção de quatro processos.
DEVICE_READONLY_STATE=/tmp/device-readonly-race-novo TEST_DEVICE_PROTOCOL_CONCURRENCY=1 \
  scripts/lab/device-readonly/run.sh --filter 'DeviceReadOnly|NocInventory|test_device_readonly_ssh_lab'
```

O runner utiliza `scripts/test.sh`: banco de teste próprio, migrations Laravel, grants, papel runtime sem bypass de RLS e PHPUnit. Na execução sem fixture persistente testa rollback de schema vazio. No modo de concorrência testa recusa de rollback destrutivo com dados existentes; o banco descartável é removido pelo harness após a prova.

A captura visual usa HTML renderizado por Laravel em transações de teste, com tokens removidos, e Playwright em container `--network none`; todos os recursos são servidos de arquivos locais. Não constitui browser de produção nem teste em equipamento físico.

## Freeze e limites de validação

- Pedidos normais de BGP/rota/interfaces/CPU continuam indisponíveis operacionalmente. Somente pedidos explícitos `Simule ssh-*` entram neste laboratório, após as verificações determinísticas.
- `Intent Planning`, Preflight MTR publicado, Task Engine, Agent operacional, Lifecycle Manager, ACLs e R2 não recebem alterações operacionais. Nenhum `AgentTask` é criado pelas consultas de laboratório.
- Os textos são **fixtures sintéticas de protocolo**, não capturas verificadas de saída de firmware. Os parsers são estritos para esses dialetos. Passar no laboratório não certifica sintaxe, paginação, prompts, RBAC ou comportamento de equipamento físico.
- Não há credencial real, DNS de gerência, NETCONF, API RouterOS, escrita de configuração, system-view, reboot, VLAN, ONU ou provisionamento nesta entrega.
- O estado de sessões BGP sintéticas não identifica causa raiz. Uma rota sintética não prova conectividade. Falha do endpoint de laboratório não prova queda de um equipamento real.

## Publicação e rollback

Não publicar automaticamente. Uma futura publicação exige autorização operacional específica, revisão dos commits, backup de PostgreSQL/chaves e laboratório de regressão. Publicar somente a Central com flags de laboratório desabilitadas por padrão; jamais anexar o transporte a redes reais ou habilitar credenciais reais por configuração. Não há pacote de Agent a assinar/distribuir nesta entrega. Futuras alterações do Agent devem seguir exclusivamente o Lifecycle Manager existente, com release assinada, compatibilidade, laboratório, backup e rollback.

Rollback de código: desabilitar as flags de laboratório, aguardar o limite das sessões e restaurar a versão anterior da Central autorizada. Preservar inventário, simulações, credenciais cifradas e auditoria. A migration recusa `down()` quando há credenciais; não apagar tabela/chave para forçar rollback. Revogar credenciais de laboratório pelo fluxo auditado quando apropriado. O runner remove somente seus próprios containers/rede e banco descartável, preservando artefatos de teste no diretório privado.

## Configuração futura — somente desenho

Qualquer evolução de escrita precisa de escopo e autorização novos: plano tipado de mudança e diff de prévia; validação determinística e de compatibilidade; backup verificável; aprovação humana vinculada ao hash do plano/tenant/equipamento; janela operacional e expiração; aplicação limitada e controlada; pós-validação; gatilhos determinísticos de rollback e recuperação do backup; auditoria de cada transição. LLM apenas explica o plano/evidência. Nenhum desses passos de escrita foi implementado ou habilitado.

## Correção do formulário do inventário

Erros comuns de validação preservam os campos administrativos seguros e a revisão otimista. Credenciais/conteúdo inseguro e campos desconhecidos continuam descartados. Mensagens e nomes de campos estão em português. Hostname explica formato, exemplos e a diferença para o nome livre; erro aparece junto ao campo com `aria-invalid`/`aria-describedby` e foco inicial. O aviso superior pode ser fechado e desaparece após 10 segundos, com pausa ao interagir; os erros junto aos campos continuam visíveis. A mudança permanece restrita às páginas de cadastro/edição do inventário.

Evidências e resultados finais: `docs/evidence/device-readonly-v1/`.

Resultado consolidado: **AGENT CENTER DEVICE READ-ONLY V1 LAB PASS**. Regressão funcional completa em três grupos independentes: 568 testes Laravel / 5.205 asserções e 185 testes Python. Pint, Ruff do laboratório, build, interface, protocolo, concorrência e rollback passaram. O Ruff global permanece com dois erros de importação preexistentes no Agent; detalhes e limitações no relatório. Nenhuma publicação ou operação real foi realizada.
