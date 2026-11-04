# Arquitetura

```text
Browser
  ↓
Nginx
  ↓
Laravel Central
  ↓
PostgreSQL

Laravel Central
  ↓
Python Agent
```

FastAPI:
NÃO FAZ PARTE DA NOVA ARQUITETURA.

Nesta fase o Python Agent existe apenas como diretório reservado. Nenhum runtime, API ou integração de Agent foi criado.
Não existe migração ou bridge de instalação anterior.

## Componentes

Nginx sem privilégios atende HTTP somente em loopback, porta 18083, encaminhando FastCGI para PHP-FPM na rede privada. Os arquivos públicos são montados somente para leitura. Nginx não acessa o código privado da aplicação.
Central e worker usam a mesma imagem Laravel/PHP. O worker consome a fila database nativa; heartbeat técnico mede a continuidade do loop. Sessões e cache usam PostgreSQL. Logs de aplicação vão a stderr, com rotação Docker. Logs HTTP omitem query strings e headers. Exceções não incluem argumentos.
PostgreSQL possui volume dedicado e usuário administrativo inicial distinto do usuário da aplicação, que não é superuser e não pode criar roles/databases. Migrations são executadas explicitamente, nunca automaticamente no boot.

As redes são exclusivas do projeto Compose `agent-center`; backend é internal e não publica banco, FPM ou worker. Somente Nginx também participa da rede frontend. Nada do host 80/443 é alterado.

## Web, API e health

Web usa middleware Laravel nativo. API está preparada como arquivo de rotas vazio, sem Agent API e sem autenticação alternativa. `/` apresenta somente identificação da fundação.
Health usa rotas stateless: live retorna 200 com processo Laravel ativo; ready executa `SELECT 1`, devolve 200 ou 503 genérico e não revela exceção/DSN/credenciais. Ambos proíbem cache.

## Multi-tenant e segurança futura

Não há tabelas de domínio ou endpoints de negócio nesta fase. As migrations padrão Laravel de users, sessions, cache e jobs são infraestrutura, e não concedem acesso a tenants. Antes de qualquer módulo futuro, implementar tenant explícito em consultas, jobs, cache, sessões de autorização e Policies/Gates, com testes de isolamento cruzado. Não adotar implicitamente tenant único, superadmin irrestrito ou ID vindo do cliente como autorização.

Auth/Session/CSRF/Hash/Policies/Gates devem permanecer nativos. LLM nunca terá autoridade operacional. Skills exigem allowlist e execução validada; shell arbitrário é proibido. Nenhuma ação em device está autorizada nesta fase.

## Versões e atualização

Laravel 13 estável, PHP 8.4 em Debian Bookworm, PostgreSQL 17 em Debian Bookworm e Nginx sem privilégios em Alpine. Composer utiliza lockfile. Imagens externas estão fixadas por digest e devem ser atualizadas por mudança revisada, com testes e rollback; registrar digests em cada entrega.
PHP2Shell: exigir PHP >= 8.4.27 no build. `session.upload_progress.enabled=0` segue mitigação oficial adicional de 23/10/2026. Container não substitui patch do runtime.

Fontes oficiais consultadas em 04/11/2026:
- https://github.com/laravel/docs/blob/13.x/releases.md
- https://www.php.net/archive/2026.php
- https://www.docker.com/blog/security-update-on-php2shell-impact-for-docker-customers/
