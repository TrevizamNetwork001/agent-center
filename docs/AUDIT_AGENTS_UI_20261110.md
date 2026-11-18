# Auditoria em português e navegação de agentes — 10/11/2026

Status: publicado em produção, como continuidade das melhorias de interface autorizadas nesta conversa.

## Resultado

- Auditoria: 67 tipos de evento e 15 tipos de entidade com nomes em português; papéis e campos do resumo traduzidos. Filtro de evento mantém o valor original. Código técnico disponível em detalhes recolhidos. Textos sobre metadata/payloads substituídos por linguagem clara. Tabela com cabeçalhos semânticos e rolagem acessível.
- Lista de agentes: botão explícito Abrir agente e cabeçalhos Último contato, Consulta de tarefas e Recursos. Recursos conhecidos com nomes descritivos.
- Agente selecionado: atalhos Conexão e IPs, Novo diagnóstico (ou Ver diagnósticos conforme permissão), Versão e atualização e Informações técnicas. Identidade, inventário e segurança agrupados; histórico e manutenção em seções separadas. Atalho técnico abre a seção recolhida por navegação nativa ao fragmento.
- Novo diagnóstico: formulário recebe o agente do atalho, selecionando somente entre os agentes já autorizados pelo backend.
- Textos do inventário e da observabilidade mais claros, incluindo motivos conhecidos de bloqueio e indisponibilidade.

Nenhum código de evento, registro de auditoria, permissão, formulário operacional, segredo, protocolo de agente ou regra de isolamento foi alterado. A reorganização não executa testes, atualizações, manutenção ou acessos a equipamentos.

## Verificações

- 76 testes Laravel, 612 assertions aprovados. Incluem interface, operações, atualizações, configurações, observabilidade, autorização e isolamento entre tenants. A captura das 34 páginas está incluída.
- Matriz Chromium: 34 páginas × 4 larguras × 2 temas = 272 combinações. Sem overflow, campos visíveis sem nome acessível, erros JavaScript ou achados no verificador limitado de contraste.
- 18 verificações adicionais aprovadas: teclado/menu, cabeçalhos longos, tabela de tablet, abertura da seção técnica, botão Abrir agente e apresentação traduzida dos eventos.
- Templates Blade compilados na imagem final sem rede; nova classe de apresentação carregada pelo autoloader existente.
- Produção: login e health/live/ready HTTPS 200; /audit e /agents sem sessão redirecionam ao login. CSS publicado confere por hash; 12 arquivos de runtime conferidos por hash. Sem marcadores de erro de aplicação na verificação dos logs.
- Cinco serviços principais saudáveis. Somente web recriado pelo deploy; demais IDs de containers preservados.
- git apply --check --whitespace=error e git diff --check aprovados.

A matriz usa fixtures fictícias do Laravel, sem senha de operador nem sessão autenticada persistente em produção. O verificador de contraste não certifica WCAG. Não houve Lighthouse, medição de latência, submissão operacional ou acesso a equipamentos. O bundle React não foi alterado; a folha de estilo compartilhada é servida diretamente pelo Nginx existente.

## Publicação

Imagem: agent-center-central:audit-agents-pt-20261110
ID: sha256:256f558c98c0a6a7fc5fc97bdade0308da020b179cdc688f27b8aba664837844
Base: agent-center-central:layout-review-20261110
ID da base: sha256:61729f0049b42771bf8e11782031bcc2135bb794d62eda9aafcf13445efc1b37

Overlay restrito de 12 arquivos de runtime sobre a imagem publicada anterior; nenhuma reconstrução de dependências. Código-fonte atualizado após conferir hashes de origem e patch. Imagem registrada em deploy/compose.yaml, publicação com scripts/compose.sh up -d --no-deps --no-build --pull never web e limpeza exclusiva das views compiladas. Nenhuma migration ou mudança de banco.

## Backup e rollback

Backup restrito em /root/agent-center-audit-pt/backup/: dump PostgreSQL custom com listagem pg_restore validada, arquivos anteriores alterados, compose e lista anterior dos containers. Dump 0600 em pasta 0700; sem envio externo ou restauração.

Rollback:
1. Confirmar ID da imagem base e usar scripts/compose.sh -f /root/agent-center-audit-pt/rollback.yaml up -d --no-deps --no-build --pull never web.
2. Restaurar somente a folha de estilo anterior do backup; preservar alterações concorrentes. Registrar a imagem anterior em services.web.image do compose persistente.
3. Limpar views com scripts/compose.sh exec -T web php artisan view:clear e verificar readiness HTTPS.
4. Para sincronizar o checkout, reverter changes.patch somente após conferir os hashes de destino de changes.json. Isso também remove a nova classe de apresentação do checkout; a imagem base já contém os arquivos anteriores.

Não restaurar banco ou executar migrations para reverter esta entrega de interface. Evidências, patch e hashes em /root/agent-center-audit-pt/.
