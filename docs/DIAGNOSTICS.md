# Diagnósticos determinísticos

Fluxo: alvo → Profile → Agent autorizado → executar → progresso/evidências → conclusão. Operator e administradores do tenant executam/cancelam; viewer apenas consulta. Platform admin precisa selecionar explicitamente um tenant. CSRF e sessões continuam nativos Laravel; FormRequests validam entrada, Policies autorizam e scopes/RLS isolam dados.

Alvos aceitos: hostname, IP ou URL HTTPS sem credenciais/query/fragmento, porta 443. O Agent exige allowlist local de nomes DNS exatos e CIDRs: Central não pode ampliá-la. Por padrão ambas são vazias. HTTP de laboratório somente loopback; HTTPS valida certificado e hostname. Conexão HTTP usa IP aprovado fixado, preservando Host/SNI e sem redirects/proxy de ambiente. Só HEAD, sem corpo de resposta.

DNS faz A e AAAA separadamente e agrupa visualmente em DNS. TCP usa o primeiro IP autorizado das respostas; esta fase não testa exaustivamente todos os endereços/CDNs. ICMP/traceroute só são anunciados se os binários existirem. Não instalar binários ou inventar outras oito capabilities para completar um número histórico.

## Classificação

- NORMAL: **Não foram encontradas anormalidades no escopo testado.**
- INDICIO_DE_FALHA: evidência negativa nas etapas primárias, status HTTP >=400, falha TLS ou duração HTTPS acima do threshold.
- INCONCLUSIVO: dados insuficientes, capability requerida ausente, fila sem execução ou interrupção.
- ERRO_DE_EXECUCAO: erro interno do Agent, timeout do subprocesso ou lease perdida em etapa primária.

SITE_LENTO com DNS/TCP/HTTPS positivos permanece NORMAL se ICMP estiver bloqueado ou traceroute incompleto. AAAA sem dados é neutro quando A responde; erro explícito de AAAA permanece evidência negativa. O diagnóstico não comprova ausência de problemas fora do escopo e não atribui culpa ao cliente.

Resultados são normalizados por capability: DNS answers/record_type/duration_ms/error; TCP connected/duration_ms/error; HTTP status/tls/duration_ms/resolved_ip/error; ping sent/received/loss_percent/min_ms/avg_ms/max_ms/duration_ms/error; traceroute hops/reached_destination/duration_ms/error. Sem stdout bruto ou exceções em evidências. Enums e limites rejeitam payload inesperado.

Golden fixtures em app/resources/fixtures/diagnostics.json têm resultados esperados fixos; teste de alvo inválido verifica também a rejeição no Planner. Fixture testing no catálogo é offline e não executa consultas.

Agent real em produção não foi instalado nesta reconstrução. Testes de DNS usam localhost no laboratório, sem sondar Google ou terceiros. Device operations continuam frozen, incluindo nomes device_* futuros.

## Correção de classificação por política (2026-11-05)

Resultados `policy_denied` são preservados como evidência, mas não contam como falha ou lentidão do alvo. Se uma etapa primária for bloqueada e não houver falha comprovada em outra etapa, a conclusão é INCONCLUSIVO, com orientação para revisar autorização da capability e allowlist local do Agent. Falhas reais e erros internos continuam com sua classificação própria.

Validação: `scripts/test.sh --filter DiagnosticPolicyTest` inclui regressões e fixtures existentes. Implantação exige atualizar a imagem da Central e reiniciar o worker de diagnósticos; editar o arquivo do host não altera containers existentes. Histórico anterior é preservado; repetir o teste após corrigir a autorização. Rollback: restaurar a versão anterior de `app/app/Diagnostics/Classifier.php`, remover `app/tests/Unit/DiagnosticPolicyTest.php` e retornar à imagem anterior caso tenha sido publicada. Não há migração nem alteração de dados.

Validação desta correção: `scripts/test.sh --filter 'DiagnosticPolicyTest|DiagnosticsTest'` passou com 24 testes e 208 assertions em PostgreSQL separado, incluindo isolamento por tenant, bloqueio com métricas vazias, erro interno, cancelamento e fixtures existentes. Pint dos dois arquivos passou. Imagem candidata `agent-center-central:diagnostic-policy-20261105` derivada da imagem operacional `agent-delete`, substituindo exclusivamente o classificador. Nenhuma migration ou reclassificação de histórico é necessária.

Rollback operacional: restaurar `deploy/compose.yaml` de `backups/diagnostic-policy-20261105/compose.before.yaml` e executar `scripts/compose.sh up -d --no-deps --no-build --wait --wait-timeout 180 web worker diagnostic-worker`. Alternativamente, usar `docker compose --env-file .env -f deploy/compose.yaml -f backups/diagnostic-policy-20261105/rollback.yaml up -d --no-deps --no-build --wait --wait-timeout 180 web worker diagnostic-worker`, mantendo o overlay em recriações posteriores. Preservar banco, volumes, histórico e tokens.

Publicação em 2026-11-05 concluída: web, worker e diagnostic-worker saudáveis na imagem candidata. SHA-256 do classificador conferido no web e diagnostic-worker contra o checkout (`938f40cc07ea8a6a01aa8448c48681b76f11f8f89ff10ffc8d2b89392c456919`). Smoke offline no web confirmou `policy_denied` → INCONCLUSIVO com mensagem de política. `scripts/deploy-precheck.sh` passou: TLS positivo/negativo, PDO/configuração de produção, fila → worker → cache, saúde do worker de diagnóstico e assets via Nginx. Nenhum diagnóstico externo disparado; histórico preservado. A autorização local do alvo/capability no Agent continua necessária para executar verificações bloqueadas.
