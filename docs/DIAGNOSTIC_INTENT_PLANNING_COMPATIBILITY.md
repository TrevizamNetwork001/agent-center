# Diagnostic Intent Planning — compatibilidade com a Central publicada

## Causa da dependência e correção

`PathReadiness` chamava diretamente `App\Agent\Observability::view()`. Isso exigia
um relatório administrativo de capabilities/helper, revisão/digest aplicado e um
resultado de permissão por destino/protocolo. A classe estava no baseline do
laboratório, mas não nas imagens operacionais Web ou diagnostic-worker. O Planner
falhava antes de produzir uma resposta útil. Copiar a classe não resolveria o
contrato: os endpoints publicados também não retornam esse resultado efetivo.

O novo adaptador interno `Diagnostics\OperationalCapabilities` consulta apenas os
contratos publicados: modelo Agent, heartbeat, anúncio de capabilities, protocolo
de tarefas, `Protocol::executionCapabilities()`, `Protocol::policy()` e
`ManagedSettings::current()`. Não importa classes, rotas, relatórios, views ou
metadados administrativos de Observability. Não há provider/binding obrigatório,
novo schema, Agent Python ou protocolo futuro a instalar.

O snapshot V1 separa disponibilidade operacional de autorização. Status,
heartbeat recente, versão reconhecida e capability anunciada são disponibilidade;
não são uma decisão de autorização para um IP. `auto_public_targets`, redes
gerenciadas e um resultado histórico não são usados para reconstruir ACLs.

O adaptador não concede autorizações e não é um segundo motor de ACL. A decisão
efetiva continua no mecanismo já existente do Agent/helper, que aplica suas
políticas antes de sondar. Como o contrato atualmente publicado não retorna uma
prova prévia dessa decisão para a Central, o adaptador retorna `UNKNOWN` para a
permissão por destino e para a saúde atual do helper. A confirmação de capability
MTR é preservada como anúncio de disponibilidade, sem promovê-la a autorização.

**Limitação operacional explícita:** nestas imagens atuais, o planejamento de rota
é apresentado, mas a execução fica bloqueada por `effective_policy_unverified`.
Não há tarefa, sondagem ou pedido de confirmação executável. Autorizar MTR na
Central exigirá uma prova operacional de autorização efetiva em um contrato
separadamente revisado; esta entrega não implementa esse mecanismo futuro.

## Planejamento preservado

Pedido: “Quero analisar a rota para 8.8.8.8”.

```text
Intenção: ROUTE_PATH_ANALYSIS
Profile planejado: MTR_ICMP
Etapas planejadas: mtr_icmp
HTTP/HTTPS/DNS/ping adicional: nenhum
Autorização efetiva: UNKNOWN
Etapas executadas: nenhuma
Cobertura do objetivo: NÃO EXECUTADA
Solicitação: INCONCLUSIVA
```

MTR TCP continua sendo selecionado somente quando solicitado como protocolo TCP,
nos limites existentes de TCP/443. A falta de MTR não provoca troca automática
por traceroute, ping ou HTTPS. Traceroute ICMP só é planejado se o usuário o pedir
explicitamente. Uma alternativa descrita na conversa não executa outro teste.

As demais categorias continuam separadas: conectividade básica, disponibilidade
web, DNS, porta TCP, latência/perda e lentidão geral. Permanecem a classificação
técnica determinística, cobertura, evidências, limitações, confirmação explícita,
idempotência e isolamento entre tenants. Agent offline, capability ausente,
helper comprovadamente indisponível e `policy_denied` não são tratados como rede
saudável. Provas sintéticas de permissão nos testes usam um adaptador fake em
`tests/Support`, excluído das imagens; não são permissões disponíveis em produção.

## Causa HTTP/TLS reproduzida

A fixture publicava a porta com `file_put_contents(port, valor)`, enquanto o teste
esperava apenas `file_exists(port)`. Entre a criação/truncamento do arquivo e a
escrita dos bytes, o leitor podia obter string vazia e convertê-la em porta `0`.

A intercalação foi reproduzida com uma pausa controlada nesse intervalo, usando
a implementação antiga de transporte: zero bytes lidos, porta zero e erro público
`connection_refused`. Portanto a falha ocorria ao conectar TCP ao endpoint inválido
da fixture, antes do handshake TLS esperado pelo teste. A reprodução foi somente
em loopback, com namespace de rede externa desativado.
O log anterior não registrava a porta lida: a prova demonstra uma intercalação
concreta capaz de produzir o erro observado, sem renomear toda falha TCP como TLS.

Correções da fixture e do teste:

- publicar a porta completa em arquivo temporário e renomeá-lo atomicamente;
- validar porta numérica entre 1 e 65535 e processo ainda ativo antes de conectar;
- aceitar TCP separadamente e registrar o estágio do handshake TLS;
- manter listener limitado disponível após handshake rejeitado;
- testar publicação atrasada propositalmente, certificado confiável, certificado
  não confiável, TCP recusado, timeout e cancelamento.

A expectativa não foi relaxada. Certificado não confiável continua exigindo
`transport_failure` com errno cURL 60 e estágio `tls_handshake`. Porta fechada
continua exigindo `connection_refused` e estágio `tcp_connect`. Não foi usado
`assertContains` com múltiplos erros aceitáveis para esconder a divergência.

`Llm\TransportFailure` preserva os códigos públicos existentes e acrescenta detalhe
interno limitado: estágio, categoria técnica, errno cURL/OS, resultado da validação
TLS e indicadores de TCP/TLS estabelecidos. `Llm\Client` preserva esses campos.
Não são incluídos URL, hostname, token, corpo de resposta ou mensagem bruta de
exceção. As verificações de certificado e hostname continuam obrigatórias.

## Compatibilidade comprovada nas imagens operacionais

Imagens candidatas de laboratório foram construídas incrementalmente sobre:

| Base operacional | Candidato |
| --- | --- |
| `agent-center-central:greeting-restart-20261112` | `agent-center-central:intent-compat-web-lab-20261112` |
| `agent-center-central:agent064-workers-20261110` | `agent-center-central:intent-compat-diagnostic-lab-20261112` |

Na Web foram incluídos apenas os arquivos de Planning/Conversation/resultado
homologados e esta correção de compatibilidade/HTTP. No diagnostic-worker foram
incluídos somente os arquivos de Diagnostics. Protocol, Agent API, Observability,
Lifecycle, frontend e demais diferenças entre as bases foram preservados.

As duas provas rodaram com o usuário de runtime da imagem, PostgreSQL/RLS de
laboratório, os endpoints nativos de capabilities e heartbeat e um Agent sintético
`0.6.2`. `class_exists(App\Agent\Observability)` precisou retornar falso. A prova
validou MTR como único passo, permissão desconhecida bloqueada e nenhuma tarefa
ou sonda. No worker, o Task Engine concluiu o plano bloqueado como INCONCLUSIVO.
Não foi instalado módulo futuro nem criada migração para fazer a prova funcionar.

A imagem Web também executou a fixture TLS nativamente, sem rede externa: TLS
confiável aceito, certificado rejeitado com causa correta, listener fechado
classificado como TCP. Essa prova passou em oito execuções consecutivas.

## Validação

As evidências finais, contagens, hashes de imagem e commits estão em
`docs/evidence/intent-planning-compatibility/`. A primeira rodada completa num
checkout novo encontrou ausência do manifesto Vite em dois testes de dashboard.
O build foi executado e a suíte foi repetida sem modificar os testes de Auth/RBAC.

Resultado final: **495 testes, 3.775 asserções, zero falhas/erros/skips**. Passaram
também os dois grupos de concorrência em processos separados (Task Engine e
encerramento de conversas). A rodada específica passou com 23 testes e 292
asserções. Pint aprovou 17 arquivos; build Vite, builds incrementais, diff check e
secret scan do índice foram executados. As provas finais das imagens confirmam
os hashes dos arquivos e código não gravável pelo usuário de runtime.

Commits de implementação: `8c6e4c8` (desacoplamento e contrato conservador) e
`a66a8b6` (transporte e fixture HTTP/TLS). Imagens e digests exatos estão no
manifesto de evidências. Os Dockerfiles de referência usam listas explícitas de
arquivos, sem copiar o restante de `app/Agent` ou a árvore inteira da aplicação.

Não houve sondagem real em 8.8.8.8, chamada paga à OpenAI, envio real ao Telegram,
deploy, alteração de ACL, Python Agent, Lifecycle, Observability, R2, quarentena
0.6.3 ou equipamentos.

## Rollout proposto — depende de nova autorização operacional

1. Comparar novamente hashes das bases operacionais e das alterações locais.
2. Executar build antes das regressões; repetir prechecks, backup local e restore
   isolado, com verificação de FORCE RLS e credenciais cifradas.
3. Drenar diagnósticos em andamento e revisar o efeito conservador do bloqueio
   de rota sem prova de permissão. Não ampliar ACLs para viabilizar o teste.
4. Integrar somente os commits aprovados e usar os deltas de cada base: Web/fila/
   scheduler com a base Web; diagnostic-worker com sua própria base preservada.
5. Publicar as imagens revisadas, sem migração, e validar HTTPS, login, readiness,
   filas, scheduler, Telegram, RAG, diagnóstico e Operations Health.
6. Pedir o planejamento de rota nos dois canais sem confirmar ou executar MTR.
   A resposta deve mostrar MTR planejado e o impedimento de autorização, sem
   substituir por site ou sondagem de conectividade.

## Rollback proposto

Preservar os IDs/digests das imagens anteriores e compose. Antes de retornar uma
imagem antiga, interromper novas solicitações, drenar/cancelar por fluxo nativo os
diagnósticos em andamento e desabilitar os três Profiles novos que a versão antiga
não conhece. Recriar somente os serviços da Central, preservando as bases separadas
do worker. Não restaurar banco sobre dados novos, remover históricos, reescrever
conclusões, editar políticas de equipamentos ou apagar Profiles. Se houver trabalho
V1 pendente, manter worker compatível até encerrá-lo com segurança.
