# Inventário anterior a instalações — 2026-11-04

VPS observada diretamente, sem procurar instalações antigas do Agent Center.

| Item | Valor |
|---|---|
| Distribuição | Debian GNU/Linux 13 (trixie), 13.7 |
| Kernel | 6.12.113+deb13-cloud-amd64, x86_64 |
| CPU | 4 vCPUs AMD EPYC, KVM |
| RAM | 7,8 GiB; aproximadamente 5,1 GiB disponíveis; sem swap |
| Disco raiz | ext4, 99 GiB; 16 GiB usados; 80 GiB livres |
| Timezone | Europe/Berlin |
| Hostname | vmi3705362 |
| Interfaces | lo; eth0 (IPv4/IPv6); docker0; bridges br-* e veth* de outros projetos |
| eth0 | 169.58.239.73/17; 2a02:c207:2370:5362::1/64 |
| Docker Engine | instalado, 29.9.2, daemon acessível |
| Compose plugin | instalado, 5.6.1 |
| Git | instalado, 2.47.3 |
| PHP global | ausente |
| PostgreSQL global | executáveis psql/postgres ausentes; existem containers de outros projetos |
| Nginx global | instalado, 1.26.3; portas 80/443 ocupadas |
| Ferramentas base | curl 8.14.1, OpenSSL 3.5.7, ca-certificates, tar, gzip, iproute2, procps presentes |

Nenhuma instalação global foi necessária. Serviços preexistentes não foram alterados.
O projeto usará HTTP somente em 127.0.0.1:18083, porta livre no inventário.
Nenhum AGENTS.md estava presente em /, /opt ou /opt/agent-center.
