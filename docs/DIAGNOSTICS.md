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
