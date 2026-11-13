# Device Read-Only V1 — publicação administrativa controlada

**DEVICE READ-ONLY V1 ADMIN PRODUCTION PASS**

Publicação autorizada em 2026-11-13. Implementação administrativa: `8723cea`. Origem analisada: `bc1e5f5`, `d953f6a`, `1cf6e02`. Apenas Laravel/UI/contratos de demonstração foram integrados às imagens operacionais existentes.

## Escopo publicado

O painel do Inventário identifica explicitamente **SIMULADO**, transporte **nenhum**, credenciais desabilitadas e acesso operacional **CONGELADO**. Apresenta os quatro contratos BGP, rotas, interfaces e recursos, com compatibilidade declarada por fabricante/modelo/versão. Reutiliza as demonstrações administrativas de Device Analysis V1, os 34 cenários e a rota já publicada, com Policy, sessão, CSRF, tenant e auditoria nativos.

A correção do formulário também foi publicada: nomes e mensagens em português, erro junto ao Hostname, campos administrativos seguros preservados, foco no campo inválido e aviso que pode ser fechado por teclado ou automaticamente. Conteúdo sensível continua rejeitado e não é repassado à sessão.

Não foram publicados `ReadOnlyLab/Ssh`, `Query`, `Access`, `Platform`, `Parser`, modelo `DeviceLabAccess`, migration de credenciais, rota `/readonly-lab`, servidor SSH Python ou fixtures CLI de protocolo. Não há dependência nova de runtime Python na Central. Nenhuma capability operacional ou Skill foi registrada. Não houve atualização do Agent nem alteração de Lifecycle, Preflight, ACLs, R2 ou equipamentos.

## Construção e preservação

As bases foram inspecionadas diretamente nos containers operacionais:

| Papel | Base anterior | Imagem publicada |
| --- | --- | --- |
| Web | `device-analysis-web-20261113` | `agent-center-central:device-readonly-admin-web-20261113` |
| Fila e scheduler | `device-analysis-queue-20261113` | `agent-center-central:device-readonly-admin-queue-20261113` |
| Diagnósticos | `device-analysis-diagnostic-20261113` | `agent-center-central:device-readonly-admin-diagnostic-20261113` |

O empacotador `scripts/prepare-device-readonly-admin.py` cria uma lista fechada de 11 arquivos sobre essas bases, sem copiar o checkout completo. A view principal deriva da view efetivamente publicada. A comparação das três imagens confirmou todos os demais arquivos preservados e paridade integral das rotas: Web 137, queue 137 e diagnostic 96. O usuário continua `www-data`. Os hashes exatos estão no [relatório de imagens](evidence/device-readonly-admin-production/image-parity.json) e no [manifesto do overlay](evidence/device-readonly-admin-production/overlay-manifest.json).

O novo arquivo Compose altera somente as imagens dos quatro serviços Laravel e fixa as flags de laboratório de protocolo em false/tenant vazio. O wrapper existente inclui esse overlay para manter a publicação reproduzível. A comparação em memória da configuração mostrou nenhuma mudança nas demais variáveis de ambiente; secrets não foram registrados. PostgreSQL e Nginx não foram recriados. Nenhuma migration ou grant foi aplicado à produção: schema/migrations permaneceram iguais, com 43 tabelas em FORCE RLS e sem tabela de acesso a dispositivos.

## Validação

| Verificação | Resultado |
| --- | --- |
| Regressão Laravel em PostgreSQL separado | 569 testes / 5.220 asserções, PASS, em três grupos disjuntos |
| Regressão Python em container sem rede | 185 testes, PASS; Agent não alterado |
| Pint da extensão e tooling PHP | PASS |
| Ruff do tooling de publicação | PASS |
| Build | Três imagens administrativas; bundles e módulos anteriores preservados |
| Imagens: rotas, classes ausentes, permissões e lista fechada | PASS nos três papéis |
| HTTP nativo da candidata, sessão/CSRF, dois tenants e RBAC | PASS; código da aplicação não montado do checkout |
| Candidata em 390/1440 px, claro/escuro | 12 verificações; oito demonstrações, quatro formulários; sem consulta real |
| HTTPS autenticado na produção | Quatro combinações de largura/tema; Inventário, novo painel e erro de Hostname validados |
| Backup/restauração/rollback | PASS; histórico preservado e limpeza obrigatória de views verificada |
| HTTPS/readiness | 200, certificado verificado, sem bypass TLS |
| Sessões/PDO | Secure e cifradas; PostgreSQL com TLS/verify-full |
| Filas | Job técnico database → worker → cache confirmado; workers saudáveis |
| Operations Health | VERDE antes e depois |
| FORCE RLS | Nenhuma linha visível sem contexto; runtime sem superuser/bypass |
| Preservação de dados | Inventário/identidade dos Agents iguais; profiles, skills, RAG, LLM, prompts e Knowledge iguais ao backup |
| Secret scan básico do índice / diff check | PASS |

Os dois erros Ruff globais `I001` em `agent/src/agent_center/heartbeat.py` e `agent/src/agent_center/update/remote.py` são preexistentes no workspace. A mesma saída foi reproduzida sem ocultar os achados. Nenhum arquivo do Agent consta dos três commits de origem nem do commit de publicação. Esses erros foram preservados; esta entrega não certifica lint ou release do Agent.

A conta existente usada na validação HTTPS manteve seus privilégios. A sessão nativa temporária de QA foi revogada e os arquivos de sessão removidos. Os POSTs de formulário na produção foram deliberadamente inválidos: nenhum equipamento, simulação ou tarefa de domínio foi criado. Demonstrações de cenários ocorreram exclusivamente no banco isolado da candidata. Os screenshots da produção ocultam a identidade da conta; o painel mostra somente metadados administrativos.

## Backup e restauração

Backup **local**, geração `20261113T144651Z`, com retenção/prune desativado para esta execução. A integridade da geração foi verificada pelo mecanismo existente, incluindo banco, storage, configuração e chaves protegidas. Nenhuma replicação externa ou API R2 foi acionada.

O dump foi restaurado em PostgreSQL isolado, sem consumidores de jobs da produção. Foram verificadas migrations, 43 tabelas com FORCE RLS, auditoria imutável, visibilidade zero sem contexto e runtime sem propriedade de tabelas. A chave protegida do backup validou a decifragem de um provider pelo SecretVault, sem imprimir segredo ou executar chamada externa. O rollback de imagem foi testado com oito simulações administrativas no laboratório: imagem anterior e candidata leram o mesmo histórico, com zero AgentTasks e nenhuma tabela de credenciais.

## Publicação e rollback

Publicação executada pelo wrapper existente, sem build a partir do checkout sujo e sem dependências:

```sh
./scripts/compose.sh up -d --no-deps --no-build --pull never --wait --wait-timeout 120 web worker telegram-scheduler diagnostic-worker
./scripts/compose.sh exec -T web php artisan view:clear
./scripts/deploy-precheck.sh
```

O smoke técnico de fila foi executado e confirmou processamento. O readiness HTTPS e o painel autenticado foram conferidos. Flags de demonstração administrativa anteriores foram preservadas; flags de protocolo LAB ficaram desabilitadas.

Rollback autorizado, se necessário, é **somente de imagens**, mantendo dados e schema:

```sh
./scripts/compose.sh -f deploy/compose.device-readonly-admin.rollback.yaml up -d --no-deps --no-build --pull never --wait --wait-timeout 120 web worker telegram-scheduler diagnostic-worker
./scripts/compose.sh exec -T web php artisan view:clear
./scripts/deploy-precheck.sh
```

A limpeza de views é **obrigatória**: a prova reproduziu cache Blade compartilhado que referenciava a nova classe ausente na imagem anterior. Após `view:clear`, a imagem anterior renderizou corretamente e preservou as oito simulações. Não executar rollback de migration, apagar histórico, limpar caches de domínio/RAG ou alterar o Agent. Preservar as três imagens anteriores registradas no relatório. Nenhum rollback foi aplicado à produção saudável.

## Limitações mantidas

A publicação não homologa hardware nem executa SSH/SNMP/NETCONF. Compatibilidade é uma declaração para demonstração, não permissão de acesso. Plataformas/versões fora da matriz continuam indisponíveis; o equipamento RouterOS 6 presente no inventário é corretamente identificado como não elegível aos contratos NE8000/RouterOS 7. Um resultado SIMULADO não pode ser promovido a medição, alerta ou Health real. Operações futuras continuam exigindo escopo e autorização próprios, aprovação, backup, janela, pós-validação e rollback.

[Evidências, Health, testes, classificação dos commits e screenshots](evidence/device-readonly-admin-production/README.md).
