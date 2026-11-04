# Onboarding seguro de Agents

RECOVERY-7 substitui a emissão imediata da RECOVERY-2. A Central continua Laravel/PostgreSQL; Python é apenas Agent e tooling autorizado.

## Fluxo

1. Administrador seleciona tenant e cadastra nome em Agents. A Central cria registro `registered`, sem credencial utilizável, e código UUID + 256 bits aleatórios. Data de cadastro e geração registradas.
2. Código de instalação aparece uma única vez na resposta no-store, sem sessão/flash. Padrão 15 minutos, limite 60–1800 segundos, hash SHA-256 no PostgreSQL e lock transacional. Tenant vem da sessão, nunca do body.
3. Instalador 0.5.3 executa self-check e enrollment como usuário dedicado. Código vai em prompt oculto e POST HTTPS, nunca argumento, URL ou log.
4. `POST /api/v1/agent/enroll` consome o código, registra hostname, IP observado, fingerprint SHA-256 declarado de machine-id (se disponível), versão/capabilities e timestamps. Retorna HTTP 202, receipt temporário e `AWAITING_APPROVAL`; nenhum bearer permanente.
5. Receipt é privado (0600), só seu hash fica na Central. Código original não pode ser repetido. Sem aprovação, `enrollment-status` retorna somente estado. Receipt expira 24h após solicitação.
6. Administrador confere nome esperado, tenant, hostname, IP, fingerprint e data por canal independente. Fingerprint declarado não é atestado criptográfico; IP pode representar NAT/proxy. Não aprovar host desconhecido. Checkbox de revisão + **Confirmar vínculo**, Laravel Auth/Policy/CSRF e auditoria.
7. Agent retoma `--enroll`, sem pedir código novamente. Após aprovação, receipt permite retirar uma única credencial; token completo nunca é armazenado na Central. Perder essa resposta exige revogar e novo cadastro, nunca replay.
8. Serviço é iniciado explicitamente depois de obter identidade. Primeiro heartbeat registra `agent_ready`; painel acompanha heartbeat e poll, com freeze local e dados ausentes claramente marcados.

`registered → pending → active` indica autorização. `AGENT_READY` é um marco observado; presença atual é calculada separadamente. `revoked` bloqueia API e retirada de credenciais pendentes. Credencial emitida antes da RECOVERY-7 permanece válida até revogação; nenhum reenrollment durante update.

## Retomar instalação

Quando instalador encerrar com 4 (aprovação pendente), aprovar no painel e executar como root o helper do kit:

```sh
/opt/agent-center-agent/complete-enrollment.sh
```

O helper usa o receipt privado existente, inicia somente a unit deste Agent e aguarda até 60 segundos por ONLINE, heartbeat e poll. Nunca imprimir identity/config/receipt. Não reenviar código. Para enrollment irrecuperável, revogar Agent e cadastrar nova identidade.

## Proteções

Rate limit de origem, enrollment/status e Agent continua ativo. Row locks impedem consumo concorrente/replay. Queries de descoberta anônima usam apenas ID/hash exatos e limpam contexto em finally. Agents, enrollments e operações usam tenant scope, Policies e FORCE RLS; FKs compostas impedem referência ao Agent de outro tenant. Viewer/operator não aprovam. Eventos permanentes não incluem heartbeat, tokens, receipts ou hashes secretos.

Lista e detalhe usam polling leve de 10s; pausa com página oculta, formulário em foco ou confirmação aberta. Erro preserva última evidência e identifica consulta não atualizada. Expiração e solicitação são visíveis, sem revelar códigos existentes.
