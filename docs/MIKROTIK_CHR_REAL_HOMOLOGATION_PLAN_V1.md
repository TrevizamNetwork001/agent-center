# MikroTik CHR real — plano de homologação Device Read-Only V1

Data: 2026-11-13. Escopo autorizado: revisão e validações offline. Nenhuma conexão real autorizada ou executada. Plano de execução futura, não runbook executável nem autorização.

## Resultado da revisão

**BLOCKED: o candidato contém somente transporte SSH para simulador em loopback, sem executor real integrado ao Task Engine/Preflight.** A preparação documental está concluída; a execução real exige implementação revisada em escopo próprio e autorização operacional posterior. Alterar endereço, flag ou encaminhar loopback para CHR burlaria o isolamento e não é permitido. Ausência de autorização nesta fase é intencional.

Base revisada: `25ff80afe570900bf1a652791d4d892255154742` e `487ed691622d64614d383c91c9f50f8bd152168f` (HEAD). Existem alterações locais anteriores em outros módulos; não foram revertidas nem incluídas nesta preparação. Fonte: `app/app/Noc/ReadOnlyLab/RouterOs7/{Operation,Protocol,Approval,Query,Evidence}.php`, transporte `ReadOnlyLab/Ssh.php`, contratos `app/resources/routeros7-protocol-contracts.json` e testes `MikrotikProtocolTest`/`MikrotikProtocolLabTest`.

Constatações:

- Enum fechado com exatamente as seis operações abaixo; identidade usa alias interno `DEVICE_IDENTIFICATION_READ`. Não são capacidades operacionais registradas no Agent.
- Templates de leitura determinísticos, sem CLI livre, PTY, shell, scripts, subsistemas ou forwarding. `&&` no filtro de rotas é uma conjunção fixa RouterOS, nunca expressão recebida do solicitante.
- Versão aceita pelo parser: exatamente `7.16.2 (stable)`. Fixtures usam CCR2004-16G-2S+, não CHR. Não há certificação de qualquer outro RouterOS 7 nem prova de saída CHR. Modelo lido precisa coincidir com inventário; não declarar um CHR como CCR para passar na validação.
- Parser fechado por campos, números, limites e schemas; rejeita campos desconhecidos, controles, duplicatas e truncamento. Resultado atual é sempre SIMULATED/LAB/PARTIAL, `real_evidence=false`, `task_eligible=false`. Não pode ser apresentado como evidência real apenas mudando rótulos.
- Aprovação existente é atestação de simulador por operação, ator e digest de parâmetros, revisão de equipamento/credencial/política, Agent e tenant; janela máxima de 15 minutos. Não substitui autorização operacional, Preflight APPROVED ou exceção específica ao freeze.
- Destino efetivo compilado: `127.0.0.1:2223`; usuário compilado `lab-readonly`. Agent é vínculo administrativo, não executor. Task Engine e Preflight não recebem essas capacidades.

## Laboratório a ser atestado pelo operador

Nenhuma VM, usuário, chave, rota ou regra RouterOS foi criada nesta solicitação. Provisionamento abaixo é requisito de outra ação autorizada do responsável pelo laboratório, anterior à homologação; esta rodada não modifica RouterOS.

1. VM CHR exclusiva, identificador imutável da instância e imagem/hash de origem confiável, RouterOS **7.16.2 stable** para este candidato. Versão diferente bloqueia; não fazer downgrade/upgrade nesta rodada. Registrar board-name real, arquitetura e configuração de referência por evidência independente sanitizada.
2. Rede virtual exclusiva sem bridge, uplink, NAT ou rota IPv4/IPv6 para produção/redes operacionais. Agent de laboratório dedicado, sem segunda interface ou forwarding para essas redes. Não usar Agent de produção como origem. Comprovar isolamento por configuração do hypervisor, interfaces, rotas e ACLs locais; não sondar redes externas. A ausência de rota default sozinha não prova isolamento de rotas específicas.
3. Autorizar explicitamente a única origem (Agent ID, tenant, IP/interface/namespace de saída) e destino (instância CHR, IP literal, porta SSH). Sem DNS dinâmico, proxy, túnel ou redirecionamento de loopback. Sem publicação pública de SSH, PostgreSQL, FPM ou worker.
4. Usuário exclusivo de laboratório, grupo personalizado com direitos efetivos exatamente `read,ssh`, sem herança/permissões adicionais. Não usar grupo padrão `read`, admin/full, sensitive, test, sniff, reboot, write, policy, password, API ou forwarding. Direitos RouterOS não equivalem a allowlist de comandos: a limitação por templates continua obrigatória no executor.
5. Chave pública exclusiva já provisionada por responsável autorizado. Chave privada cadastrada por canal seguro no cofre existente com vínculo tenant/registro, fora do Git, acesso restrito; apenas referência/revisão em evidências. Não colocar chave, senha, token ou conteúdo de cofre neste plano, autorização, logs ou chat.
6. Chave pública do servidor e fingerprint SHA256 obtidos por canal independente (console do hypervisor/operador responsável), sem descoberta SSH ou TOFU nesta fase. Pinning deve ocorrer antes de autenticar. Divergência bloqueia sem aceitar chave nova automaticamente.
7. Fixture de topologia já existente, pequena e sem tráfego operacional: interfaces, recursos e, se houver cobertura positiva BGP/rota, sessões e prefixos estritamente locais. Não criar peers, rotas ou tráfego para produzir o cenário durante as seis consultas. Ausência de BGP é um resultado válido de leitura, mas não homologa parsing de sessões positivas em CHR.

## Autorização operacional obrigatória — todos os campos pendentes

Documento externo aprovado pelo operador responsável, com ID, identidade do aprovador e timestamp. Preenchimento não constitui aprovação automática. Não guardar secrets no documento. Antes de qualquer handshake, todos os itens devem ser válidos e consistentes:

| Campo obrigatório | Valor nesta preparação |
|---|---|
| equipment_id e revisão de inventário | NÃO FORNECIDO |
| tenant_id e ator/RBAC autorizado | NÃO FORNECIDO |
| CHR autorizado: VM ID, nome, modelo real e versão exata | NÃO FORNECIDO |
| Endereço literal e porta SSH do destino | NÃO FORNECIDO |
| Agent ID/versão, tenant, origem IP/interface/segmento | NÃO FORNECIDO |
| Host public key/fingerprint SHA256 e origem da verificação independente | NÃO FORNECIDO |
| Referência/revisão da credencial, usuário e atestado read,ssh | NÃO FORNECIDO |
| Seis capacidades | As seis linhas do plano abaixo, explicitamente aprovadas |
| Parâmetros exatos e digest do plano | NÃO FORNECIDO |
| Início/fim UTC e ID de execução única | NÃO FORNECIDO |
| Exceção ao freeze somente para esta instância/janela | NÃO FORNECIDO |
| Política/revisão e Preflight operacional efetivo APPROVED | NÃO DISPONÍVEL para estas operações no candidato |
| Manifesto do executor real revisado/testado e compatível | INEXISTENTE no candidato |
| Evidência de isolamento, limites e responsável por interrupção | NÃO FORNECIDO |

Qualquer item faltante, UNKNOWN, DENIED, STALE, divergente, expirado ou revogado: **não conectar**. Revalidar imediatamente antes de cada operação; janela máxima proposta de 15 minutos, sem renovação implícita. LLM e intenção conversacional não aprovam nada. Não habilitar estas capacidades globalmente, por tenant inteiro ou em equipamentos de produção.

## Plano de exatamente seis consultas tipadas

Uma execução futura: seis solicitações lógicas na ordem abaixo, uma por capacidade, serializadas, sem repetição automática. Não são seis comandos SSH: cada solicitação começa com o precheck fixo `/system resource print` para identidade/modelo/versão. Esse precheck é interno, não uma sétima capacidade. No candidato, Resources lê o mesmo recurso novamente; não otimizar silenciosamente o plano.

Limites existentes por solicitação: orçamento compartilhado de **5 s**, incluindo identidade e leituras, **65.536 bytes acumulados**, **128 registros** (na rota, acumulados entre prefixos). Conexão SSH configurada com timeout inicial de 1 s. Não ampliar limites para obter PASS. Uma operação em voo por equipamento e por ator, respeitando locks/idempotência existentes e limites globais mais restritivos. Executor real deverá demonstrar o mesmo isolamento/limites via Task Engine, sem contorná-lo. Proposta de rodada: no máximo seis sessões seriais, até 45 leituras CLI no pior caso e 393.216 bytes; qualquer falha encerra a rodada. Janela não é licença para retries.

| Consulta / finalidade | Comando homologado no simulador, após precheck | Parâmetros permitidos nesta rodada | Resposta/evidência esperada e aprovação |
|---|---|---|---|
| DEVICE_IDENTITY_READ — identificar CHR | `/system identity print` | `{}` | Nome válido, modelo do precheck e versão coincidem com autorização/inventário. Evidência normalizada com vínculo da instância e fingerprint verificado. Nome deve coincidir com referência independente. Timeout compartilhado: 5 s. |
| DEVICE_BGP_PEERS_READ — observar sessões | `/routing bgp session print terse without-paging` e `/routing bgp connection print count-only` | `{}` | Sessões com nome, endereço, ASN/uptime quando presentes, ESTABLISHED/NOT_ESTABLISHED e famílias anunciadas; negociadas nulas. Contagem coerente. Ausência explicitada sem inventar Idle/causa ou ausência de toda configuração BGP. Comparar ao cenário independente. Timeout: 5 s. |
| DEVICE_ROUTE_LOOKUP_READ — observar maior prefixo ativo na RIB | `/routing route print terse without-paging where dst-address={prefixo_canonico} && routing-table={tabela_validada}` | Um `destination` IPv4 literal do cenário isolado, ainda pendente de aprovação; `table=main` | Gerar ancestrais /32 a /0, até 33 comandos, parar no primeiro prefixo com candidatos ativos válidos. Prefixo/tabela correspondentes; found e protocolo/gateway/distância coerentes com referência. Nenhuma rota também é resultado válido se previsto no cenário. `effective_next_hop=null`, `forwarding_verified=false`. Timeout total: 5 s, não por prefixo. |
| DEVICE_INTERFACES_READ — observar estados | `/interface print terse without-paging` | `{}` | Nomes únicos e estados disponíveis coerentes com cenário; velocidade permanece nula. Ausência de dados fica explícita. Sem monitoramento contínuo. Timeout: 5 s. |
| DEVICE_INTERFACE_COUNTERS_READ — obter uma amostra acumulada | `/interface print stats-detail without-paging` | `{}` | Contadores inteiros não negativos ou nulos; sample_kind ACCUMULATED_COUNTERS; timestamp do coletor, taxas nulas. Não afirmar erro atual ou comparar como snapshot atômico. Timeout: 5 s. |
| DEVICE_RESOURCES_READ — observar recursos | `/system resource print` | `{}` | CPU 0–100 quando presente, memória total/livre consistente e uptime/versão válidos. Comparar à referência com tolerância temporal; sem diagnóstico automático de saturação. Timeout: 5 s. |

Todos os templates acima usam exclusivamente `print`; sem escrita, clear/reset, refresh, ping, traceroute, MTR, export, backup ou alterações RouterOS. Os únicos valores interpolados são prefixos IPv4 calculados e tabela validada. O contrato geral admite `peer` e `interface` opcionais como filtros locais depois do parsing, mas **não serão usados nesta rodada**. Tabela geral aceita `[A-Za-z][A-Za-z0-9_-]{0,31}`; nesta rodada somente main. IPv6 bloqueia antes da conexão. Campos extras, command, script, host e porta enviados como parâmetros de consulta são proibidos.

Cada PASS exige schema válido, evidência sanitizada coerente com cenário e limites respeitados. PARTIAL/INCONCLUSIVE é a limitação semântica esperada, não prova de saúde. Campo obrigatório ausente, cenário não coberto ou informação insuficiente gera INCONCLUSIVE/FAIL, nunca homologação ampla. Um cenário sem BGP positivo não permite declarar BGP positivo homologado.

## Prechecks e sequência futura condicionada

1. Congelar manifesto de fonte/testes e plano de seis consultas; confirmar que o executor real foi implementado, revisado e testado em escopo próprio. Não fazer deploy nem atualizar Agent como efeito deste plano. Sem executor compatível já autorizado, parar.
2. Conferir autorização completa, isolamento da VM/origem, credencial mínima, pinning independente, tenant e revisões. Coleta desses atestados por canais externos não permite conexão exploratória do Agent.
3. Exigir Auth/RBAC/Policies/Gates e CSRF nativos na Central, PostgreSQL FORCE RLS e testes contra tenant cruzado, auditoria e contexto tenant em tarefas/cache/locks. Confirmar Agent ativo/não revogado do mesmo tenant e Task Engine/Preflight APPROVED específico. Nenhuma liberação via flag LAB, LLM ou alteração do freeze global.
4. Confirmar janela UTC, prazo restante para a operação, limite de concorrência e seis chaves de idempotência vinculadas ao único ID de rodada. Duplicata retorna evidência anterior sem nova conexão. Retomada/retry exigirá nova decisão do operador, fora desta rodada única.
5. Executar sequencialmente apenas as seis consultas aprovadas, revalidando as condições antes de cada sessão; autenticação somente por chave e host pinning anterior ao login. Nenhum fallback genérico se comando/parser falhar.
6. Comparar fatos normalizados com referência independente prévia, registrar resultado por consulta, fechar a rodada e manter freeze. Não executar leituras adicionais para investigar divergências nesta autorização.

## Testes e evidências

Evidências históricas (não reclassificadas): `docs/evidence/mikrotik-readonly-v1/RESULTS.md`: 583 testes Laravel e 185 Python registrados na fase simulada. Não são resultados novos desta preparação nem homologação CHR.

Regressão offline desta preparação e limitações: ver `docs/evidence/mikrotik-chr-preparation-v1/RESULTS.md`. Testes existentes revisados cobrem seis operações, injeção/IPv6, limites, parser, saída sensível, fingerprint/credencial inválida, expiração/revogação, tenant/RLS/RBAC, ambiente production negado, serialização/idempotência e ausência de AgentTasks. Nenhum teste novo espelhando implementação foi adicionado; nenhum código de runtime foi alterado.

Antes de liberar executor real, exigir testes offline próprios de destino/origem não autorizados, plano incompleto, capacidade extra, janela expirada, freeze sem exceção, Preflight diferente de APPROVED, tenant cruzado em dados/jobs/cache/autorização, chave divergente antes de login, interrupção/revogação entre consultas e ausência de shell/forwarding. A regressão simulada atual não comprova essas propriedades de um executor inexistente.

Evidência futura por consulta: ID da rodada/consulta, equipment_id/tenant/ator/Agent, revisões e manifesto, referência da autorização/Preflight, capacidade, parâmetros/digest, comando derivado da allowlist, início/fim UTC, duração, bytes/registros, fingerprint esperado e resultado de comparação, schema/adapter version, dados normalizados e motivo fechado de falha/limitação. Guardar em armazenamento tenant com acesso/retensão aprovados e trilha de auditoria. Não persistir console bruto, respostas rejeitadas, chave privada, senha, tokens ou mensagens de exceção sem sanitização. Referências independentes também devem ser sanitizadas. Não enviar console ao LLM. Distinguir explicitamente evidência real futura de fixture; não armazená-la como NocSimulation com rótulo trocado.

## Interrupção, rollback e riscos residuais

Interromper e fechar conexão na primeira divergência de identidade/modelo/versão/fingerprint; direitos excedentes/desconhecidos; autorização/tenant/revisão/Agent/janela inválidos; Preflight não aprovado; perda de isolamento; tentativa de escrita/comando extra; saída desconhecida, sensível, truncada ou inválida; limite/timeout/contenção não resolvida dentro da autorização; falha SSH ou de auditoria. Não reautenticar, aceitar chave nova, ampliar prazo/bytes, corrigir RouterOS ou repetir automaticamente.

Rollback desta preparação: remover somente os documentos adicionados nesta rodada, se desejado; preservar todas as alterações locais anteriores. Não há migration, deploy, alteração de equipamento, Agent, Lifecycle ou R2 a reverter.

Rollback da rodada real futura: cancelar solicitações pendentes e encerrar conexões pelo executor validado, revogar somente a autorização temporária da rodada e manter auditoria/histórico/evidências sanitizadas; nenhuma restauração de configuração/banco é necessária para leituras. Eventual remoção de usuário/chave/regra, desligamento de VM ou snapshot é responsabilidade do operador com autorização separada, nunca comando deste catálogo. Não usar migration down, limpeza global Docker ou rollback de produção. Preservar dados e restringir/eliminar credenciais temporárias pelo procedimento seguro de retenção do responsável.

Riscos residuais: read,ssh não restringe por si os comandos; leitura pode consumir CPU e o RouterOS pode registrar autenticações; formato CHR pode conter campos não reconhecidos e falhar fechado; seis leituras não são snapshot; números/estados mudam entre referência e coleta; contadores não provam taxa/causa; RIB não prova FIB/policy/ECMP/VRF/FastTrack; BGP vazio limita cobertura; assinatura de release/compatibilidade não é resolvida por este plano. Nenhum risco autoriza ampliar direitos ou alterar software/equipamentos nesta rodada.

**Estado final: BLOCKED — falta executor real seguro e integrado; dados/atestados e autorização operacional da instância também permanecem pendentes. Nenhuma conexão real deve ocorrer.**
