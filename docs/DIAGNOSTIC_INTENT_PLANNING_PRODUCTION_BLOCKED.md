# PRODUCTION BLOCKED — dependência de Observability ausente

## Decisão

Não publicar. Os commits homologados `c84a05c`, `4f97956` e `1dd33c4` não foram
integrados no checkout de produção nem ativados nos containers de produção.

Ao construir candidatos incrementais sobre as imagens atuais e reproduzir
“Quero avaliar a rota para 8.8.8.8” em transação READ ONLY, o planejamento falhou:

```text
Class "App\Agent\Observability" not found
PathReadiness.php:17
```

`PathReadiness` da versão homologada chama `Observability::view()` para comprovar
helper, capability e política efetiva. A classe existe no baseline do laboratório
(commit `df15c23`), mas está ausente das duas bases efetivamente publicadas:

| Serviço | Base preservada | Dependência |
| --- | --- | --- |
| Web, worker da fila e scheduler Telegram | `agent-center-central:greeting-restart-20261112` | `App\Agent\Observability` ausente |
| Worker de diagnósticos | `agent-center-central:agent064-workers-20261110` | `App\Agent\Observability` ausente |

A autorização limita a integração aos commits homologados e proíbe modificar
Observability. Não foi incluído esse módulo, criado um shim, removida a verificação
de segurança ou inventada autorização de destino para contornar a dependência.
A integração da dependência exige escopo separado; outra opção é homologar uma
versão de Planning que seja compatível com as bases realmente publicadas.

## Prechecks concluídos

- Comparadas as imagens em execução com o baseline homologado. Os arquivos de
  diagnóstico existentes correspondiam à base; os arquivos de conversa do worker
  de diagnósticos eram diferentes e foram preservados nos candidatos.
- Construídos candidatos incrementais, sem ativação: 13 arquivos autorizados na
  imagem Web e somente 9 arquivos de Diagnostics no worker de diagnóstico.
- Parser da frase exata: reconheceu ROUTE_PATH_ANALYSIS. O preview de plano foi
  bloqueado pela dependência ausente, antes de qualquer confirmação ou tarefa.
- Backup local `20261112T141833Z`, com integridade verificada e sem prune/R2.
- Restore real em banco separado: 16 conjuntos protegidos comparados, FORCE RLS
  verificado, configuração de Agents preservada e credencial OpenAI decifrável com
  a chave do backup. O banco temporário de restore foi removido pelo runner.
- Pint e secret scan do índice: PASS. Build incremental das duas imagens: PASS.
- QA de laboratório: 30 verificações de páginas, larguras e temas; sem erros JS
  ou overflow, acesso de outro tenant retornando 404.

## Regressão desta tentativa

A reexecução completa terminou com 488 testes, 3.671 asserções e uma falha:
`LlmHttpTest::test_real_timeout_connection_refused_tls_failure_and_cancellation`,
linha 116. O teste de transporte TLS esperava `transport_failure` e recebeu
`connection_refused`. Não foram modificados os testes nem o transporte LLM para
forçar aprovação. Os testes de concorrência posteriores do runner não foram
executados nesta tentativa, pois o runner encerrou ao receber essa falha.
Essa rodada não é declarada PASS; o resultado homologado anterior permanece
documentado separadamente no laboratório. A ausência de Observability nas imagens
publicadas já impede a publicação, independentemente dessa falha de regressão.

## Produção preservada

Checkout em `36f51c0`, compose idêntico ao snapshot e hashes de todos os arquivos
locais preexistentes preservados. Nenhum serviço de produção foi recriado.

Health verificado depois do bloqueio:

- HTTPS, formulário de login e readiness: OK.
- Operations Health: VERDE.
- Filas: zero pendências e zero falhas.
- Webhook Telegram: registrado, URL HTTPS correta, zero updates pendentes,
  sem último erro informado pela API.
- RAG: READY_EMPTY, índice vazio; credenciais habilitadas decifráveis.
- Runtime sem superuser/bypassrls e tabelas FORCE RLS preservadas.

Não houve publicação, portanto rollback não foi acionado. Imagens anteriores,
compose e backup estão preservados. O teste Web/Telegram pós-deploy e screenshots
de planejamento em produção não foram executados: não existe deploy aprovado
pelos prechecks. Capturas feitas nesta tentativa são somente de laboratório.

Nenhuma ACL/política foi ampliada. Nenhuma sondagem real em 8.8.8.8 foi executada.
Python Agent, Lifecycle, Observability, release 0.6.3 em quarentena, R2 e
equipamentos não foram modificados.

Evidências operacionais restritas: `/root/intent-planning-production-20261112`.
