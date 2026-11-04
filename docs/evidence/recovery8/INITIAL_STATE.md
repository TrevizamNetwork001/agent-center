# Estado inicial e rollback — 2026-11-04

Checkout limpo em 2f3fc8f, origin local /opt/agent-center, sem origin externo.
Produção separada: /opt/agent-center, imagem Central recovery6. Checkout recovery7 não promovido.
Debian 13.7, kernel 6.12.113, 7.8 GiB RAM, disco 99 GiB / 22% usado, sem swap.
Docker 29.9.2 / Compose 5.6.1. Cinco containers Agent Center saudáveis.
PostgreSQL 17.11 privado, volume agent-center_postgres_data, SSL off inicialmente.
Nginx em host + container não privilegiado, entrada loopback 18083; nenhum FastAPI.
DNS A 169.58.239.73. Certificado SAN correto, válido 2026-11-04 até 2027-02-02.
HTTP 308 HTTPS; login/ready 200. Sessão Secure/HttpOnly/SameSite=Lax, XSRF Secure/Lax.
NTP=yes e NTPSynchronized=yes. Certbot.timer e hook específico presentes.
Secrets env/chaves 0600, diretório 0700; nunca inventariados por conteúdo.
Backup anterior: geração 20261104T210551Z, manifesto e bundle verificados.

Rollback: conservar /opt/agent-center e seus volumes; nenhuma migration nova nesta fase.
Toda recriação destrutiva de ensaio se limita ao projeto agent-center-recovery8.
Não executar down -v nem pg_restore sobre produção. Para promoção futura, preservar
imagem recovery6, configuração anterior, backup pré-deploy e TLS/HBA compatíveis.
Acesso confirmado pelo operador após nginx -t e reload da configuração já autorizada;
allowlist mantida. Tentativa de abrir todo o vhost foi rejeitada pela revisão automática,
sem execução. Outros projetos não foram alterados.
