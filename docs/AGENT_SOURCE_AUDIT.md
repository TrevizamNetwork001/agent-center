# Revisão do fonte do Agent — 2026-11-05

Revisão histórica dos pacotes 0.5.3/0.5.4. Os sete achados abaixo foram corrigidos
no fonte 0.5.5; implementação, testes e rollback em
[AGENT_FIXES_055.md](AGENT_FIXES_055.md). As evidências abaixo preservam o
comportamento anterior. O precheck do host teste ainda precisa ser validado com
o companion corrigido; não se atribuiu sua exceção antiga a uma hipótese.

Foram revisados todos os módulos do fonte disponível e os 22 módulos Python de
cada pacote 0.5.3 e 0.5.4, além de instalação, reparo, wrappers, units systemd,
empacotamento e testes. Os pacotes contêm 1.952 e 1.963 linhas Python,
respectivamente. Revisão estática e testes em containers isolados, sem identidade
de produção, rede externa, systemd real ou acesso a equipamentos. Nos testes de
companion, apenas o self-check roda como subprocesso real com queda de UID/GID;
API, serviço e falhas são simulados. O executor usa relógio virtual na prova de
deadline. Isso não substitui validar o serviço instalado no host remoto.

## Achados priorizados

| ID | Prioridade | Achado confirmado e consequência | Local |
| --- | --- | --- | --- |
| A01 | Alta | `agent/src` e pyproject ainda representam 0.5.2. Os pacotes incluem onboarding, telemetry e update/remote ausentes nessa árvore. Só 12 dos 22 módulos de cada pacote são idênticos aos arquivos disponíveis. O git_ref de 0.5.3 não existe neste checkout. Corrigir a correspondência entre fonte, revisão e release antes de novos builds. | `agent/pyproject.toml`, `agent/src`, catálogo 0.5.3 |
| A02 | Alta | Exceção no self-check é convertida em `precheck_failed`, sem distinguir permissão, execução ou ambiente. Após entregar o relatório FAILED à Central, `Remote.run()` retorna True e main termina 0. Isso explica como os logs do systemd mostram sucesso enquanto a operação falhou. Não prova qual exceção ocorreu no teste real. | pacote 0.5.3, `agent_center/update/remote.py:191`, `:218`, `:257` |
| A03 | Alta | Se o pós-teste falha depois da troca do runtime, o novo runtime pode permanecer instalado e ativo, com operação FAILED. O backup e `.previous` existem, mas a referência ao backup não é incluída no relatório de falha e não há rollback automático nesse caminho. Exige recuperação explícita e visibilidade do backup. | mesmo módulo, `:185`, `:206` |
| A04 | Média | `_postcheck()` exige ONLINE, heartbeat/poll positivos e mtime recente, mas não compara a versão observada à versão desejada. Um status recente de 0.5.3 foi aceito na prova de pós-teste de 0.5.4. | mesmo módulo, `:59` |
| A05 | Alta | No caminho direto de `Engine.apply`, o serviço é parado antes de adquirir instance.lock. Falha de lock deixa o serviço parado, sem troca do runtime. O wrapper Remote tenta reiniciar nesse caso; o CLI direto não tem essa proteção. | `agent/src/agent_center/update/engine.py:138`, equivalente nos pacotes |
| A06 | Média | A renovação de lease é síncrona dentro do loop de execução. Enquanto a chamada bloqueia, o prazo não é reavaliado. Injeção de renew lento produziu kill no segundo virtual 36 para timeout de 20 s. Heartbeat independente não resolve o atraso do kill. | `agent/src/agent_center/execution.py:130` |
| A07 | Média | `recover()` verifica que executions é lista, mas não valida cada entrada antes de usar `.get()`. Entrada null provoca AttributeError, não tratada pelo runtime. Não houve reexecução, mas uma resposta malformada pode derrubar/reiniciar o processo. | `agent/src/agent_center/execution.py:183` |

As provas A02–A07 estão em
[`behavior-proofs.json`](evidence/agent-source-audit/behavior-proofs.json).
O reprodutor é [`scripts/audit-agent-behavior.py`](../scripts/audit-agent-behavior.py).
Ele interrompe se os hashes dos pacotes não forem os aprovados. Nenhum desses
defeitos foi corrigido silenciosamente no pacote já publicado nesta revisão.

## Segurança e isolamento conferidos

- Nenhum shell fornecido pela Central/LLM: handlers e comandos systemctl fixos,
  subprocessos com argv, sem shell=True/eval/exec nos módulos operacionais.
- TLS validado, redirects bloqueados, proxies de ambiente desabilitados no Client
  e limite de leitura de resposta. Não há dependências de terceiros no runtime.
- Capabilities em allowlist, tarefas exigem policy válida e tasks habilitado;
  freeze permanece ativo e nomes device_ são negados.
- DNS e redes exigem autorização local; TCP/HTTP/ICMP/trace passam por validação
  de IP. O caso DNS negado por rede é diferenciado em 0.5.4 sem ampliar permissão.
- Identidade com token vinculado ao UUID, arquivos privados, repr sem segredo,
  lease fora do journal, logger de eventos fixos que descarta dados arbitrários.
- Journal com SQL parametrizado, reservas duráveis, recuperação sem reexecutar
  tarefa incerta. SQLite é estado local do Agent; a Central usa PostgreSQL.
- Companion root separado do Agent sem privilégios; catálogo/artefatos root-owned,
  SHA-256, verificação de paths e symlinks, backup e runtime anterior preservados.

Essas verificações não são garantia de ausência de vulnerabilidades. Não houve
auditoria do sistema operacional ou comparação com CVEs do host, nem inspeção
da configuração/identity remotas.

## Testes e ajustes realizados

| Alvo | Resultado |
| --- | --- |
| Fonte disponível, com regressões DNS | 46 testes PASS |
| Conteúdo real do pacote 0.5.3, testes base de runtime/executor/update | 40 testes PASS |
| Conteúdo real do pacote 0.5.4, incluindo DNS | 46 testes PASS |
| Companion real 0.5.3 + pacote 0.5.4, API/serviço simulados, self-check real | PLANNED, runtime preservado |
| Injeções de falha A02–A07 | Todas reproduzidas e registradas |
| Manifesto do kit 0.5.3 | 11 hashes conferidos |
| Sintaxe dos módulos empacotados | 44 módulos analisados por AST |
| Ruff, erros de sintaxe/imports/variáveis (E4,E7,E9,F) | Fonte, testes, reprodutor e ambos os pacotes PASS |

As primeiras execuções dos testes de pacote detectaram dois problemas do próprio
harness: teste preso à string 0.5.2 e mount sem o diretório packaging. Corrigidos
os dois testes para comparar `__version__` do código em teste e usado o layout
correto de mounts. Não se alteraram resultados esperados para esconder erro.
O teste de timeout/heartbeat também passou a selecionar o evento result, em vez
de supor que a última chamada concorrente seria o resultado. A suíte DNS nova
falha nos dois casos de bloqueio por rede na 0.5.3 como esperado; essa correção
já faz parte de 0.5.4. Os testes base 0.5.3 acima não incluem essa regressão nova.

Logs sanitizados e inventário estão em `docs/evidence/agent-source-audit/`.
O primeiro lint tentou criar cache em mount somente leitura; repetido com
`--no-cache`, sem alterar permissões. A seleção inicial de imports do reprodutor
foi ajustada para import dinâmico após a conferência do hash do pacote.
Ajustes desta revisão: testes, reprodutor e documentação. Sem deploy, migrations,
troca de runtime, aprovação de plano, novas tarefas ou mudança de permissões.
Rollback dos ajustes de teste: reverter somente os diffs de test_execution.py e
test_update.py desta revisão; os artefatos adicionais não têm efeito operacional.

## Situação do problema reportado

No host teste, o operador comprovou Python 3.13.5, self-check 0.5.4 e dry-run
manual válidos. O companion passou a comunicar após o reparo. Duas operações da
Central terminaram com precheck_failed; a segunda foi uma única repetição antes
desta revisão. O mesmo caminho completo passou no ambiente isolado.

A prioridade é obter uma causa sanitizada do precheck dentro do contexto do
serviço, corrigir A02 e alinhar fonte/release (A01). Verificar configuração de
systemd/permissões é uma hipótese, não um diagnóstico concluído. Não liberar
instalação, afrouxar sandbox ou alterar políticas DNS para forçar sucesso.
