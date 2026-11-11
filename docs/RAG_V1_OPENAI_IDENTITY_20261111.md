# OpenAI / RAG V1 — consulta de identidade e vinculação dedicada

AGENT CENTER RAG V1 BLOCKED

Motivo: /v1/me identifica organização associada, mas não comprova o projeto e a chave administrativa usados. Também não foi fornecido o ID da organização beneficiada para comparação exata. A autorização de publicação continua condicionada a esse gate.

## Evidência desta execução

Uma única requisição GET https://api.openai.com/v1/me no backend Laravel operacional, tenant 1, usando a credencial existente do SecretVault e o binding knowledge_assistance. Allowlist e DNS/IP validados pelo NetworkPolicy existente, DNS pinning, TLS com validação de certificado/hostname, sem proxy, redirect ou retry; timeout de 15 segundos e resposta limitada a 64 KiB. Token somente em memória. Nenhuma inferência paga executada.

HTTP 200; 1161 ms; início 2026-11-11T14:30:16+00:00. Organização associada: org-C2uAqILvlucSffzzVtuwCjuO. Nenhum identificador de projeto ou chave administrativa reconhecido foi extraído. Não foi persistido o corpo integral, nome, email, ID de usuário ou secret. Evidência: docs/evidence/rag-v1-identity-20261111/me-sanitized.json.

Provider Laravel: b6cf9cf8-ff17-49ed-b1a4-6fb757158ed8. Binding: 8600f9a2-ab78-4f03-8bb8-de8dc2ebb6ab. A chave não foi alterada. Associação de usuário/organização não comprova escopo de projeto da chave nem titularidade dos créditos. As três chamadas históricas não foram reinvestigadas.

## Procedimento administrativo preparado — não executado

1. O operador acessa https://platform.openai.com/ e seleciona a organização beneficiada. Em Settings / Organization / General, registra o ID exato; em Billing / Credit grants, confere grant e validade. Guarda evidência sanitizada que vincule o grant ao mesmo ID da organização.
2. Seleciona o projeto correto (Default project somente se esse for o escolhido), registra nome e ID administrativo do projeto, demonstrando que pertence à organização acima.
3. No projeto, abre API Keys e cria uma chave dedicada ao Agent Center. Registra o ID administrativo da chave, projeto, organização, responsável e data em registro administrativo protegido. Nome amigável e prefixo mascarado, isoladamente, não provam identidade. Se o painel não mostrar o ID, um administrador deve obtê-lo pela listagem administrativa oficial do projeto; não cadastrar Admin API key na Central nem enviar essa credencial pelo chat.
4. Usa permissões Restricted: conceder apenas criação de Responses, endpoint usado pelo candidato para geração JSON; não conceder administração, arquivos, ferramentas ou outros endpoints sem necessidade comprovada. Conferir acesso ao modelo gpt-6.1-sol e limites do projeto. A chave atual não deve ser revogada: Hermes pode atender outras aplicações.
5. Antes de substituir, o operador concede autorização específica indicando tenant 1, Provider OpenAI existente b6cf9cf8-ff17-49ed-b1a4-6fb757158ed8 e a associação organização/projeto/ID da nova chave. A autorização prévia de deploy não é interpretada como autorização de rotação.
6. Após essa autorização, o operador entra na Central por HTTPS com conta habilitada a administrar inteligência e tenant correto. Em LLM / Provider OpenAI existente, cadastra o secret no campo protegido API key e salva pelo formulário nativo (Auth, sessão, CSRF, Gate e SecretVault). Não criar provider/model/binding duplicados; não inserir IDs em campos de configuração proibidos. Não transmitir o secret pelo chat, logs, URLs, Git ou documentação. Manter o registro administrativo seguro separado, pois o cadastro atual não contém campos de organização/projeto/ID administrativo da chave.
7. Após cadastro autorizado: conferir o registro administrativo contra a credencial fornecida pelo operador no ato do cadastro, Provider existente, disponibilidade de descriptografia, modelo gpt-6.1-sol, Prompt publicado e binding knowledge_assistance, todos no mesmo tenant. Não usar secret_key_id como ID OpenAI: ele identifica a chave mestre de criptografia do SecretVault.
8. Executar no máximo uma inferência controlada nessa etapa, sem retry, com dados sintéticos pelo adaptador candidato; registrar HTTP, latência, modelo, tokens e identificador de requisição quando disponível. Corroborar uso atribuído à nova chave/projeto. Não disparar o botão Testar antes disso: ele consumiria a chamada adicional. Falha exige parar sem troca automática de modelo.
9. Somente com o vínculo comprovado, retomar prechecks e publicação autorizada: backup/restauração verificados, PostgreSQL/FORCE RLS, regressões, migration homologada, rollback preservando dados, promoção somente RAG, smoke com fontes e isolamento, HTTPS/login/readiness e Operations Health. Definir previamente o orçamento das chamadas necessárias à homologação/smoke; não repetir testes pagos desnecessariamente.

Documentação oficial consultada:
- https://help.openai.com/en/articles/9132009-how-can-i-view-the-users-or-organizations-associated-with-an-api-key
- https://developers.openai.com/api/docs/guides/api-keys

Nenhuma substituição/revogação de credencial, deploy, migration, mudança no Agent, Lifecycle, Observability ou R2 foi executada. Evidências anteriores, candidato e plano de rollback permanecem preservados; seus estados não foram reaprovados nesta consulta de identidade.
