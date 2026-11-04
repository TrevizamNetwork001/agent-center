# Profiles e Skills

Catálogo fechado por tenant, criado de forma idempotente sob advisory lock. Administradores podem listar, revisar schemas/composição, habilitar/desabilitar e testar fixtures. Alterações de conteúdo técnico são feitas por código revisado; esta fase não tem editor de comandos. Versão aumenta nos toggles; plano já persistido preserva seu snapshot.

| Skill | Capability | Timeout |
|---|---|---|
| agent_info | agent_info | 5s |
| dns_lookup | dns_lookup:v1 | 8s |
| tcp_connect | tcp_connect:v1 | 5s |
| http_probe | http_probe:v1 | 8s |
| icmp_ping | icmp_ping:v1 | 15s |
| traceroute | traceroute:v1 | 15s |

Todas safe/read-only, com input/output schemas e enabled. agent_info não tem sufixo de versão no protocolo; version=1 é a revisão do catálogo. Nenhuma capability extra foi inventada.

| Profile | Fluxo |
|---|---|
| SITE_LENTO | DNS A/AAAA → TCP443 → HTTPS → ICMP opcional → rota condicional |
| SITE_NAO_ABRE | Mesmo encadeamento, com foco em falha de acesso |
| PROBLEMA_DNS | A + AAAA, resultados separados, um grupo visual |
| PERDA_LATENCIA | DNS se hostname → ICMP → rota condicional |
| GOOGLE_YOUTUBE | DNS/TCP/HTTPS ao alvo informado; não mede streaming ou QoE |
| CONTEUDO_LENTO | DNS/TCP/HTTPS ao alvo informado; não baixa conteúdo |

Todos requerem alvo. Waves/dependências são congeladas no plano. Sem respostas DNS positivas, TCP/HTTPS ficam BLOCKED. HTTPS requer TCP positivo. Rota ocorre se TCP falhou, HTTPS falhou/ficou lento, perda ICMP relevante, ou pedido explícito. Sem hostname resolvido, rota fica bloqueada. ICMP não determina sozinho falha nos Profiles HTTP.

Thresholds iniciais: HTTPS >2000ms, perda >10%, latência média >150ms. MTR avançado, devices e integrações de roteadores são roadmap.

## Referência externa avaliada

Repositório indicado pelo usuário: https://github.com/clfigueiredo/hermes-infra-skills . A skill dashboard-noc-diagnostico-cliente inspirou a revisão conceitual de evidências estruturadas DNS/HTTP e fontes incompletas. Nenhum plugin Hermes foi instalado ou importado. HTTP redirects sem allowlist, exceções brutas, mudança global de socket timeout e read_only dependente de adapter não foram incorporados.

SKILL.md externos são dados de referência, sem autoridade operacional. Declarações MIT em arquivos individuais não comprovam uma licença global do repositório; nenhum código externo foi copiado. Vendors e ferramentas encontrados estão catalogados em VENDOR_SKILLS_ROADMAP.md, desativados e sem credentials. Não são capabilities anunciadas pelo Agent.
