# MikroTik Read-Only Protocol V1 — laboratório

Escopo: desenvolvimento local, Central Laravel/PostgreSQL. Nenhuma publicação, atualização do Agent, conexão a CHR ou equipamento real. Cadastrar um MikroTik v7 não autoriza acesso. A versão exata e o modelo continuam obrigatórios; a homologação simulada desta entrega se limita a **7.16.2 / CCR2004-16G-2S+**. Não certifica todos os releases 7.x.

## Arquitetura reutilizada

Inventário `NocDevice`, revisões, Policies/Gates nativos → `Inventory::simulate` (transação, lock do equipamento, advisory lock do ator, idempotência) → `ReadOnlyLab\Query` → especialização `RouterOs7\Query` → transporte SSH existente → parser determinístico → `NocSimulation` imutável, tenant/FORCE RLS, auditoria → painel / Conversation Engine / Telegram existentes. Nenhum novo executor, fila, Task Engine, inventário ou motor de autorização operacional. SecretVault existente cifra chaves efêmeras com contexto tenant/registro. A migração Laravel adiciona somente uma aprovação JSONB à tabela LAB já protegida por FORCE RLS.

O Task Engine e Preflight publicados permanecem inalterados: não recebem capacidades novas. Intenções normais de BGP/rotas/interfaces continuam indisponíveis operacionalmente. UNKNOWN, DENIED ou STALE nunca são convertidos em autorização real. A atestação local não é um resultado APPROVED do Preflight operacional; permite somente o simulador fixo, sem tarefa de Agent. `Agent` é vínculo administrativo verificado por tenant/estado/revogação, nunca executor desta entrega.

## Contratos e consultas

Contratos versionados e schemas fechados: `app/resources/routeros7-protocol-contracts.json`. Entradas compatíveis reutilizam Device Analysis; validação adicional no limite do protocolo. Saídas têm propriedades fechadas, tipos, campos nulos explícitos, máximos e motivos de indisponibilidade. Alias de compatibilidade: `DEVICE_IDENTITY_READ` usa o contrato de inventário `DEVICE_IDENTIFICATION_READ`, sem registrar capacidade operacional.

| Operação v1 | Template fechado | Evidência |
|---|---|---|
| DEVICE_IDENTITY_READ | `/system resource print`, depois `/system identity print` | Nome, modelo, versão exata |
| DEVICE_BGP_PEERS_READ | `/routing bgp session print terse without-paging`; `/routing bgp connection print count-only` | Nome, endereço, ASN remoto quando presente, Established/não estabelecida, uptime, famílias **anunciadas**, famílias negociadas nulas |
| DEVICE_ROUTE_LOOKUP_READ | `/routing route print terse without-paging where dst-address={prefixo_canonico} && routing-table={tabela_validada}` | Prefixo ativo observado, protocolo, gateway configurado, distância; próximo salto efetivo nulo |
| DEVICE_INTERFACES_READ | `/interface print terse without-paging` | Estados disponíveis; velocidade nula |
| DEVICE_INTERFACE_COUNTERS_READ | `/interface print stats-detail without-paging` | Bytes/pacotes/erros/drops disponíveis; sem taxa; timestamp local LAB |
| DEVICE_RESOURCES_READ | `/system resource print` | CPU, memória, uptime, versão; ausência explicitada |

Cada sessão lê primeiro a identidade de recursos, valida modelo e versão contra a revisão do inventário, e só então executa as consultas tipadas. IPv4: no máximo 33 prefixos ancestrais (/32 até /0), interrompendo no primeiro prefixo com candidatos ativos válidos. Não lê a tabela global. Identidade + consultas compartilham **5 s**, **65.536 bytes acumulados**, **128 registros**, no máximo 34 consultas. IPv6 possui contrato de entrada existente, mas retorna `IPV6_NOT_HOMOLOGATED` antes de conectar. Nomes de tabela restritos a `[A-Za-z][A-Za-z0-9_-]{0,31}`; nenhuma expressão do usuário.

Uma rota configurada pode estar inativa. Dinâmica indica origem de aprendizado, não prova seleção. A ativa é candidata elegível à FIB; a tabela consultada não inclui automaticamente regras de política, VRF, FastTrack, ECMP ou decisão efetiva de encaminhamento. Consultas sucessivas não constituem snapshot atômico. Resultado é LONGEST_ACTIVE_OBSERVED_RIB_PREFIX, sem afirmar next-hop efetivo ou caminho. Não há MTR/ping/traceroute no roteador.

Sessões vazias com contagem zero de connections indicam NO_BGP_CONNECTIONS_CONFIGURED no cenário, sem afirmar ausência de templates/instâncias BGP; contagem positiva indica NO_SESSIONS_OBSERVED. O campo configuration_present permanece nulo quando nenhuma connection foi contada; connections_configured registra somente o que essa leitura comprova. Não se inventa estado Idle/causa de desconexão. Famílias remote.afi são anunciadas pelo peer, **não** comprovam negociação. Contadores acumulados não comprovam erro atual; não há duas amostras nem boot-id comprovado. CPU/memória elevadas são números, sem limiar ou causa inventados. Respostas são sempre conservadoramente PARTIAL/INCONCLUSIVE.

## Segurança e vínculo administrativo

`Access::approve` continua somente API interna de provisionamento LAB, sem rota HTTP de credenciais. Nova rotação invalida a atestação especializada. `RouterOs7\Approval::attest` exige tenant/RBAC, inventário LAB ativo, modelo/versão declarados, Agent ativo do mesmo tenant, fingerprint SHA256 fornecido previamente por canal independente e direitos efetivos exatamente `read,ssh`. Atestação vincula ator, operação, digest de parâmetros, revisão de política, revisão de equipamento/credencial, IP declarado e porta futura 22; expira em 15 minutos. Não usa o endereço de gerência como destino. Revogação, chave alterada, Agent revogado, política/revisão/tenant/parâmetros diferentes bloqueiam antes do transporte. Revalidação imediatamente antes da conexão, inclusive no transporte compartilhado.

A janela LAB não autoriza equipamento real. O freeze operacional continua ativo. O único endereço de transporte é `127.0.0.1:2223`, compilado no enum existente, no namespace de rede do simulador. Nenhum host/porta entra por LLM ou formulário. Pinning compara o blob completo da chave cuja assinatura SSH foi validada; sem TOFU, AutoAddPolicy ou known_hosts desabilitado. Só public-key, exec limitado; nenhum PTY, shell, script, subsistema ou forwarding. Campos de resposta desconhecidos, inclusive potenciais secrets, rejeitam a resposta inteira; nenhum console bruto é salvo em banco, interface, LLM, auditoria ou mensagens. Erros são códigos fechados.

Servidor simulado usa fixtures versionadas em `device-readonly-cli-fixtures.json`, chaves efêmeras geradas fora do Git, permissões 0700/0600. Não executa subprocessos. Docker network `--internal`, sem rota default IPv4/IPv6, sem portas publicadas, cap-drop ALL/no-new-privileges no servidor. Somente PostgreSQL efêmero no mesmo segmento. A prova de isolamento lê rotas locais, sem sondar nenhum endereço externo. Nenhuma exceção de firewall/ACL é criada.

## Web, conversa e Telegram

POST autenticado existente `/noc-inventory/{id}/readonly-lab`, `protocol=routeros7-v1`, `operation` do enum, `request_key` UUID, `device_revision` e parâmetros tipados. CSRF nativo, nenhum campo secret. Formulários especializados só aparecem com gate LAB e flag local `device_readonly_lab.routeros_protocol=true`; default false. Resultados levam permanentemente LAB / SIMULADO; dados técnicos nas evidências fictícias. Pedidos normais não conectam. Ações explícitas: “Simule routeros-identity/bgp/route/interfaces/counters/resources no CCR”; rota dessa frase usa somente o destino demonstrativo 8.8.8.8. O painel aceita parâmetros tipados autorizados na atestação.

Conversation Engine reutiliza histórico/tom e fatos determinísticos. Telegram reutiliza a mesma identidade, autorização, supressão quando LAB é desligado, resultados e apresentação. OpenAI só escolhe explicações autorizadas; não gera CLI nem autorização. Nenhuma mensagem desta fase habilita acesso real.

## Fontes oficiais e alcance da validação

Consultadas em 2026-11-13. O manual corrente se identifica como 7.26; não é uma captura de um RouterOS 7.16.2. Templates de leitura e semântica foram confrontados com documentação oficial; fixtures são representações escritas para o simulador, **não capturas homologadas de hardware/CHR**. Campos fora do dialeto fechado falham com indisponibilidade controlada. Compatibilidade exata de console, firmware, direitos efetivos e performance em roteador real só poderá ser confirmada no procedimento futuro explicitamente autorizado.

- [Console](https://manual.mikrotik.com/docs/management-tools/console/) — paths e leitura.
- [Scripting / print](https://help.mikrotik.com/docs/spaces/ROS/pages/47579229/Scripting) — terse, count-only, stats-detail, without-paging e filtros where; não autoriza scripts no adaptador.
- [Sessões BGP](https://manual.mikrotik.com/docs/cli-reference/routing/bgp/session/) — campos read-only, Established, significado de remote.afi; clear/refresh/resend/stop fora do catálogo.
- [Rotas](https://manual.mikrotik.com/docs/cli-reference/routing/route/) — atributos, active, configured gateway/immediate gateway.
- [Policy Routing](https://help.mikrotik.com/docs/spaces/ROS/pages/59965508/Policy%2BRouting) — múltiplas tabelas e política.
- [Interfaces](https://manual.mikrotik.com/docs/cli-reference/interface/) — contadores/estados; limitações de aceleração.
- [Usuários e grupos](https://manual.mikrotik.com/docs/authentication-authorization-accounting/user/) — permissões de grupos e autenticação.

## Provisionamento manual futuro — não executado

Administrador responsável, em sessão independente do Agent: conferir versão/modelo exatos; criar grupo personalizado contendo somente `read,ssh`; confirmar direitos efetivos e ausência de herança/permissões adicionais. **Não utilizar grupo padrão read**, que pode incluir reboot/test/sniff/sensitive/password/API e outras permissões. Não solicitar admin/full. Criar usuário dedicado e importar sua chave pública aprovada, restringir origem conforme política já aprovada; guardar a chave privada no cofre tenant, nunca no inventário/interface/LLM. Obter fingerprint host por canal independente, comparar com autorização assinada. Administrador deve verificar acesso efetivo ao conjunto de consultas antes de qualquer conexão do Agent. Não foram criados usuários, grupos, chaves ou regras em roteador nesta missão.

## Homologação real futura — NÃO executar agora

Uma autorização operacional posterior deve identificar equipment_id, tenant, management_ip/porta, POP, modelo, **versão exata**, Agent e origem de rede, fingerprint aprovado, referência de credencial restrita/direitos atestados, seis capacidades ou subconjunto exato, parâmetros/tabela, janela UTC, máximo de sessões/consultas/bytes/tempo, evidências esperadas e responsáveis. Revalidar Preflight efetivo APPROVED, política atual, ACLs existentes e autorização específica do freeze imediatamente antes da única sessão. Nenhuma condição UNKNOWN/DENIED/STALE pode liberar. Esta entrega **não contém transporte operacional habilitável por simples flag**: endpoint compilado é loopback; o executor real e a integração operacional exigem revisão posterior própria, sem atualizar Agent por efeito colateral.

Interromper diante de identidade diferente, direitos excedentes/desconhecidos, firmware diferente, qualquer comando não aprovado, resposta sensível/malformada, limite, timeout, erro de transporte, revisão/política/janela alterada ou solicitação de escrita. Registrar códigos e evidências normalizadas, nunca console bruto/secrets. CHR real também exige autorização identificando a instância. Não interpretar cadastro no inventário ou esta documentação como autorização.

## Rollback e dados

Somente laboratório: desligar as flags LAB, revogar aprovação pelo Access existente, encerrar o runner (trap remove apenas seus containers/rede/volumes efêmeros). Reverter somente os arquivos/commits desta entrega em checkout isolado; preservar alterações locais anteriores. Não executar down em banco populado: a migration recusa remover aprovações existentes; manter a coluna adicional ao reverter código. Teste de down vazio e guard de down populado verificam comportamento. Inventário, histórico de simulações e auditoria permanecem preservados. Nenhuma restauração/rollback de produção é necessária, pois não houve publicação nem alteração de dados/serviços operacionais. Estado privado efêmero contém chaves e deve ser destruído pelo responsável após retenção de evidências sanitizadas.

Configuração remota não implementada. Evolução futura separada exigiria prévia, validação, backup, aprovação humana, janela, aplicação controlada, pós-validação e rollback, sem acrescentar essas ações ao catálogo de leitura.

## Evidências e resultados

Consultar `docs/evidence/mikrotik-readonly-v1/RESULTS.md` para comandos, contagens e limitações efetivamente verificadas. Categorias: A unitários; B protocolo SSH simulado; C CHR real **NÃO EXECUTADO, sem autorização**; D hardware de produção **FORA DO ESCOPO**.
