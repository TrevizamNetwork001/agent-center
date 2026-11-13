# Evidências — Device Read-Only V1

Todos os equipamentos, endereços, dados e respostas desta pasta são **SIMULADOS**. SSH foi exercitado contra servidores de fixtures em laboratório isolado; hardware real não foi testado.

- `results.json`: consolidação dos testes e limitações.
- `protocol-boundaries.json`: negação real de requisições SSH de PTY, shell e forwarding nos dois endpoints simulados.
- `concurrency-result.json`: quatro requisições repetidas deduplicadas, quatro atores distintos serializados, cinco consultas SSH sem sobreposição, nenhuma task operacional.
- `browser-results.json`: 12 verificações de interface em 390/1440 px e claro/escuro, sem rede externa; formulário mantém dados, exibe erro junto ao campo e permite fechar o aviso por teclado ou temporizador.
- `ne8000-*` e `routeros7-*`: painel, controles e resultados tipados fictícios. Arquivos com `result` mostram a interpretação e a evidência técnica.
- `noc-form-error-*`: regressão visual do erro de Hostname e dos campos preenchidos.

Os screenshots vêm de respostas autenticadas do Laravel geradas em transações de teste, com tokens removidos antes da captura. Playwright roda com `--network none` e atende todos os recursos a partir de arquivos locais. Os JSONs de protocolo/concorrência não contêm credenciais ou saída CLI bruta.

Arquitetura, contratos, reprodução, plano de publicação e rollback: [DEVICE_READONLY_V1_LAB.md](../../DEVICE_READONLY_V1_LAB.md).

Resultado: **AGENT CENTER DEVICE READ-ONLY V1 LAB PASS**.

Validação funcional: 568 testes Laravel / 5.205 asserções, divididos em três grupos disjuntos (A–C: 164; D–N: 201; O–Z: 203); 185 testes Python em container; 31 testes direcionados de integração / 531 asserções; concorrência e rollback populado; rollback vazio em cada grupo; build; Pint em 32 arquivos; Ruff do laboratório; secret scan básico do índice e diff check. São 20 screenshots e 12 verificações de navegador.

O Ruff global do Agent encontrou dois erros **preexistentes** de ordenação de imports (`I001` em `heartbeat.py` e `update/remote.py`). Eles não foram ocultados ou corrigidos por esta entrega, que não altera o Agent. O resultado de laboratório não certifica lint ou release do Agent operacional.

As validações ocorreram no workspace existente. Alterações anteriores no Agent, Lifecycle, UI e outros arquivos foram preservadas e não fazem parte dos commits desta entrega. Antes de uma futura publicação autorizada, preparar checkout limpo dos commits aprovados, executar novamente os checks de release e manter as flags de laboratório desabilitadas na produção.
