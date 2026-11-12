# Device Analysis V1 — compatibilidade da demonstração administrativa

Correção isolada sobre `2186588` e `3c5dd48`. Não houve publicação ou alteração do Agent, Lifecycle, Preflight, Observability, ACLs, políticas, device freeze, R2 ou equipamentos.

## Causa e correção

`Contracts::labEnabled()` restringe o simulador conversacional a `local/testing`. O bloqueio foi reproduzido na imagem homologada em `production`, inclusive com as flags antigas e tenant habilitados. Essa proteção continua intacta.

A demonstração administrativa passa a ter um contexto tipado separado: `SimulationScope::AdministrativeDemo` (`ADMINISTRATIVE_DEMO`). A entrada é um POST de formulário no Inventário NOC: `noc.analysis-demo`, `/noc-inventory/{nocDevice}/analysis-lab`. Esse endpoint gera fixtures locais e não executa consultas remotas.

Condições cumulativas:

* autenticação, sessão e senha regularizadas pelos middlewares existentes;
* tenant selecionado no contexto nativo e autorizado pelas Policies e FORCE RLS;
* `DEVICE_ANALYSIS_ADMIN_DEMO_ENABLED=true`;
* tenant explicitamente listado em `DEVICE_ANALYSIS_ADMIN_DEMO_TENANTS`;
* `NocDevicePolicy.demonstrate`, reutilizando a autorização administrativa de atualização do cadastro (tenant/platform admin, nunca operator/viewer);
* CSRF, rate limit existente de 30/minuto, fixture fechada, parâmetros tipados e revisão do cadastro correspondente.

As novas configurações são **false/lista vazia por padrão**, independentemente de APP_ENV. Habilitar a demonstração não habilita `labEnabled()`, o Telegram ou a conversa Web em produção. Não se deve mudar APP_ENV para contornar o bloqueio.

## Isolamento verificável

O servidor escolhe o contexto por enum; o formulário não aceita contexto, resultado, comando ou identidade de tenant fornecidos pelo cliente. A entrada administrativa exige contexto administrativo, enquanto o endpoint anterior e a conversa mantêm o contexto `LAB_CONVERSATION`. Chaves de idempotência não podem ser reutilizadas entre os contextos.

Não há simulador ou autorizador operacional duplicado: `Inventory::simulate`, locks, `Catalog`, normalizadores e interpretação determinística são reutilizados. As únicas gravações da geração são uma linha imutável na tabela existente `noc_simulations` e um evento de auditoria `noc.simulation_created`, agora com `simulation_scope` fechado. Nenhuma migration, tabela ou grant novo.

O resultado possui `source=FIXTURE`, `simulation=true`, `mode=SIMULATED`, `display_mode=SIMULADO`, `environment=LAB`, `simulation_scope=ADMINISTRATIVE_DEMO`, `real_evidence=false`, `task_eligible=false`, `health_eligible=false` e `operational_enabled=false`. A cobertura operacional permanece `NOT_EXECUTED`. Os modelos e contratos de Task Engine recebem diagnósticos reais, não linhas de `noc_simulations`.

Diagnostic Experience rejeita evidência administrativa na apresentação conversacional, inclusive se o ambiente for local. A entrega Telegram também recusa esse contexto. Pedidos operacionais continuam reconhecidos como BGP/tabela de rotas, devolvendo consulta real indisponível, sem confirmação, substituição silenciosa ou execução. Não foi implementada demonstração conversacional administrativa.

O detalhe do inventário não devolve resultados `ADMINISTRATIVE_DEMO` a usuários sem permissão administrativa nem quando o tenant/flag perde habilitação. O histórico permanece armazenado, sem exclusão. O inventário comum continua acessível conforme sua Policy original. Simulações anteriores permanecem regidas pelo contrato anterior, sem virar evidência operacional.

Os testes calculam snapshots de todas as tabelas protegidas por FORCE RLS, exceto o histórico de simulações e auditoria, antes/depois dos 34 cenários. Não houve mudança no inventário, revisões, Agent, Health, diagnósticos, tarefas ou outros estados protegidos. A geração das 34 fixtures também foi executada em cada imagem candidata com `--network none`, sem acesso ao banco. O acesso administrativo/persistência foi testado separadamente com autenticação, CSRF, RBAC e PostgreSQL/RLS reais.

## Interface e catálogo

A seção existente do detalhe passa a se chamar **Laboratório de análise**. Exibe fabricante/plataforma, compatibilidade declarada, cenário, evidência, interpretação e limitações, com identificação permanente LABORATÓRIO / SIMULADO. Não apresenta saúde real ou estado ONLINE.

Os 34 cenários e as matrizes de modelos/releases são os mesmos homologados. Nenhuma nova Skill, Profile ou capacidade de equipamento é instalada. Alarmes Huawei continuam DOCUMENTED, sem catálogo de códigos por release ou consulta operacional habilitada. Permanecem as limitações de contadores acumulados, janelas comparáveis, referências explícitas de recursos e ausência de inferência de causa raiz.

## Imagens e compatibilidade

O Dockerfile mantém o bootstrap, vendor e rotas operacionais de cada imagem base. A descoberta de rotas apontou que copiar o arquivo web completo do checkout incluiria referências a módulos não publicados. Esse empacotamento foi corrigido: só se adiciona o arquivo fechado `noc-analysis-demo.php` ao arquivo de rotas já presente na imagem operacional, durante o build. Observability não é incorporada.

Usar `--target web` com a base Web/queue e `--target diagnostic` com a base do worker diagnóstico. O usuário final de execução é preservado. A paridade exige todas as rotas anteriores intactas: Web/queue recebem somente o POST administrativo; o worker diagnóstico mantém suas rotas anteriores. Imagens candidatas são artefatos LAB, sem serviço de produção substituído.

## Validação e evidências

[Relatório e screenshots](evidence/device-analysis-demo/REPORT.md). Os testes reproduzem o bloqueio original e exercitam APP_ENV=production, flags, tenant, admin/operator/viewer, autenticação, CSRF, 34 fixtures, leitura autorizada, revogação, RLS entre dois tenants, replay, revisão, parâmetros arbitrários, não admissão de evidência administrativa pela conversa e ausência de efeitos operacionais. Telegram e as demais regressões são executados pela suíte completa.

Na primeira execução direcionada, o próprio teste precisou respeitar CSRF ao verificar usuário anônimo e a janela do rate limit ao percorrer 34 cenários. As proteções foram mantidas; o teste foi ajustado para enviar um token válido e avançar o relógio entre os lotes. O teste de leitura foi acrescentado antes da regressão final. Nenhuma falha foi mascarada nem classificador operacional alterado.

## Plano de publicação — pendente de autorização específica

1. Conferir revisão, alterações posteriores e hashes das imagens operacionais; integrar os dois commits anteriores e este patch isolado, preservando alterações externas.
2. Executar prechecks, backup verificável, restauração em banco isolado, regressões e comparação de rotas dos três papéis. Não executar migrations de outros módulos: esta correção não exige migration.
3. Publicar os overlays compatíveis da Central; manter as flags novas desligadas inicialmente. Não atualizar o Agent nem qualquer executor.
4. Habilitar somente os tenants administrativamente aprovados mediante configuração específica. Testar o formulário com fixtures em tenant de demonstração, sem conectar ao IP de gerência e sem marcar o equipamento como ONLINE.
5. Validar HTTPS/readiness, filas, scheduler, webhook, operações reais existentes e Operations Health. Pedidos operacionais na Web/Telegram devem continuar indisponíveis para equipamentos; simulações administrativas nunca entram no Task Engine.

Rollback funcional imediato: desativar `DEVICE_ANALYSIS_ADMIN_DEMO_ENABLED` e recarregar a configuração nos três papéis, mantendo este patch de isolamento. Isso interrompe novas demonstrações e oculta os resultados administrativos, preservando histórico e auditoria. A revogação é testada, inclusive para replay.

Não executar migration down nem apagar simulações. Para rollback adicional de imagem, preparar antes um artefato compatível que preserve o filtro de leitura `ADMINISTRATIVE_DEMO`; uma reversão cega para versões que desconhecem esse contexto voltaria a exibir os resultados administrativos no histórico comum. A preparação desse rollback de imagem é condição para uma futura publicação, não uma operação realizada nesta entrega.

A sessão temporária de QA e os cookies permanecem fora do Git e são invalidados após as capturas. O teste de interface usa HTTP de loopback em laboratório, com sessão real e CSRF; a validação HTTPS do domínio operacional pertence à publicação futura. Não houve teste em equipamento real.
