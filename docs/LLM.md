# LLM foundation — interpretação sem autoridade

## Limite do domínio

Laravel mantém a classificação oficial e o estado técnico. Interpretation é um consumidor read-only de diagnostic_conclusions e persiste somente diagnostic_interpretations/interpretation_sources. Não importa o Task Engine, não cria tasks, não seleciona comandos e não chama Agent/Skills. Texto do provider é escapado na UI e identificado como complementar, depois da conclusão/evidências/etapas oficiais.

Fallback é obrigatório: sem binding, provider, secret, prompt publicado, conexão válida ou output válido, a classificação/resumo/próximos passos determinísticos permanecem. A conclusão técnica não chama LLM para finalizar. Chamadas só por POST opcional autorizado; viewer não inicia chamadas. Cada interpretação é append-only e copia a classificação oficial, sem atribuí-la a uma saída do modelo.

## Providers, Models e bindings

Todos são tenant-specific. provider_type atual: openai_compatible; base URL administrativa termina no prefixo da API, por exemplo /v1. Client/transport/network policy são separados do domínio de diagnóstico. Outros protocolos exigem novo adaptador revisado e tipo em allowlist; não há carregamento de classes definido pelo banco.

Models têm identificador fornecido pelo administrador, enabled e context_limit opcional. Limite de saída padrão usa max_completion_tokens; a opção legada max_tokens é uma configuração fechada por model. Compatibilidade com JSON mode e endpoint Chat Completions deve ser validada com o provider escolhido. Nenhum modelo comercial foi inventado/configurado automaticamente.

Binding por purpose combina provider/model/prompt publicado do mesmo tenant. “Preparar padrão” cria um prompt publicado padrão e binding diagnostic_explanation, após verificar provider/model habilitados e secret recuperável; o administrador autoriza explicitamente envio de contexto sanitizado. Operator não precisa configurar essas relações. Sem consentimento no binding, nenhum contexto é enviado.

Contrato inicial: POST /chat/completions; stream=false, n=1; mensagens textuais e JSON output. Sem tools, functions, URLs de callback ou headers arbitrários em config. Referência primária do adaptador: [OpenAI Docs — Create chat completion](https://developers.openai.com/api/reference/resources/chat/subresources/completions/methods/create).

## HTTP e SSRF

LLM_ALLOWED_HOSTS é uma lista de hostnames exatos na configuração de implantação, fora do banco. Vazia por default: egress LLM bloqueado. Exemplo de configuração é uma decisão do administrador, não uma URL criada pela aplicação.

Somente HTTPS/443, sem usuário/senha/query/fragmento, IP literal, localhost ou portas alternativas. Todos os IPs A/AAAA resolvidos precisam ser públicos; redes privadas, loopback, link-local, CGNAT, metadata, mapped IPv6, NAT64, 6to4, multicast e faixas especiais são bloqueados. Cada retry resolve/valida de novo e cURL recebe o IP validado via CURLOPT_RESOLVE, mantendo o hostname original para TLS/SNI. Proxy de ambiente e redirects desativados. Não há exceção de rede privada configurável pela UI.

Timeout de conexão até 1,5s, total HTTP por tentativa entre 1–15s; teste de conexão até 3s por tentativa. Uma repetição apenas para 429/5xx, com 100ms de espera. DNS usa o resolver da máquina; sua latência também depende do resolver/OS. Não há retry infinito ou processo em background. O transporte suporta cancellation callback e aborta antes de DNS quando já cancelado; sem API de cancelamento remoto, a interrupção local não garante cancelamento de cobrança remota.

Entrada até 48000 bytes, mais orçamento conservador derivado de context_limit/limite de saída. Knowledge até 12000 bytes. Resposta HTTP até 32768 bytes. Output local exige três campos, limites 2000/8000/6×500 caracteres, finish_reason=stop e nenhum tool/function call. JSON inválido, corpo incompleto, timeout, TLS inválido, 401, 429 e 5xx geram códigos fixos sanitizados. Sem exception HTTP com Authorization/body nos logs.

Rate limit: seis solicitações/minuto por usuário, 30/minuto por tenant; lock de 40s por tenant/diagnóstico durante chamada. Logs de request completos não são coletados. Contagem/status/timestamp persistidos nas interpretações permitem métricas sem registrar payloads externos brutos.

Teste de conexão usa texto mínimo fixo, sem dados reais de diagnóstico/Knowledge, e só devolve status sanitizado. Não ocorre chamada externa na suíte: DNS/transport mocks e servidor TLS fake local. Policy de loopback da fixture existe apenas em tests, não na produção.

Referência de SSRF: [OWASP SSRF Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Server_Side_Request_Forgery_Prevention_Cheat_Sheet.html).

## Secrets e master key

AES-256-GCM pelo Illuminate Encryption/Encrypter. Chave dedicada de 32 bytes aleatórios em secrets/llm-master.key (base64), fora do Git e do Docker context. Diretório host 0700; arquivo 0600 e UID 33 para bind readonly no web, /run/secrets/llm-master.key. APP_KEY de sessão não é reutilizada.

Ciphertext autenticado contém tenant/provider/secret; decriptação exige correspondência de identidade para impedir transplante entre providers. Banco guarda ciphertext e fingerprint da chave mestre, nunca secret em texto claro. UI retorna somente configured/not configured; api_key não entra em flash, serialização ou auditoria. O cliente remove a API key atual de inputs/outputs por coincidência literal, além de redaction de padrões conhecidos.

Referência da primitiva: [Laravel Encryption](https://laravel.com/framework/docs/13.x/encryption). Não é possível prometer remoção de todo dado sensível em texto livre: administradores devem revisar Knowledge e o consentimento de envio ao provider. O provider externo tem sua própria política de retenção.

Rotação API key: substituir pela UI; nunca recuperar valor salvo. Rotação master key planejada:

1. Backup completo e prova de restore; preservar a chave atual em arquivo anterior privado.
2. Gerar nova chave de 32 bytes, modo 0600/UID33; trocar bind e configurar LLM_PREVIOUS_KEY_FILES com paths antigos montados readonly (overlay de implantação revisado).
3. Recriar web; executar `php artisan agent-center:rotate-llm-secrets` (preflight sem mudança).
4. Executar com `--apply`; cada provider é travado e recriptografado, com audit secret_updated sem conteúdo.
5. Novo backup e restore isolado. Reter chaves anteriores enquanto qualquer backup depender delas; não remover por mero sucesso de rotação.

O comando pré-valida todos os secrets; erro posterior interrompe e exige manter ambas as chaves. A rotação é retomável; não promete transação única entre todos os tenants.

## Backup, restore e proveniência

pg_dump integral inclui itens, snapshots, eventos, prompts, providers/models/bindings, ciphertext, interpretações e fontes. backup.sh copia master key separadamente para backups/secrets/<timestamp>.llm-master.key, 0600; não incluir no Git/config tar. O keyring completo do diretório secrets/ também entra em arquivo .llm-keyring.tar.gz privado: guardar chaves anteriores dentro desse diretório. Retenção local de sete dias acompanha os dumps. Replicar dumps/configs e chave para destinos externos privados/criptografados, preferencialmente com controle de acesso separado. Destino externo não foi fornecido nesta fase.

Sem a chave correta, secrets não são recuperáveis; APP_KEY não substitui LLM master key. Restore de um dump antigo deve usar a chave correspondente ou key ring de versões. A fixture/proof em scripts/lab demonstra recuperação com a chave copiada do backup; valores não são emitidos.

Proveniência imutável: diagnostic_id/classification, prompt_version_id, provider/model IDs e snapshot (inclui model_identifier), timestamp, digest do contexto e knowledge_item/version IDs com título/revisão. Não há resposta externa bruta ou Authorization em audit. Knowledge archive não altera a interpretação histórica.

## Fora do escopo

Sem RAG vetorial, embeddings, pgvector, semantic search, Conversation, mensageria, MTR ou device access. Python Agent 0.5.2 não foi alterado. Interpretação pode ser semanticamente errada mesmo com schema correto; ela não substitui evidência nem classificação determinística.
