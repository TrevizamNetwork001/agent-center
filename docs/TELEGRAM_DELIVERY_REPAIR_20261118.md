# Correção de entrega Telegram e compatibilidade do preflight — 18/11/2026

**Atualização:** o usuário autorizou a publicação, que foi concluída com ajuste adicional de timeout comprovadamente anterior à conexão. O [relatório de produção](TELEGRAM_REPAIR_PRODUCTION_20261118.md) registra imagens finais, backup/restore, testes, rollback e a instabilidade residual. As seções de preparação abaixo preservam a evidência anterior à publicação.

## Problema e evidência

A mensagem sobre tracert para 8.8.8.8 foi processada, classificada como pedido de análise de caminho e recebeu uma resposta no histórico. A entrega ficou UNKNOWN/DELIVERY_UNKNOWN. Nenhum diagnóstico foi criado. O Agent associado ao contexto era 0.6.6; a resposta informava incompatibilidade do preflight.

Consultas somente de leitura no tenant 1 mostraram bot habilitado, 56 entregas SENT e cinco UNKNOWN naquele instante. Os serviços principais estavam saudáveis e o scheduler estava em execução. getMe respondeu OK; getWebhookInfo alternou entre resposta válida e falha de transporte. Nas respostas válidas, havia zero updates pendentes e nenhum last_error_date retornado.

No worker, uma conexão padrão expirou, IPv6 falhou e IPv4 estabeleceu HTTPS com validação TLS. Cinco consultas IPv4 adicionais ao endpoint público funcionaram; uma de três consultas IPv4 autenticadas de getWebhookInfo falhou. Portanto, IPv4 reduz a exposição à falha IPv6 observada, mas não comprova resolução de toda a instabilidade. Não houve envio de mensagens nem chamada a provider LLM.

## Alteração preparada no checkout

- Client Telegram usa IPv4 somente no seu destino fixo, preservando TLS, timeouts, allowlist e proibição de redirects.
- O callback de estatísticas de transporte captura somente o código de erro em memória. Falhas comprovadas de resolução DNS ou estabelecimento de conexão (cURL 6 e 7) retornam TRANSPORT_UNAVAILABLE, sem detalhes privados.
- Deliver permite até cinco tentativas para essas falhas, com intervalo de 60 segundos e revalidação de identidade, tenant, vínculo e fontes. Timeouts, respostas 5xx e falhas sem prova continuam UNKNOWN para sendMessage. Entregas antigas UNKNOWN não são modificadas nem reenviadas.
- Preflight e validação da Agent API compartilham allowlist explícita 0.6.5/0.6.6. O contrato existente está implementado na candidata 0.6.6. ALLOWED continua exigindo versão do Agent autenticado e helper correspondente, saúde, digest, revisão e janela válidos. Versões futuras não são aceitas automaticamente; não há concessão de ACL, capability ou execução por esta alteração.

Um Agent 0.6.6 com helper incompatível ou indisponível ainda pode responder UNKNOWN. A aceitação de versão não comprova autorização operacional nem sucesso de um tracert.

## Validação e limites

Testes usam transporte Telegram simulado, PostgreSQL descartável com TLS, roles runtime e FORCE RLS, em rede Docker interna própria e credenciais sintéticas. Não usam o banco ou secrets de produção. Cobertura inclui falhas de conexão versus entrega incerta, limite de tentativas, revogação e isolamento por tenant, resposta autenticada 0.6.6 e rejeição de helper divergente.

Resultado final: **117 casos, 794 asserções, nenhuma falha, dois skips** para os cenários SSH opcionais que exigem o laboratório de protocolo. Filtro: `TelegramClientTest|TelegramTest|PreflightRealFlowTest|OperationalCapabilitiesTest`, executado por `scripts/test.sh` com todas as referências PostgreSQL, rede, credenciais, CA e assets explicitamente voltadas ao laboratório. Pint aprovou os sete arquivos PHP alterados; `git diff --check` passou. Rede e container PostgreSQL exclusivos dessa verificação foram removidos ao finalizar. Logs de execução locais em `/tmp/agent-center-telegram-repair-tests-r2.log`; reprodução técnica em `/tmp/agent-center-telegram-repair-tests.sh`, sem credenciais embutidas. Uma repetição deve criar estado e credenciais sintéticos novos, sem reutilizar banco operacional.

O primeiro laboratório foi iniciado com timezone PostgreSQL diferente do procedimento existente e produziu falhas de validade temporal e identificação. Foi descartado e recriado com `timezone=Europe/Berlin`, conforme o runner de referência; nenhum ajuste de aplicação foi feito para mascarar essas falhas. O teste unitário também identificou diferença da API de exceções na dependência Guzzle local; a implementação final usa TransferStats, sem ler ou registrar mensagens de exceção.

Esta entrega prepara fonte; não publica a Central, não atualiza o Agent e não acessa equipamentos. Não há migration ou mudança de configuração operacional.

## Publicação e rollback

Deploy exige autorização operacional específica conforme AGENTS.md. Antes dele, revisar somente os arquivos desta correção contra as imagens efetivamente implantadas: o checkout contém alterações anteriores que não podem ser promovidas em bloco. Preparar backup pelo procedimento existente, imagem anterior identificada e candidata compatível; validar em laboratório os mesmos contratos e preservar dados, histórico, vínculos e filas.

A comparação read-only dos quatro arquivos da imagem Web confirmou Client e PreflightController iguais à base Git, enquanto Deliver e Preflight contêm diferenças anteriores de laboratório no checkout. Foi preparada uma candidata local em `/tmp/agent-center-telegram-production-candidate/`, com patch de quatro arquivos aplicado conceitualmente à cópia da base implantada, sem trazer métodos ou integrações de laboratório adicionais. Esse diretório é temporário e não é uma imagem publicada. Os quatro arquivos passaram Pint; o cliente dessa candidata também passou os seis testes unitários. Ainda exige validação da imagem e do runtime antes de qualquer publicação autorizada.

Rollback de fonte: reverter somente o diff desta correção. Rollback de uma publicação autorizada: retornar à imagem anterior dos serviços que tiverem sido alterados, preservando banco e volumes. Não executar migration down, restaurar dump sobre produção ou reenviar filas UNKNOWN. A imagem anterior volta a ter as limitações aqui descritas.
