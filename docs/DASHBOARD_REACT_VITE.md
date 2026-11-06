# Dashboard React + Vite — 2026-11-06

Publicado em https://agente.trevizamnetwork.com.br/dashboard.

## Implementação

React 19.3.1 e Vite 8.3.6, integrados ao Laravel 13.40.0. Apenas o conteúdo do dashboard é montado em React; autenticação, sessão, CSRF, Gates, isolamento por tenant, sidebar e temas permanecem no Laravel. Não há serviço Node nem servidor de desenvolvimento Vite em produção. A imagem usa build frontend separado e dependências fixadas no lockfile.

Busca local por alvo/perfil/Agent e filtros de estado nos seis diagnósticos recentes. Atualização a cada dez segundos e botão manual; pausa em página oculta, requisições sem sobreposição e timeout de doze segundos. Em falha de conexão, os últimos dados confirmados permanecem com aviso. Mudança de contexto ou sessão inválida remove os dados e exige recarregar. A apresentação inicial vem do servidor e continua disponível se o bundle não carregar. JSON privado, com campos explícitos e sem metadados de auditoria ou credenciais.

## Validação

- Laravel: 234 testes, 1843 assertions aprovados.
- PostgreSQL: harness de concorrência, claims, fencing e idempotência aprovado.
- Browser: seis capturas, larguras 390/900/1440, temas claro/escuro, busca, filtros, refresh, falha de conexão e troca de contexto; nenhum erro JavaScript.
- Build de produção Vite e imagem Docker completos; manifest da imagem igual ao build testado.
- npm audit: zero vulnerabilidades reportadas; Pint e diff check aprovados.
- Produção: resposta JSON 200, cache privado/no-store, tenant correto, HTML com módulo React e conteúdo inicial; hashes dos sete arquivos de runtime e dos assets públicos conferidos.
- Readiness 200 e Operations Health VERDE. Agent teste 0.6.1 ONLINE, MTR indisponível até implantação específica.

## Publicação e rollback

Imagem persistente do web: `agent-center-central:dashboard-react-vite-20261106`. Publicação feita por troca atômica dos arquivos e reload gracioso do PHP-FPM, sem recriar workers nem migrar banco. Compose mantém a imagem anterior dos workers. Assets antigos preservados para clientes com páginas abertas.

Backup: `/opt/agent-center/backups/dashboard-react-20261106T004553Z`.

Rollback específico do dashboard:

```bash
python3 /root/agent-center-dashboard/deploy.py rollback /opt/agent-center/backups/dashboard-react-20261106T004553Z
```

Restaura views, controller, manifest, arquivos prévios e compose; não restaura banco nem altera o contrato MTR. Classes/assets novos podem permanecer sem referências. Não limpa dados ou journals.

Para rebuild, executar o fluxo Docker existente com `deploy/php/Dockerfile` no contexto da raiz; o estágio frontend executa `npm ci` e `npm run build`. A instalação local de dependências não deve ocorrer em produção fora do fluxo de build.

## Limites

Filtros e busca abrangem somente os seis registros recentes; a consulta completa permanece no Histórico. Atualização usa polling, sem WebSocket. Sem alteração da VM do Agent, políticas, dispositivos ou classificações determinísticas.

O contrato MTR e o kit de instalação controlada estão documentados em `MTR_CENTRAL_READY.md` e `MTR_TESTE_INSTALLATION.md`.
