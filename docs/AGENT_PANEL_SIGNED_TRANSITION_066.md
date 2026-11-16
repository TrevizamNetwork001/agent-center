# Atualização assinada 0.6.6 pelo painel

O companion operacional 0.6.5 aceita somente arquivos Python no zipapp. O pacote
SSH homologado contém wheels. Instalar apenas o runtime não atualizava o decoder
que recebe a próxima release. A homologação anterior usava um supervisor de
laboratório para o bootstrap e, portanto, não comprovava a atualização autônoma
do companion instalado na VM. Essa era a causa concreta da incompatibilidade.

## Correção

Uma solicitação autorizada no painel usa duas operações do Lifecycle existente:

1. Pacote de transição Python 0.6.6, com manifesto legado, aceito pelo companion
   0.6.5. A transação existente instala runtime e companions e preserva backups.
2. Pacote final 0.6.6, sequência maior, com wheels fixados. O manifesto assinado
   contém `runtime_package_protocol: 2` e `bootstrap_sha256`. A Central só o
   entrega após o companion anunciar o protocolo compatível.

Não há novo updater, instalação manual por SSH, pip operacional ou chave recebida
do manifesto. O timer systemd existente autentica, baixa e instala por HTTPS.
O pacote intermediário é ocultado do seletor normal. Revisões da mesma versão
são oferecidas pela sequência assinada. A aprovação seguinte mantém tenant,
autor, Gate, validade de quinze minutos e quarentena. Não anunciar o protocolo
em cinco minutos encerra a espera com erro explícito. Campos de transição são
exclusivamente da Central; o Agent não pode escrevê-los no relatório.

O helper MTR 0.6.5 permanece intacto. Ao receber a resposta legada exata de
operação desconhecida, a observação consulta somente `ready` pelo socket local.
Isso permite reportar sua saúde sem inventar visibilidade das políticas: a
capacidade detalhada permanece UNKNOWN/UNSUPPORTED_VERSION. Nenhuma sondagem é
executada por essa compatibilidade e o Preflight original é preservado.

## Segurança e limites

Assinaturas Ed25519, hashes, sequência monotônica, FORCE RLS, RBAC, CSRF,
autenticação, leases e isolamento por tenant continuam obrigatórios. Pacote
inválido, quarentena, conflito ou autorização expirada continuam causando bloqueio
legítimo. O ajuste elimina a dependência de SSH na atualização; não remove os
controles de segurança nem promete atualizar um Agent sem conectividade HTTPS.

Não há habilitação de dispositivos, novas ACLs, credenciais de equipamentos,
transportes simulados em produção ou mudanças em R2. O freeze continua ativo.

## Rollback

Cada etapa preserva seu próprio backup e reverte automaticamente se o postcheck
falhar. Falha no pacote final retorna à transição 0.6.6 saudável, com o companion
novo e sem as dependências SSH. Isso não equivale ao retorno de toda a cadeia
para 0.6.5. O backup original da primeira operação é mantido.

O retorno completo pelo painel usa o código original 0.6.5, reempacotado e assinado
pela autoridade operacional com sequência superior à instalada. Cada membro do
zip original é preservado; somente um comentário de restauração distingue o
digest no catálogo, cuja chave é única por tenant. Os companions derivados são
idênticos aos originais. É uma nova
transação autorizada do Lifecycle; não se rebobina o contador antirreplay nem se
restaura um journal antigo. O pacote sem wheels remove o runtime SSH do caminho
ativo e restaura os companions derivados do artefato original. Backups auditáveis
permanecem fora do caminho ativo. Não usar o rollback legado isolado de runtime
como substituto da transação conjunta.

O rollback das imagens da Central utiliza os arquivos compose anteriores,
omitindo `deploy/compose.panel-updater.yaml`, e recria somente web, worker,
telegram-scheduler e diagnostic-worker, com `--no-deps --no-build`. Não há migration.
Releases publicadas, operações e auditoria são preservadas. Nunca restaurar o
dump PostgreSQL por rotina: ele é backup de emergência, não rollback de código.

## Validação

O teste `PanelNativeLifecycleTest` usa Debian 13 com systemd real e Agent não-root.
Parte dos bytes operacionais originais 0.6.5 e executa os próprios timers e
entrypoints remotos. O provisionamento apenas prepara o laboratório; não instala
as releases. O tráfego HTTPS é terminado por nginx isolado com CA de laboratório,
sem desativar a validação TLS. O servidor SSH simulado conta conexões para provar
que a atualização não o utiliza. Os testes de falha e restauração são executados
em ambientes separados.

Evidências finais e publicação: ver relatório de rollout desta alteração.
