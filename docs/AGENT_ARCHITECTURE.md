# Agent 0.5.2 — reconstrução RECOVERY-2

Browser → Nginx → Laravel Central → PostgreSQL. Agent Python é um cliente HTTP separado da Central, sem servidor web, FastAPI ou bridge. A direção de transporte desta fase é Agent → Central (enroll/heartbeat/poll); a Central entrega policy/respostas. Nenhum device é acessado, nenhuma tarefa operacional é criada e nenhum self-update remoto existe.

A infraestrutura/código antigos foram perdidos. Reconstruímos a versão 0.5.2 pelos nomes de endpoints e invariantes fornecidos pelo proprietário, não por arquivos antigos. Não há evidência para afirmar compatibilidade byte a byte de payloads antigos. O contrato novo e explícito está em AGENT_API.md e é exercitado pelo próprio cliente Python contra Laravel.

## Processos e isolamento temporal

Um processo não root, com três threads normais: loop principal/poll, heartbeat e saída de logs. Heartbeat possui Client/HTTPSHandler/contexto TLS próprios. Não adquire lock do journal nem grava status/identity em disco. Poll, persistência e recuperação ficam no loop principal; logs usam fila limitada e inserção não bloqueante. HTTP não usa proxy do ambiente nem segue redirects.

Padrões: heartbeat 20 s, poll 5 s, timeout HTTP 5 s, até uma repetição com backoff curto para operações idempotentes. Poll falho usa backoff exponencial até 60 s e mantém heartbeat independente. Intervalo de heartbeat aceito em config é 1–30 s; meta normal de gap de sucesso <60 s. Não se promete sucesso durante indisponibilidade da Central/rede. 401/403 levam a QUARANTINED e saída controlada 3, sem loop agressivo de reinício pelo systemd.

State machine explícita: BOOT → CONFIG → IDENTITY → RECOVERY → CAPABILITIES → CLAIM → ONLINE/DEGRADED. Ciclos ONLINE/DEGRADED → CLAIM continuam independentes do heartbeat. START → RENEW → EXECUTE → RESULT → RECONCILE → IDLE são transições preparadas e não entram em execução remota nesta fase: policy tasks_enabled=false e claim retorna task=null. STOPPED/QUARANTINED encerram de forma controlada.

## State e journal

Config fora do código, padrão `/etc/agent-center-agent/config.json`; contém referência para identity_file. Agent ID e token ficam juntos no identity file privado, para evitar divergência. State padrão `/var/lib/agent-center-agent`; logs padrão `/var/log/agent-center-agent`. Os diretórios de state/identity são privados, arquivos 0600. Não passar token/código por argumentos de processo.

JSON usa tempfile no mesmo filesystem, flush, fsync do arquivo, rename e fsync do diretório. Erros preservam o arquivo anterior; JSON inválido não é silenciosamente substituído. Instance lock exclusivo impede dois processos no mesmo state_dir. Enrollment grava identidade sem sobrescrever uma identidade existente. Código de enrollment é lido por prompt oculto, enviado uma única vez e não é automaticamente repetido em falha ambígua.

Journal SQLite, schema 1, WAL e synchronous=FULL. A tabela de eventos possui sequência monotônica; execution_id é chave única. Reserva e transições são transacionais. `run_once` persiste executing antes de chamar ação e grava resultado limitado junto com result_pending. Reabertura preserva resultado, sem executar de novo. Executing encontrado em recovery vira uncertain; resultado incerto jamais é reexecutado por replay. Reconcile só solicita orientação e nesta fase recebe hold. At-most-once é a escolha deliberada: uma interrupção pode impedir uma execução, mas não autoriza repetir uma ação de resultado desconhecido.

Resultados do journal são dados técnicos locais, não logs. Nenhum token/código/Authorization deve ser payload de journal. Config, identity, journal e status não são trocados pelo updater; rollback restaura somente código. Schema futuro incompatível deve ser recusado/revisado, nunca resolvido restaurando state antigo sobre o atual.

## Capabilities verificáveis

| Nome conhecido | Implementação desta reconstrução |
|---|---|
| agent_info | versão, hostname e Python; freeze=true |
| icmp_ping:v1 | um ping, alvo IP em CIDR autorizado; binário ping necessário; timeout e args fixos |
| dns_lookup:v1 | reverse lookup de IP autorizado; não é promessa do payload histórico de forward DNS |
| tcp_connect:v1 | conexão curta a IP/porta autorizados; sem envio de comando |
| traceroute:v1 | traceroute numérico limitado a cinco hops; binário necessário; sem MTR avançado |
| http_probe:v1 | HEAD HTTPS com verificação TLS; HTTP somente loopback; IP em allowlist, sem redirects/credenciais |

Default allowed_networks=[]: probes de rede são negados. Não há execução remota de probes no loop desta fase. Testes usam apenas loopback/mocks. Self-check anuncia exatamente handlers conhecidos disponíveis; ping/traceroute só aparecem se seus binários existirem. No laboratório foram anunciadas quatro capabilities (agent_info, dns_lookup:v1, tcp_connect:v1, http_probe:v1). As outras duas estão preparadas, sem instalar binários no host ou fingir disponibilidade.

O piloto anterior tinha 14 capabilities, mas apenas seis nomes foram fornecidos. Os oito nomes restantes continuam como lacuna de roadmap, sem identificadores inventados, anúncio ou handlers fictícios. Self-check é offline: valida implementação/pré-requisitos; não significa que um destino esteja alcançável.

## Freeze e policy

DEVICE_OPERATIONS_FROZEN=1 no systemd. Config false é recusada; policy não pode liberar device operations. Todo nome device_* (inclusive desconhecido/futuro) e todo nome fora da allowlist é rejeitado. Não há shell=True, eval, import dinâmico de action, SSH ou acesso Huawei.

Policy schema_version=1 exige agent_id correspondente. Campos ausentes/invalidamente tipados são tratados de forma controlada e com defaults restritivos. Allowed capabilities é intersectado com o conjunto local; nenhum campo arbitrário vira comando. tasks_enabled=true não é aceito por este runtime de fase 2. Policies inválidas causam modo degradado/negação, não exceção que derrube o processo.

## Segurança e limites

Bearer é aleatório de 256 bits, com UUID identificador; Central guarda apenas SHA-256 do valor completo. TLS obrigatório fora de loopback; logs de Agent só aceitam nomes fixos de eventos e não interpolam exceptions/body/headers. Identity repr não inclui token. Rede indisponível, JSON inválido e status HTTP adversos viram Outcomes, não tracebacks no processo principal.

Unit hardened com usuário dedicado, NoNewPrivileges, filesystem somente leitura exceto State/Logs/RuntimeDirectory, PrivateTmp, proteção de kernel e capabilities vazias. Isso não torna o código seguro contra root/comprometimento do próprio usuário de serviço. Chaves/config/state/backup precisam continuar restritos ao operador.

Referências: [Python sqlite3](https://docs.python.org/3.13/library/sqlite3.html), [PostgreSQL RLS](https://www.postgresql.org/docs/17/ddl-rowsecurity.html), [systemd.exec](https://www.freedesktop.org/software/systemd/man/latest/systemd.exec.html).
