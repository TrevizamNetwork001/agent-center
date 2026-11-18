# Guia de retomada do Agent Center — 18/11/2026

## Comece aqui

Repositório: https://github.com/TrevizamNetwork001/agent-center. A linha de desenvolvimento consolidada é `main`; a base verificada antes deste complemento documental é `4453457f0734a52e57a5e810e5428da112b050d6`. Leia primeiro [AGENTS.md](../AGENTS.md), [README](../README.md), [fluxo consolidado](FLUXO_ATUAL_20261118.md) e [pendências verificadas](PENDENCIAS_20261118.md). Este guia registra fonte e evidências existentes; não é uma nova atestação de produção.

Para localizar todos os documentos e evidências versionados, consulte o [índice completo](INDICE_DOCUMENTACAO.md). Os relatórios datados preservam decisões e resultados de cada fase. Quando houver divergência, conferir o relatório posterior, o código, o contrato e a implantação efetiva; não aplicar uma instrução antiga automaticamente.

## Arquitetura e pontos de entrada

| Frente | Código e referências |
| --- | --- |
| Central Laravel | `app/`; [arquitetura](ARCHITECTURE.md), [regras locais](../app/AGENTS.md) |
| Identidade, RBAC e tenant | `app/app/Tenancy`, `app/app/Policies`, middleware e migrations Laravel; [Auth/tenancy](AUTH_AND_TENANCY.md), [roles PostgreSQL](DATABASE_ROLES.md) |
| Conversa e canais existentes | `app/app/Conversation`, `app/routes`; [Conversation](CONVERSATION_ENGINE.md), [Telegram](TELEGRAM_V1_OPERATIONS.md) |
| Diagnósticos, Preflight e tarefas | `app/app/Diagnostics`, `app/app/Agent`; [Diagnostics](DIAGNOSTICS.md), [Task Engine](TASK_ENGINE.md), [Profiles/Skills](PROFILES_AND_SKILLS.md) |
| Conhecimento e LLM | `app/app/Knowledge`, `app/app/Llm`; [Knowledge](KNOWLEDGE.md), [Prompts](PROMPTS.md), [LLM](LLM.md), [RAG](RAG_V1_SPEC.md) |
| Agent separado | `agent/src`; [arquitetura](AGENT_ARCHITECTURE.md), [API](AGENT_API.md), [operações](AGENT_OPERATIONS.md) |
| Lifecycle e candidata | `agent/candidates/routeros-operational-v1`; [updates](AGENT_UPDATES.md), [transição assinada](AGENT_PANEL_SIGNED_TRANSITION_066.md), [rollout](AGENT_PANEL_ROLLOUT_066_20261116.md) |
| Frontend | `app/resources`, `app/public`, Vite; [dashboard](DASHBOARD_REACT_VITE.md), [UX](WEB_UX.md); alterações visuais exigem a skill allowlisted em AGENTS.md |
| Operação e recuperação | `deploy`, `scripts`; [deploy](DEPLOYMENT.md), [saúde](OPERATIONS_HEALTH.md), [backup](BACKUP_POLICY.md), [recuperação](DISASTER_RECOVERY.md) |
| Dispositivos em laboratório | [implementação MikroTik](MIKROTIK_READONLY_INTEGRATION_V1_IMPLEMENTED.md), [aceite 0.6.6](MIKROTIK_READONLY_066_LAB_COMPLETION.md), [preparação física](MIKROTIK_PHYSICAL_READONLY_PREPARATION_V1.md) |

## Regras para qualquer continuação

Central exclusivamente Laravel; PostgreSQL; Python somente no Agent separado e tooling autorizado. Preservar Auth, Session, CSRF, Hash, Policies/Gates nativos, TLS, auditoria, RBAC e FORCE RLS. Todo recurso, job, cache e autorização de domínio deve isolar tenant e ter testes contra acesso cruzado. Migrations futuras são Laravel.

LLM não decide operações. Não admitir shell/CLI arbitrários; contratos e allowlist determinísticos limitam execução. Skills operacionais exigem allowlist. Device freeze permanece, mesmo para leitura. Cadastro de equipamento ou PASS de simulador não libera acesso físico. Novas integrações exigem escopo próprio; WhatsApp não é entrega autorizada deste registro.

Push de fonte não autoriza deploy, atualização de Agent, ampliação de privilégios ou alteração destrutiva. Releases de Agent usam assinatura, compatibilidade, laboratório, backup e Lifecycle Manager existente. Rollback deve preservar dados, identidade e journal; não restaurar dump nem executar migration down como reversão genérica.

## Fonte, laboratório e produção

- A Central teve web, workers, PostgreSQL e nginx saudáveis na inspeção documentada em 18/11. Não houve nova inspeção operacional nesta publicação.
- O runtime da Central usa imagens `panel-updater-*-20261116` e diverge do checkout. Antes de deploy, selecionar e revisar ajustes, contratos e dependências; não promover todos os recursos LAB automaticamente.
- `agent/src` registra fonte 0.6.4; a candidata 0.6.6 está separada. O rollout 0.6.6 foi comprovado na VM teste em 16/11, sem autorizar atualização geral.
- RouterOS foi homologado com simulador, não com equipamentos físicos. As leituras físicas continuam bloqueadas por freeze/autorização.
- Conversa/Telegram e RAG têm fechamentos de produção históricos. Esta rodada não enviou mensagens nem chamou providers reais.

## Pendências que precisam continuar visíveis

| Pendência | Próximo passo e limite |
| --- | --- |
| Diferenças entre fonte e runtime | Revisar manutenção e elaborar implantação com backup, testes, escopo e rollback próprios |
| Backup externo atual | Verificação operacional ainda não comprovada; erro de acesso ao bus systemd não prova ausência de timer ou backup |
| Certificados do LAB `backup-ui` | Correção autorizada documentada; validade até **18/12/2026 16:53:49 UTC**. Renovar antes ou encerrar o LAB com autorização própria |
| Aceite físico MikroTik | Autorização específica com tenant, ator, equipamento, Agent, destino, fingerprint, credencial, janela e limites; não executar por este guia |
| Skips opcionais dos testes | Três cenários systemd `PANEL_TRANSITION_LAB=1` e dois RouterOS `ROUTEROS_TASK_LAB=1` não foram reexecutados na publicação anterior |
| Advisories atuais | Rodada anterior sem rede Composer não foi auditoria atualizada de vulnerabilidades |
| Branches históricas auxiliares | Preservadas no GitHub; não são novas linhas homologadas para produção. Ver ressalva de bundle abaixo |

Detalhes, evidência e rollback da correção TLS: [pendências de 18/11](PENDENCIAS_20261118.md). Backups restritos, secrets, dumps, identidades e estado persistente continuam fora do versionamento; GitHub não recupera sozinho o banco ou as chaves.

## Branches verificadas no GitHub

Consulta direta `git ls-remote --heads origin` após push normal e atômico, sem force:

| Branch | Commit verificado | Uso |
| --- | --- | --- |
| `main` | `4453457f0734a52e57a5e810e5428da112b050d6` | Base consolidada anterior a este guia |
| `dashboard-react-vite` | `12fec521c31d0055999018a3815de30446d57119` | Histórico auxiliar frontend |
| `mtr-central-ready` | `89ba0f5bd39b8fc6ee1ba7af6e4f55daf4cc58e7` | Histórico auxiliar MTR |
| `mtr-path-analysis-lab` | `f301555a9ea8f83255fe21bb922865bc7777cc60` | Histórico auxiliar LAB |
| `mtr-secure-executor` | `4c99a12bc38812a3b09d2c41750a924e1e78b9cf` | Histórico auxiliar executor limitado |
| `pre-publication-local-20261118` | `f227293ee7e01bcdb10e909a7fe0a40293bbd995` | Snapshot ancestral da main |
| `production-recovery8` | `57a9470f9f173ec93575d40901dc0598195bd66b` | Linha histórica, não prova do runtime atual |
| `recovery9` | `57a9470f9f173ec93575d40901dc0598195bd66b` | Linha histórica de recuperação |

As quatro primeiras branches auxiliares têm histórico sem base comum com a main atual; contagens de commits exclusivos não equivalem a funcionalidades ausentes. Não fazer merge automático. O snapshot `pre-publication-local-20261118` já é ancestral de main.

### Ressalva da publicação das auxiliares

A publicação anterior excluiu explicitamente um bundle antigo do conjunto de main. A publicação posterior das quatro branches auxiliares tornou alcançável o commit `faeda52` com `agent/dist/recovery8-source.bundle`. Esse formato é proibido pela política de Git do projeto, ainda que não demonstre por si só uma credencial exposta. A checagem inicial desta publicação usou padrões de tokens/chaves e não cobriu bundles; não deve ser descrita como aprovação do scanner histórico completo.

Não baixar, executar ou reutilizar esse bundle como artefato aprovado. A remoção de arquivo em novo commit não remove o objeto histórico. Uma eventual retirada das referências/revisão de histórico remoto exige decisão específica, preservação do fonte e verificação posterior; não foi feita exclusão ou force push nesta rodada. Este alerta substitui a afirmação antiga de que o bundle permanece apenas local, sem alterar a evidência do conjunto de main publicado anteriormente.

## Validação e reprodução

Resultados históricos da publicação consolidada: 604 testes Laravel/5.563 asserções, 5 skips opcionais; 202 testes Agent; 19 backup; 4 R2 sintético; 2 assinatura/adulteração; build, Ruff, Pint, Compose e Chromium aprovados conforme [relatório e evidências](PUBLICACAO_GITHUB_20261118.md). Não são resultados executados novamente ao escrever este guia.

Para retomar testes, seguir [scripts/test.sh](../scripts/test.sh), [validação](VALIDATION.md), os runners de `scripts/lab` e a seção de reprodução da publicação. Usar PostgreSQL e redes descartáveis de laboratório, assets frontend explícitos e dependências correspondentes aos lockfiles. Não usar env, credenciais ou base de produção nos testes. Testes condicionais exigem o ambiente específico; omitir a flag não comprova o cenário.

Antes de publicar: revisar diff e arquivos novos, executar `git diff --check`, validar links e scanners existentes `scripts/secret-scan.sh` e `scripts/audit-secret-history.py`. O scanner histórico lê secrets localmente para comparação em memória e só emite contagens; falha de permissão não é PASS. Uma detecção de bundle deve permanecer registrada, sem expor seu conteúdo. Confirmar o hash remoto após o push.

Nesta rodada, nenhum teste de aplicação foi reexecutado porque a alteração adicional é documental. A validação documental e os scanners devem ser registrados no complemento de publicação, com suas limitações. Não criar evidência fictícia para preencher uma lacuna.

## Recuperação e próximo trabalho

Em host autorizado, clonar o repositório, conferir commit/branch e integridade, ler AGENTS.md e este guia. Provisionar acesso GitHub independente da VPS e recuperar secrets/configuração de seu armazenamento seguro, nunca do Git. Seguir manifesto/schema e procedimento de restore em ambiente separado. Conferir runtime implantado antes de decidir qualquer promoção.

Uma continuação deve registrar problema, escopo autorizado, arquivos/contratos afetados, isolamento por tenant, testes realmente executados, limitações e rollback. Atualizar este guia e as pendências quando houver evidência nova. Este complemento pode ser revertido por commit inverso dos arquivos documentais, sem alterar banco ou serviços.
