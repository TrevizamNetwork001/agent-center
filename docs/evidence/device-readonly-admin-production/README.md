# Device Read-Only V1 — evidências da publicação administrativa

**DEVICE READ-ONLY V1 ADMIN PRODUCTION PASS**

- [Resultados consolidados](results.json): 569 testes Laravel / 5.220 asserções e 185 Python; limites e achados Ruff preexistentes explícitos.
- [Classificação dos três commits](commit-scope.json), [manifesto administrativo](overlay-manifest.json) e [paridade das imagens](image-parity.json): nenhum executor, credencial, migration ou rota de Device Access.
- [Health final](health.json) e [serviços publicados](services-after.json): HTTPS/readiness, TLS, RLS e Operations Health VERDE.
- [Preservação dos dados](preserved-data.json): hashes comparados com backup pré-publicação, sem conteúdo ou secrets.
- [Candidata HTTP nativa](browser.json) e [produção HTTPS autenticada](production-ui.json): desktop/mobile e temas; sem consultas reais.
- [Restauração](restore.json), [SecretVault restaurado](restore-crypto.json), [histórico na imagem anterior](rollback-device-analysis.json) e [renderização após limpar views](rollback-render.json).
- [Sessão de QA revogada](session-cleanup.json).

São 20 screenshots. Arquivos `admin-*` usam exclusivamente dados fictícios em banco isolado e imagem candidata imutável; arquivos `production-*` documentam a interface HTTPS publicada. Identidade da conta é ocultada na evidência de produção. O painel de produção contém metadados declarados do inventário e não medições de rede. Nenhuma credencial, cookie, token, saída CLI de dispositivo ou dump foi incluído.

Publicação, backup local, reprodução e rollback: [documentação](../../DEVICE_READONLY_ADMIN_PRODUCTION.md).
