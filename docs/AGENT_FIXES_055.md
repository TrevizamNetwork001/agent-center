# Correções do Agent 0.5.5

O fonte completo foi recuperado do pacote 0.5.4 verificado antes das correções.
Fonte e pyproject agora declaram 0.5.5. O build gera Agent, updater e companion
exclusivamente dessa árvore, com ordem/timestamps fixos e SHA-256 do fonte.
Pacotes 0.5.3/0.5.4 originais permanecem imutáveis; o script histórico de 0.5.4
agora apenas verifica seu hash. Novas versões usam scripts/build-agent-release.py.

## Achados corrigidos

| Achado | Correção e evidência |
| --- | --- |
| A01 | Árvore completa alinhada, versões iguais, três zipapps do mesmo fonte e build repetido byte a byte. |
| A02 | Erros finitos de self-check distinguem permissão, timeout, execução, troca de usuário e JSON inválido. Sem exception/argv/stdout privados nos logs. Operação FAILED/UNCERTAIN resulta em exit de falha, mesmo com relatório entregue. |
| A03 | Pós-teste malsucedido restaura o runtime anterior verificado e confirma seu retorno. Backup/ref e resultado de rollback ficam na evidência da falha. Falha de recuperação produz UNCERTAIN e impede replay automático. |
| A04 | Pós-teste exige versão esperada, schema 1, status fresco e heartbeat/poll positivos; leitura privada com NOFOLLOW e limite de tamanho. |
| A05 | Falha antes da troca, inclusive lock ocupado, tenta restaurar o serviço anterior somente se o hash do runtime original estiver preservado. Falha de restart tem código próprio. |
| A06 | Watchdog independente encerra o grupo do processo no prazo, inclusive durante renew bloqueado. Resultado permanece execution_timeout. |
| A07 | Entradas de reconcile e lista de cancelamentos são validadas; entradas malformadas não derrubam o runtime nem autorizam reexecução. |

Relatórios de manutenção repetidos são idempotentes na Central somente quando
estado, erro e evidência são os mesmos. Divergência retorna conflito, preservando
o registro. Isso cobre ACK perdido de PLANNED. Policies, token, contexto de tenant
e RLS continuam obrigatórios. Versões aceitas incluem explicitamente 0.5.5.

## Instalação na máquina existente

O instalador reconhece a identidade existente e prepara a atualização sem
reenrollment. Sob helper.lock, valida kit/units/arquivos root-owned, salva os dois
updaters anteriores e o catálogo em backup privado, pausa somente o timer do
companion e troca os dois updaters por arquivos de hash conhecido. Recusa serviço
de manutenção ocupado. Preserva runtime, identidade, configuração e journal.
Disponibiliza 0.5.5 no catálogo local e volta ao estado do timer apropriado.

O companion 0.5.5 anuncia sua versão. O painel distingue preparação pendente de
updater pronto sem inventar diagnóstico remoto. A ação continua chamada
Atualizar Agent. Após o preparo, o operador solicita precheck e revisa/confirmar
o plano pelo fluxo nativo. Nenhum plano foi aprovado automaticamente nesta
publicação e não se afirma que o host teste já foi atualizado.

## Verificação

- Agent: 55 testes PASS, incluindo regressões dos achados, sem rede externa ou
  equipamento. Deadline testado com subprocesso local e renew bloqueado.
- Instalador: 7 testes PASS, incluindo transição dos updaters originais,
  repetição, falha/rollback, segredo fora da saída e rejeição de adulteração.
- Laravel/PostgreSQL separado: 37 testes / 436 assertions PASS, incluindo
  relato de versão/código sanitizado, idempotência, autorização e isolamento.
- Companion/pacote reais em container: precheck PLANNED com runtime preservado;
  apply com pós-teste injetado falho restaurou pacote 0.5.3 e estado, reportando
  FAILED/postcheck_failed/rollback=true. Serviço e API simulados; self-check real.
- Build reproduzido com hashes iguais, sintaxe Bash e diff check PASS. Kit HTTPS
  baixado e hash conferido. Nenhuma medição externa DNS ou ação de device.

SHA-256 do fonte: `8ea3d5d194a877af9ea3868a94ae28275ce18fd5fcc6c0e58ba6092759d63c00`.
Runtime: `769980dd97751c44cf486e0f21fc399cf4448142c18f0b3f08966d45521e12a1`.
Kit: `c2b1fcbdc7d0a1871e791ab2374289b269615c7864996e77d5d1ae39e604bea7`.
Hashes de todos os componentes em docs/INSTALLER_DOWNLOAD_HASHES.json e
artifacts/agentkit/0.5.5/build.json.

## Publicação e rollback

Imagem Central derivada de updater-repair: agent-center-central:agent055. Apenas
web é recriado. Sem migrations, grants, mudança em dados históricos, contas,
secrets, políticas DNS ou permissões/sandbox do Agent. Vhost do projeto recebe
somente a permissão de download do kit 0.5.5, com nginx -t antes de reload.
Uma diferença local de página 403 foi identificada e não foi incluída no vhost
final: seu conteúdo operacional anterior foi preservado.

Snapshot privado em backups/agent-fixes055 contém a árvore anterior e arquivos
da Central anteriores. Para rollback da Central, voltar somente a imagem web
para agent-center-central:updater-repair e restaurar instalador/helper/hashes do
snapshot, preservando alterações posteriores. Se já houver hosts com companion
0.5.5, manter suporte aos códigos novos até restaurar seus companions; relatórios
não entregues permanecem duráveis e não autorizam repetição de instalação.
Não remover kit publicado, dados ou identidades. Limpar views via compose.
O vhost anterior está em central-before/host-nginx.conf; restaurar e validar
nginx -t antes de reload se for necessário retirar a rota de distribuição.

Na máquina do Agent, backup do preparo em /var/lib/agent-center-updater/repairs/
contém catalog.before.json, rollback.json, updater.pyz.before e
remote-updater.pyz.before, todos privados. Com manutenção inativa e helper.lock
protegido, restaurar os arquivos root-owned, preservar identidade/config/state e
restabelecer o timer conforme rollback.json. Rollback de runtime usa o updater
controlado, com pacote anterior verificado. Nunca forçar unlock ou restaurar banco.
