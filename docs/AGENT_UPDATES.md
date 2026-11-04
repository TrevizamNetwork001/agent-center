# Atualizações, backups e rollback do Agent

## Limite de autoridade

Painel cria intenções estruturadas `update`/`rollback`; não aceita scripts, shell, caminhos, URLs de download ou upload executável. Só administradores do tenant podem solicitar/aprovar. Releases são publicadas por operador em `app/resources/agent-releases.json`, em checkout auditado; não há cadastro HTTP arbitrário.

Runtime reconstruído inicial: **0.5.2** no commit ff168b9. Nova implementação: **0.5.3**. Não se atribuem capabilities da VPS perdida. O catálogo só publica artefatos realmente construídos, com SHA-256, arquivo, compatibilidade Linux/CPython 3.13, data, status, changelog, Git e capabilities testadas. Build de zipapps é determinístico. Ping/traceroute dependem de ferramentas explicitamente provisionadas; não são instaladas por este kit nem declaradas disponíveis sem detecção.

## Provisionamento de release

Kit offline: `python3 agent/packaging/build-kit.py /diretorio/novo`, a partir de Agent commitado. Manifesto e seu SHA precisam chegar ao operador por canal confiável independente. SHA protege integridade, não autentica publicador desconhecido.

O instalador disponibiliza a release inicial em `/opt/agent-center-agent/releases/<sha>.pyz` e `catalog.json`, controlados por root. Novas releases exigem staging explícito em host autorizado, usando `agent/packaging/stage-release.py` de checkout confiável com `PYTHONPATH=agent/src`, `--artifact`, `--sha256` e `--version`. Não baixa conteúdo, não altera serviço e não aplica update. Catálogo da Central e catálogo root local devem concordar. Código/updater/catalog/release não podem ser graváveis pelo usuário do runtime.

## Protocolo administrativo

1. Selecionar release, operação e motivo fechado. Intenção `PRECHECK_REQUESTED`, auditoria e snapshot da release/versão instalada.
2. Companion de atualização do Agent solicita intenção autenticada, passa a `PRECHECKING`. Apenas release previamente instalada no catálogo root local pode ser avaliada. SHA, estrutura do pacote, schema, versão e freeze são conferidos em self-check com privilégios reduzidos.
3. Plano `PLANNED` mostra compatibilidade/release, hash e evidências. Nenhuma parada/swap ocorreu. Plano expira para aprovação em 15 minutos.
4. Administrador marca revisão e confirma. Estado `APPROVED`, timestamp e usuário. Não existe atualização automática ao detectar nova versão.
5. Companion só retira apply se autorização tem menos de 15 minutos e versão atual ainda coincide com origem. Não há apply concorrente com attempts CLAIMED/RUNNING. Enquanto há manutenção pendente, não se iniciam novas tasks; heartbeat/poll permanecem ativos.
6. `RUNNING`: marcador local fsync com ID antes de mutação, parada do runtime, confirmação de parada, instance lock, backup consistente do código/config/state, manifesto SHA de backup privado. Estado e journal atuais não são restaurados durante swap/rollback.
7. Engine reutilizado: verificação obrigatória, lock de manutenção, staging, `.previous`, `.failed`, self-check antes/depois do swap, fsync/rename. O companion vive em unit separada; parar runtime não interrompe o updater. Se estava ativo, reinicia e exige novo status ONLINE com heartbeat/poll antes de completar. Serviço intencionalmente parado pode permanecer parado; evidência `postcheck=false` não é prova de saúde online.
8. Resultado persistido antes do POST de retorno. Falha de transporte repete somente o relatório idempotente. Falha/crash não repete apply automaticamente. Estados `COMPLETED`, `FAILED`, `UNCERTAIN`, com motivo fechado e referência de backup, preservam auditoria.

Endpoints autenticados: `operations/claim` e `operations/report`. Bearer só no header; nenhum parâmetro de tenant é aceito. Não se usa Task Engine para executar scripts privilegiados: tasks de diagnóstico continuam com sua allowlist e leases/fencing originais.

## Companion privilegiado opcional

`agent-center-updater.timer` chama `agent-center-updater.service` a cada 15s, independente do runtime não-root. É uma parte estreita do Agent: root somente para arquivos protegidos e parar/iniciar sua unit fixa. Catálogo/artefatos/config do companion exigem root em todos os ancestrais e ausência de gravação por grupo/outros. Endpoint HTTPS vem de arquivo root privado separado do config editável pelo runtime. Nada da Central vira comando. Habilitar timer apenas após validar backup externo e autorizar esse host. Instalador mantém opt-in explícito.

## Rollback e recuperação interrompida

Rollback precisa de intenção, precheck e aprovação próprios. Release escolhida deve corresponder exatamente ao SHA de `.previous/agent.pyz`; não se escolhe arquivo livre. Mantém config, token, identidade, state, journal, policies e known_hosts. Nunca reenrola nem substitui journal recente por snapshot antigo. Versão problemática/origem, destino, motivo, status, backup e datas ficam na operação.

Engine pode recuperar falha de runtime em caminhos específicos; isso não constitui garantia universal de rollback automático. Falha durante rollback, serviço sem partida e interrupção entre renames precisam de recuperação manual.

Procedimento em host autorizado:

1. Parar timer do companion e serviço do Agent. Guardar `update.json`, markers do companion, `.failed`, `.previous` e backups privados. Nunca apagar marcador para tentar de novo.
2. Conferir SHA/self-check de runtime, previous e failed a partir de catálogo confiável. Se runtime ausente e previous íntegro, usar rollback local autorizado; se apenas failed estiver íntegro, operador pode restabelecer código conhecido em runtime com serviço parado, fsync e novo self-check. Não copiar state de backup sobre state atual.
3. Conferir identidade única, journal SQLite com WAL/SHM, permissões, freeze e leases. Estado `uncertain` de task continua em hold/reconcile.
4. Reiniciar somente código verificado, observar heartbeat/poll. Relatório interrompido é `UNCERTAIN`, sem replay. Uma intenção incerta bloqueia novas manutenções/tasks; resolver administrativamente somente após investigação do host.
5. Retomar timer após comprovar estado estável. Nova operação exige novo precheck/aprovação.

Não existe host de produção presumido. Sem um host autorizado, a evidência é exclusivamente de laboratório. Testes de Engine usam serviço controlado/injetado; não equivalem a validar systemd completo em Debian 13 real.
