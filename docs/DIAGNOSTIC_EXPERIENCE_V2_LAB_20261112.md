# Experiência de diagnóstico V2 — laboratório Web/Telegram

Resultado: **AGENT CENTER DIAGNOSTIC EXPERIENCE V2 LAB PASS**. Abrange também **DIAGNOSTIC RESPONSE UX LAB PASS**. A correção complementar de continuidade tem [relatório próprio](CONVERSATION_CONTEXT_FIX_LAB_20261112.md).

## Escopo e commits

Implementação em `/root/agent-center-diagnostic-v2-lab`, branch `diagnostic-experience-v2`, base `54cf48f`:

| Commit | Conteúdo |
| --- | --- |
| `245a92c` | Resumos baseados em evidências, acompanhamento durável e apresentação Web |
| `ea9ccf3` | Relação entre pergunta e resposta curta; encerramento explícito |
| `63b14db` | Continuidade do tom através de “Sim, confirmo” |
| `c4d3242` | Regressão de pergunta pendente com interjeição intercalada |

Não foram alterados Task Engine, Classifier, Python Agent, Lifecycle, Observability, RAG, R2, equipamentos ou release 0.6.3 em quarentena. Não há migration nem nova dependência de runtime. Nenhuma publicação ocorreu. O código foi preparado em checkout separado para não sobrescrever as alterações não relacionadas existentes em produção.

## Causa e correção

A sincronização da conversa apresentava essencialmente a classificação e o resumo oficiais. As evidências existentes, o ponto de observação e a continuidade da reclamação não participavam da apresentação básica. A entrega Telegram tratava cada atualização como envio independente e utilizava o rodapé histórico genérico.

`DiagnosticExperience` é um apresentador/read model autorizado pelas permissões nativas. Ele lê conclusão, evidências e estados reais; não agenda operações nem reclassifica resultados. Web e Telegram utilizam a mesma interpretação das evidências.

A aceitação só é informada depois que `Tasks::create` retorna com sucesso. A confirmação é seguida por uma única mensagem de acompanhamento; etapas posteriores atualizam essa mensagem quando possível. A duplicação de confirmação processada mais aceitação foi removida desse caminho.

## Fluxo validado

1. Pedido e confirmação explícita pelo usuário.
2. Validação de destino, Profile, Agent, capacidades e políticas existentes.
3. Task Engine aceita: “Solicitação aceita para youtube.com. Os testes estão sendo preparados e aguardam execução no Agent. Vou apresentar o resultado aqui quando terminar.” O prefixo acompanha o tom autorizado.
4. Tarefa `QUEUED`: “As verificações estão na fila. Aguardando execução no Agent.”
5. Tarefa `CLAIMED`: “O Agent recebeu a solicitação. Aguardando o início da execução das verificações.”
6. Tarefa `RUNNING`: “Há verificações em execução no Agent. O resultado será enviado aqui ao concluir.”
7. Resultado final com classificação oficial, evidências, limitações e botão autenticado.

O polling durável existente do Telegram observa esses estados; não mantém requisição HTTP aberta. O acompanhamento segue a cadência existente, normalmente de um minuto. Transições rápidas podem ser agrupadas e o resultado prevalece sobre progresso atrasado. Não há percentuais inventados nem promessa de mostrar toda etapa intermediária. São permitidas até 12 mudanças de acompanhamento por diagnóstico, além do resultado terminal.

Agent com heartbeat e polling antigos recebe mensagem de ausência de sinal recente, sem atribuição de falha à rede. A ausência desses timestamps não vira uma afirmação inventada de offline. Bloqueio de política é distinguido de teste executado. O timeout operacional mantém a conclusão nativa `INCONCLUSIVO`; cancelamento preserva evidências. Diagnóstico ativo continua protegido do encerramento por inatividade.

## Exemplo executado no Telegram

Dados **sintéticos**, inseridos pelo protocolo nativo no banco de laboratório; nenhum equipamento foi acessado. Os valores abaixo não descrevem o youtube.com real.

> Prontinho, meu rei! Terminei a verificação de youtube.com.
>
> ✅ NORMAL
> Não foram encontradas anormalidades no escopo testado.
>
> O que ficou registrado:
> • Conexão TCP: conexão estabelecida · tempo da sondagem: 7 ms
> • Amostra ICMP (complementar): latência média ICMP: indisponível · tempo da sondagem: indisponível · sem resposta dentro do prazo da verificação
> • DNS A: 1 endereço(s) retornado(s) · tempo da sondagem: 3 ms
> • Resposta HTTPS: HTTP 200 · TLS validado · tempo da sondagem: 42 ms
> • DNS AAAA: 0 endereço(s) retornado(s) · tempo da sondagem: 3 ms
>
> Limites: A investigação representa o ponto de observação do Agent, no período deste diagnóstico. Isso não comprova a saúde de toda a rede nem descarta a lentidão percebida pelo cliente. A resposta HTTPS não mede o carregamento completo da página ou a reprodução de vídeo.

O texto completo e as chamadas de transporte estão em [scenarios.json](evidence/diagnostic-experience-v2-20261112/scenarios.json). O exemplo registra a etapa de rota dispensada como não executada, separadamente das verificações com evidência. A mesma mensagem de acompanhamento, ID sintético `102`, recebeu as quatro edições de fila, recebimento, execução e resultado. Repetir a sincronização não produziu nova entrega.

“Mas continua lento para os clientes” mantém o diagnóstico como contexto e pergunta sobre os clientes afetados. “Quais testes você fez?” responde com as evidências disponíveis. Nenhuma dessas perguntas inicia nova tarefa ou cria confirmação implícita. Aprofundamento explícito continua sujeito à confirmação operacional existente.

## Classificações preservadas

| Classe oficial | Apresentação |
| --- | --- |
| `NORMAL` | Texto oficial obrigatório sobre ausência de anormalidades **no escopo testado**; não declara saúde global da rede |
| `INDICIO_DE_FALHA` | Mostra a evidência observada, como conexão recusada; não atribui causa ou culpado |
| `INCONCLUSIVO` | Expõe ausência de evidências ou impedimento operacional; não inventa testes/valores |
| `ERRO_DE_EXECUCAO` | Expõe falha operacional da ferramenta; não a apresenta como falha da rede |

As quatro fixtures são classificadas pelo Classifier existente e comparadas com a conclusão original antes e depois da apresentação. Não há classificação alternativa na camada visual. A conclusão executiva usa o registro oficial, evitando a tradução histórica que podia reinterpretar uma classe.

## OpenAI, sanitização e fallback

O apresentador utiliza o cliente OpenAI existente, mediante binding `diagnostic_explanation` habilitado, versão publicada e ambas as autorizações `allow_external_context` e `allow_conversation_language`. Sem essa autorização, usa a apresentação determinística completa. Não habilita bindings nem amplia políticas de produção automaticamente.

O modelo escolhe **identificadores de introduções, conectivos e formas de apresentar fatos aprovados**, incluindo a ordem dos fatos fornecidos. Não se aceita prosa livre do modelo para substituir medições ou conclusão: a aplicação renderiza os valores originais. Campos adicionais, escolhas desconhecidas, fatos omitidos/duplicados ou saída inválida causam fallback. Portanto, o modelo auxilia a apresentação; não reescreve livremente as evidências.

O contexto enviado limita-se a trechos autorizados e sanitizados, tom, classificação e fatos de apresentação. Endereços brutos das evidências não entram nesse payload. O código conserva os limites existentes de três chamadas por usuário/minuto, 12 por tenant/minuto e 12 por conversa, compartilhados com o gerador conversacional; timeout máximo dez segundos, saída máxima 1.024 tokens, `store=false`, sem ferramentas. A sincronização repetida não gera nova chamada para o mesmo resultado.

Os testes cobrem saída maliciosa/malformada, métricas inventadas, timeout e fallback. A prova adicional de continuidade utilizou OpenAI real em dois canais e está no relatório complementar. O binding de diagnóstico usado nos testes não é instalado em produção por esta entrega. Fontes RAG e a interpretação manual existente continuam nos respectivos caminhos, com as permissões originais.

A [prova final do resultado com OpenAI real](evidence/diagnostic-experience-v2-20261112/live-result.json) também retornou `ASSISTED`: 683 tokens de entrada e 45 de saída. O modelo escolheu a ordem DNS A, DNS AAAA, TCP, HTTPS e ICMP complementar, mantendo `NORMAL`, HTTP 200 e 42 ms da fixture. O binding específico e seu prompt foram criados pelas APIs nativas somente dentro da transação de laboratório, usando o modelo já autorizado; a transação foi revertida. Houve quatro chamadas reais no conjunto das provas, incluindo uma execução preliminar cuja captura precisou ser corrigida no harness; cada invocação foi limitada e nenhuma iniciou tarefas.

## Entrega Telegram e segurança

Reutiliza outbox, filas e scheduler existentes. A cada entrega são revalidados tenant, proprietário, vínculo verificado, geração do bot, grupo/tópico e permissões do diagnóstico. Mensagens de acompanhamento atrasadas são suprimidas quando há estado mais novo da mesma investigação.

`editMessageText` usa o ID conhecido da mensagem entregue. “Message is not modified” é tratado como sucesso. Um erro definitivo de mensagem indisponível admite um novo envio protegido; as edições seguintes passam a usar esse novo ID. Timeout de edição pode ser repetido com segurança no mesmo ID, com limite de tentativas. Uma primeira entrega ambígua continua `UNKNOWN`, sem reenvio automático que poderia duplicar resultados. Um worker interrompido durante edição conhecida pode ser recuperado pelo sweep existente.

O botão **Ver diagnóstico completo** abre `/diagnostics/{uuid}` pelo endereço HTTPS configurado e exige autenticação e Gate existentes. Não há token na URL nem página pública. A prova de navegador verifica redirecionamento ao login e `404` para outro tenant. O rodapé “Histórico:” não é acrescentado ao acompanhamento; `/historico` e referências verificáveis RAG permanecem preservados.

Referência da operação de edição: [Telegram Bot API — editMessageText](https://core.telegram.org/bots/api#editmessagetext).

## Interface Web

Resumo executivo com classificação, destino, Agent executor, estado real, verificações com evidências, métricas disponíveis, limitações e timeline. Plano preparado é distinto de primeira execução. Etapas dispensadas/bloqueadas não são listadas como testes realizados. Campos ausentes aparecem como indisponíveis. Detalhes técnicos e evidências brutas existentes permanecem expansíveis. Não foi alterado o cálculo técnico de duração.

CSS novo e restrito aos componentes V2; temas e componentes existentes preservados. Navegador validou larguras 1.440, 768 e 390 em claro/escuro para as quatro classes: **24 layouts**, sem overflow horizontal ou erro JavaScript. Também verificou os dois históricos contextuais e acesso entre tenants.

- [Normal — desktop claro](evidence/diagnostic-experience-v2-20261112/diagnostic-NORMAL-1440-light.png)
- [Normal — celular escuro](evidence/diagnostic-experience-v2-20261112/diagnostic-NORMAL-390-dark.png)
- [Indício — desktop escuro](evidence/diagnostic-experience-v2-20261112/diagnostic-INDICIO_DE_FALHA-1440-dark.png)
- [Inconclusivo — celular claro](evidence/diagnostic-experience-v2-20261112/diagnostic-INCONCLUSIVO-390-light.png)
- [Erro operacional — celular escuro](evidence/diagnostic-experience-v2-20261112/diagnostic-ERRO_DE_EXECUCAO-390-dark.png)

## Testes e evidências

**471 testes / 3.466 asserções / 44 arquivos PASS**, executados pelo `scripts/test.sh` em três grupos disjuntos para o mesmo PostgreSQL descartável, serialmente, com o papel de runtime e FORCE RLS. Não se utilizou o banco de produção. Migrações e rollback do banco de teste também passaram.

| Grupo | Testes | Asserções |
| --- | ---: | ---: |
| 0 | 166 | 1.130 |
| 1 | 149 | 1.127 |
| 2 | 156 | 1.209 |

Cobertura: aceitação/fila/claim/execução/conclusão; quatro classificações; métricas ausentes; bloqueio de política; indisponibilidade do Agent; cancelamento; deadline; OpenAI indisponível/saída inválida; Telegram indisponível/429/timeout; edição indisponível; duplicidade; recuperação de worker; revogação; dois tenants; RBAC/RLS; RAG; confirmação explícita; idempotência; seguimento sem tarefa nova; inatividade protegendo execução. Os testes de concorrência do Task Engine e de fechamento de conversa passaram em processos separados.

Pint: 16 arquivos PHP alterados PASS. `npm ci` e build Vite em container PASS. Verificação de diff e scan por padrões de segredos no escopo alterado/evidências fazem parte do registro final; não se afirma uso de um scanner externo inexistente. Nenhuma credencial ou exportação privada é incluída no Git.

- [Resumo verificável](evidence/diagnostic-experience-v2-20261112/validation.json)
- [Validação do navegador](evidence/diagnostic-experience-v2-20261112/browser.json)
- [Hashes de imagem e escopo protegido](evidence/diagnostic-experience-v2-20261112/scope-and-images.json)
- [Produção preservada](evidence/diagnostic-experience-v2-20261112/production-preserved.json)

Logs completos de testes, build, Pint e os 24 screenshots ficam em `/root/agent-center-diagnostic-v2-20261112`, fora do runtime de produção. Os artefatos compartilhados neste commit contêm somente dados sintéticos e resultados sanitizados.

## Candidata, rollout e rollback

Candidata **somente laboratório**: `agent-center-central:diagnostic-experience-v2-lab-20261112`, imagem `sha256:04c78a7c6c0a2ab12b7183e9d8d312a8c1e5e7d098d20b03ac16730e1694db49`. Derivada da imagem real vigente, com sobreposição apenas dos arquivos de runtime aprovados. Seus hashes coincidem com o código commitado; a validação Web executou essa imagem sem bind mount de código da aplicação.

Rollback compatível **somente laboratório**: `agent-center-central:diagnostic-experience-v2-rollback-lab-20261112`, imagem `sha256:6909e71b85665a36fe721927b4ebfca94245599a3f3ee912f5a6e83094c017ae`. Restaura Engine, gerador conversacional, parser, sanitização, seletor de tom, controller e views anteriores; conserva `Telegram\Client`, `Deliver` e `Sweep` V2 para finalizar/editar notificações já persistidas sem flood. Os arquivos restaurados e os arquivos de entrega mantidos foram conferidos por hash. Não foi aplicada em produção.

Plano condicionado a nova autorização operacional específica:

1. Reconciliar estes commits com a versão vigente em checkout de release isolado. Preservar alterações posteriores; não copiar o checkout inteiro sobre o repositório de produção.
2. Executar prechecks e regressões da versão reconciliada. Registrar imagens/digests, filas e scheduler atuais; verificar backup e recuperação de PostgreSQL/storage antes da troca. Backup de produção não foi executado nesta tarefa LAB.
3. Revisar bindings autorizados. Resultado sem binding específico continua pelo fallback completo; não criar autorização silenciosamente. Nunca levar transporte fake ou fixtures para produção.
4. Publicar exclusivamente a imagem reconciliada na Central Web, fila relevante e scheduler, conforme procedimento vigente; manter Agent e worker de diagnóstico fora da troca.
5. Conferir HTTPS/readiness, PostgreSQL, filas, scheduler, webhook, Operations Health e auditoria. Fazer teste de Telegram somente com destinatário autorizado e confirmação explícita, sem sondagens reais desnecessárias.
6. Se houver regressão, aplicar a variante de rollback compatível reconciliada com a versão publicada; preservar conclusão, histórico e outbox. Não apagar filas/resultados nem reexecutar testes como compensação. Não executar rollback de migrations: não há migration nova.

Produção permanece em `54cf48f`, imagem `agent-center-central:telegram-final-v1-20261112`. Não houve deploy, alteração de scheduler produtivo, mudança de política de 15 minutos ou início de WhatsApp. Encerrado após a entrega LAB.
