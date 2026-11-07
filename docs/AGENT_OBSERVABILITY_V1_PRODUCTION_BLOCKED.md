# AGENT OBSERVABILITY V1 PRODUCTION BLOCKED

MOTIVO: o updater repetiu saídas com código 1 durante a observação, e o Agent
apresentou DEGRADED. Não foi obtida uma janela limpa de 15 minutos na VM.
Rollback autorizado concluído e verificado nos dois lados.

## Estado final

- Central: imagem `agent-center-central:access-maintenance-2a08663-20261107`,
  SHA256 `fc4e7485b3789afcc9a673acc289f2722a72d5cb4697328184eac72daa3e3f78`.
- Agent teste e helper MTR: **0.6.2**, identidade e todos os hashes originais
  de runtime, helper, companion, configuração e políticas restaurados/conservados.
- Journal atual íntegro: 87 execuções e 348 eventos; não foi substituído.
- Serviço, timer e socket ativos; Central observa Agent online.
- Login/readiness/liveness HTTPS 200, CSRF 419 sem token, redirecionamento HTTP 308.
  Containers saudáveis. Operations Health mantém **ATENÇÃO histórico**, sem NO-GO.
- Zero tarefa ativa, operação pendente, lease vencido ou fence anormal nos checks.
- Release 0.6.3/seq 4 preservada e **em quarentena**. Sequência histórica máxima 4
  mantida; nenhuma redução de ledger ou reoferta automática. Metadados do componente
  foram reconciliados com o MTR 0.6.2 realmente restaurado, com auditoria.

## Implantação tentada

Fonte aprovada: 40ed9b8, f0e77ab, 8a48eb6, manutenção 2a08663/f6401a0 preservada.
Candidata `agent-center-central:observability-v1-f0e77ab-20261107`.
Comparação integral entre imagens: somente dez arquivos homologados da Observability;
sem nova migration, alteração de worker, autenticação, RLS ou outra funcionalidade.

Release assinada Ed25519 pela autoridade existente, runtime
`08a81eab118e8f10ab5e57444aa4357ba9fe384deed4436a256eb797084cb480`, sequência 4.
Assinatura, hash, plataforma e anti-replay validados. Registro e atualização somente
pelo painel/Lifecycle existentes. Operação
`400199f4-e03d-45cd-8c41-a9d1511c508c`: **COMPLETED**, pós-check aprovado e MTR 0.6.3
operational_verified=true. Esse sucesso histórico não foi reescrito após a reversão.
A reversão posterior utilizou RuntimeAdapter/Components e o backup autenticado da
própria operação, sem instalar manualmente outro pacote ou trocar o journal.
Eventos de quarentena, rollback e verificação de recuperação foram auditados.

## Observação que bloqueou a entrega

A Central completou **900,882 segundos / 31 amostras**, sem violação, com Health
ATENÇÃO antes/depois. A primeira coleta local interrompida pelo ambiente (3 amostras)
não foi usada como janela completa; a coleta persistente completa foi preservada.

A primeira observação da VM completou 900 segundos, mas registrou falha do updater
às **18:41:55 UTC**, exit 1; a execução seguinte concluiu normalmente às 18:42:14 UTC.
O mesmo exit 1 já aparecia antes da publicação às 16:49:17, 17:51:19, 17:52:14,
18:10:38, 18:12:44 e 18:24:17 UTC, com o mesmo binário do companion.

Foi exigida uma nova janela, com critérios inalterados. Ela registrou **DEGRADED às
18:50:06 UTC**, nova ocorrência de erro e duas mensagens de erro acumuladas até a
amostra das 18:51:36 UTC. A tentativa final foi interrompida para rollback após
12 amostras (~330 s). Não se declara PASS dessa janela.

O log do companion não registra a causa específica do exit 1. Há evidência do
padrão anterior, mas isso não prova que toda ocorrência atual tenha a mesma causa;
não se atribui o problema à rede nem ao código 0.6.3 sem prova. Não se alterou o
Health para forçar VERDE nem se descartou a amostra DEGRADED.

## Validações aprovadas

- Cadastro existente revisão 2, until_revoked=true; tenant/destino, autorização
  administrativa, fingerprint antes da credencial e autenticação válidos.
- Backups locais restritos da Central (banco/schema/roles/configuração) e VM
  (identidade/token/runtime/journal/políticas/units/componentes/permissões).
  Restauração PostgreSQL isolada sem rede PASS; 31 tabelas FORCE RLS; SQLite
  consistente e bytes arquivados verificados. Secrets ficaram fora desta entrega/Git.
- Gates admin/operador e RLS entre tenants, runtime sem superuser/BYPASSRLS,
  CSRF/HTTPS e páginas autenticadas aprovados. Nenhum privilégio persistente alterado.
- Sete capabilities instaladas/relatadas, políticas/revisão efetivas, versões e
  helper saudável no painel real durante a tentativa. 14 comparações nativas com
  o preflight do executor, zero chamada de handlers de sondagem.
- Consulta MTR: DENIED / POLICY_BASE_DENIED. Agent info: ALLOWED, sem executar
  capability. Estados de saúde não medidos permanecem UNKNOWN/INDISPONÍVEL;
  não são classificados como falha de rede. TTL de 60 s respeitado.
- Seis screenshots 390/900/1440 claro/escuro e captura adicional ALLOWED,
  com dados reais, sem fixtures, overflow de página ou erros JS. Capturas da
  tentativa 0.6.3 estão identificadas; capturas de rollback mostram o estado final.
- 268 testes Laravel / 2035 assertions e 149 Python são os resultados homologados
  anteriores, não uma reexecução nesta rodada. Nesta rodada foram executadas as
  validações operacionais, de segurança, restauração e navegador acima.

## Rollback e riscos residuais

Rollback Central verificado; banco, nginx e workers não foram recriados nem
restaurados sobre alterações posteriores. Rollback conjunto nativo do Agent
verificado, com journal atual preservado e todos os hashes originais iguais.
Backups root-only: `/root/observability-v1-production/secure/central` e
`/var/backups/agent-center-observability-v1-20261107` na VM teste.

Pendência real: diagnosticar a indisponibilidade episódica do companion/Agent
antes de qualquer nova liberação. A 0.6.3 não permanece ativa em produção.
O catálogo preserva a sequência 4 e sua quarentena; não reutilizar/resetar a
sequência para contornar anti-replay. Nenhuma nova publicação foi iniciada.

R2, equipamentos, Skills, arquitetura, políticas e privilégios operacionais
preservados. Nenhum tcpdump/Nmap. Coletores temporários encerrados; evidências
preservadas. Trabalho encerrado com bloqueio, sem iniciar outra fase.
