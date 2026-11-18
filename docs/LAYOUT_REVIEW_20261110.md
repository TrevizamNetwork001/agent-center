# Revisão geral de layout — 10/11/2026

Diretriz: [web-development-design-ops](../skills/web-development-design-ops/SKILL.md), adaptada ao Laravel Central. A revisão mantém a identidade atual e concentra as correções em consistência, uso no celular e acessibilidade.

## Escopo

Leitura dos layouts, componentes, CSS/JS compartilhados e telas de autenticação, dashboard, Agents, diagnósticos, perfis, Skills, Knowledge, prompts, LLM, conversas, auditoria, usuários, tenants e backups.

Captura de 34 páginas/estados renderizados pelo Laravel em transações com dados fictícios e PostgreSQL de teste. Inclui guias MTR/tcpdump/nmap, catálogo, releases, preparação de atualização e formulário de manutenção. Nenhuma conexão a equipamento, atualização de Agent ou publicação de release é executada.

O Chromium abre o HTML capturado com assets locais e rede desativada. A matriz usa 360, 768, 1366 e 1920 px, em temas claro e escuro. O dashboard React é montado antes das verificações. Isso valida a renderização e a interação local; não substitui testes ponta a ponta de submissão em uma sessão HTTP real.

## Achados e correções

| Achado | Correção |
| --- | --- |
| Menu móvel abria mantendo o foco fora da navegação | Foco no link atual, ciclo Tab/Shift+Tab, Escape, restauração do foco, fundo inerte e limpeza ao mudar para desktop |
| Tabelas de usuários e tenants exigiam rolagem lateral para alcançar ações no celular | Mesmo padrão de linhas responsivas usado nas demais telas, com rótulos por célula |
| Releases usava documento HTML independente, sem viewport, temas ou navegação compartilhada | Integração ao layout Laravel, formulário e tabela responsiva existentes, estado vazio |
| Paginação administrativa usava apresentação diferente | Reutilização de shared.pagination |
| Algumas telas tinham título genérico ou repetido na aba | Títulos específicos para login, senha, usuários, tenants, edição e catálogo |
| Links repetidos de edição sem identificar o registro para leitores de tela | Nome do registro em texto apenas para tecnologia assistiva |
| Controles pequenos e campos com fonte menor que 16 px no celular | Alvos principais de 44 px e campos móveis de 16 px |
| Barra de rolagem da navegação completamente oculta | Indicador fino para sinalizar conteúdo adicional |
| Cabeçalho rígido e textos longos pouco protegidos | Altura flexível e quebra de texto nos elementos compartilhados |

Os formulários preservam rotas, métodos, CSRF, Policies e validações existentes. Nenhuma migration, consulta de domínio, job, política de autorização ou configuração de produção foi alterada.

## Evidências e limites

- Matriz final: **34 páginas × 4 larguras × 2 temas = 272 combinações**, sem overflow da página, campos visíveis sem rótulo ou erros JavaScript detectados. Os nove testes de teclado passaram. Nenhum contraste insuficiente foi encontrado pelo verificador limitado descrito abaixo.
- Testes Laravel finais: **65 testes, 437 assertions**, incluindo interface, autenticação, autorização, isolamento entre tenants, catálogo assinado e quarentena de releases. Captura adicional: 1 teste, 34 assertions.
- Build limpo com lockfile: aprovado; bundle do dashboard com 230,24 kB de JavaScript (72,14 kB gzip) e 2,74 kB de CSS (0,93 kB gzip). Verificação de sintaxe dos scripts JavaScript e git diff --check aprovados.
- [Matriz inicial](evidence/layout-review-20261110/before.json): 23 páginas × 4 larguras × 2 temas, 184 combinações; falha de foco ao abrir o menu confirmada.
- [Matriz final e testes de teclado](evidence/layout-review-20261110/after.json).
- [Usuários no celular antes](evidence/layout-review-20261110/users-mobile-before.png) e [depois](evidence/layout-review-20261110/users-mobile-after.png).
- [Tenants no celular](evidence/layout-review-20261110/tenants-mobile-after.png).
- Releases [no celular](evidence/layout-review-20261110/releases-mobile-after.png) e [no desktop](evidence/layout-review-20261110/releases-desktop-after.png).

A análise de contraste calcula cores de texto sobre fundos opacos nos elementos visíveis selecionados; não é certificação WCAG. Não foi executado Lighthouse nem medição de latência real. Estados que exigem dados adicionais, integrações externas e submissões operacionais não são cobertos por essa matriz offline.

O build deve usar as dependências do lockfile em instalação limpa: node_modules local estava incompleto e continha binding musl. A instalação e o build desta revisão foram realizados em pasta temporária com Node 24 Debian, sem alterar o lockfile nem os assets publicados.

## Reproduzir

Capturar as páginas com o executor autorizado do projeto, que cria e remove seu banco de teste sem reaproveitar banco preexistente:

```bash
./scripts/test.sh --filter test_capture_layout_audit_pages tests/Support/LayoutAuditSnapshotTest.php
mkdir -p /tmp/agent-center-layout-evidence
docker run --rm --network none \
  --mount type=bind,src=/opt/agent-center/app/storage/framework/testing/layout-review,dst=/snapshots,readonly \
  --mount type=bind,src=/opt/agent-center/app/public,dst=/public,readonly \
  --mount type=bind,src=/opt/agent-center/scripts/lab/layout-review.cjs,dst=/review.cjs,readonly \
  --mount type=bind,src=/tmp/agent-center-layout-evidence,dst=/evidence \
  agent-center-browser:recovery4 node /review.cjs
```

O capturador está fora das suítes padrão para não duplicar os testes que fornecem as fixtures. HTML de teste fica em storage/framework/testing/layout-review e não deve ser commitado: contém tokens CSRF sintéticos de sessão. As evidências versionáveis contêm somente métricas e capturas com dados fictícios.

## Entrega e rollback

Alterações preparadas no repositório, sem deploy de produção. Os assets compartilhados usam a versão de cache 17 para uma futura publicação autorizada.

Para rollback, reverter somente o patch desta revisão nos arquivos CSS/JS compartilhados e nas views de layout, autenticação, administração, catálogo e releases, restaurando a versão de cache anterior. Preservar alterações preexistentes de LLM, deploy e documentação. Não executar rollback de migrations, restaurar banco ou alterar Agents: esta revisão não muda dados nem esquema.
