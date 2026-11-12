# DIAGNOSTIC INTENT PLANNING LAB PASS

Implementação: `c84a05c` e `4f97956`, branch `lab/diagnostic-intent-planning-v1`.
Baseline `9f661dc` preserva a correção de saudação já publicada anteriormente.
Nenhuma publicação foi realizada nesta tarefa.

## Verificações

| Verificação | Resultado |
| --- | --- |
| Laravel/PostgreSQL, suíte completa | 488 testes, 3.668 asserções, zero falhas/erros/skips |
| Últimas alterações: detalhes, thresholds, MTR, planejamento, tom e Telegram | 33 testes, 280 asserções, PASS |
| Claims concorrentes, worker/result, fencing e idempotência | PASS em processos separados |
| Encerramento por inatividade concorrente | PASS em processos separados |
| PostgreSQL/FORCE RLS e isolamento entre tenants | PASS na suíte |
| Pint | 28 arquivos, PASS |
| PHP/Composer e Vite | Imagem de laboratório construída com sucesso |
| Smoke da imagem sem rede | Pedido exato reconhecido; cobertura inconclusiva quando rota não executada; sem artefato JUnit |
| Integridade da imagem | 13 hashes de código/template iguais ao workspace |
| Interface | 30 verificações, 390/768/1440 px, claro/escuro, sem overflow/erros JS |
| Acesso cruzado no navegador | HTTP 404 |
| Secret scan do índice Git | PASS pelo scanner do repositório |
| Produção / sondagens reais em produção | Nenhuma publicação; zero sondagens |
| Python Agent, Lifecycle, Observability, R2 e equipamentos | Sem alterações |

## Evidências

- [Validação estruturada e hashes](validation.json)
- [Resumo de checks](checks.txt)
- [JUnit completo](final-junit.xml)
- [Planos antes/depois e prova Web sintética](plans-and-web-proof.json)
- [Transcript Telegram sintético, por ingresso/worker/transporte falso](telegram-proof.json)
- [QA do navegador](browser.json)
- [Rota MTR, celular claro](route-390-light.png)
- [Rota MTR, desktop escuro](route-1440-dark.png)
- [Conectividade NORMAL sem cobertura de rota, celular escuro](mismatch-390-dark.png)
- [Conectividade NORMAL sem cobertura de rota, desktop claro](mismatch-1440-light.png)
- [Confirmação, celular](confirmation-390-light.png)
- [Helper indisponível, celular](blocked-390-dark.png)

O JUnit completo antecede o complemento final de apresentação em “mais detalhes”
e o ajuste de thresholds de cobertura. Esses complementos passaram pela rodada
posterior de 33 testes. Os 13 hashes de imagem registram o código final, incluindo
esses complementos. As contagens da rodada posterior repetem parte da suíte;
não representam 521 testes distintos.

Todos os destinos, conversas, usuários e resultados das provas são fixtures de
laboratório. Não há inferência externa, chamada real ao Telegram ou sondagem do
IP 8.8.8.8. O secret scan utilizado é o scanner básico existente do índice Git;
não substitui uma auditoria especializada de todos os tipos possíveis de segredo.

## Reexecução

No checkout, executar `scripts/test.sh` com os overrides de laboratório:

```bash
TEST_PG_CONTAINER=rag-v1-postgres \
TEST_NETWORK=rag-v1-lab \
TEST_OWNER_ENV=/root/rag-v1-lab/owner.env \
TEST_RUNTIME_ENV=/root/rag-v1-lab/runtime.env \
TEST_CA_FILE=/root/rag-v1-lab/no-ca \
TEST_DIAGNOSTIC_CONCURRENCY=1 \
TEST_CONVERSATION_IDLE_CONCURRENCY=1 \
scripts/test.sh
```

O script cria/remove apenas o banco de teste separado. O laboratório de interface
tem seu próprio banco `intent_planning_v1_lab`, servidor em loopback e credenciais
privadas fora do Git. `scripts/intent-planning-lab.sh` recusa sobrescrever o estado
ou container existentes. Não usar os envs ou containers de produção para testes.

Causa raiz, matriz, exemplos e rollback: [relatório](../../DIAGNOSTIC_INTENT_PLANNING_V1.md).
