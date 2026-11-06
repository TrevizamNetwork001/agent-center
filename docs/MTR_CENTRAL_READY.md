# Integração final MTR — Central publicada

**MTR CENTRAL READY — AGENT DEPLOY PENDING**

Contrato: 2026.11.06.1. Imagem persistente: `agent-center-central:mtr-contract-062-final-20261106`. Foram integrados os commits 4da9a01, f301555 e 4c99a12 por ancestralidade; a branch segura já continha a análise. A comparação com a base publicada não encontrou divergências nos arquivos alterados. Alterações pré-existentes do diretório de trabalho foram preservadas; não houve checkout forçado ou operação no GitHub remoto.

## Publicação e testes

Publicados somente PHP/Blade/JS/CSS Laravel, migration aditiva da capability e a correção de Operations Health que usa Protocol::VERSIONS para reconhecer 0.6.1/0.6.2. O FPM recebeu código atomicamente e reload gracioso; não foi necessário recriar o container web. Os workers usam a imagem publicada. Dados, leases, fencing, idempotência, Auth/CSRF, isolamento por tenant e definições já existentes foram preservados.

Laravel/PostgreSQL: 231 testes, 1.812 assertions; harness concorrente de claims, corrida worker/result, fencing e idempotência, PASS. Python: 102 testes, PASS. Interface: 12 screenshots em 390/900/1440, claro/escuro, sem erros JS/overflow; MTR indisponível em 0.6.1 e em 0.6.2 sem confirmação. Normalizer/classificador receberam diretamente resultados reais do helper e runtime empacotado. O laboratório de units Debian 13 repetiu ICMP/TCP, action worker com capability sets vazios, timeout/cancelamento, concorrência, recusa de alvo e isolamento. Não houve instalação na VM teste.

## Health e compatibilidade

Readiness: HTTP 200. Agent teste: 0.6.1 ONLINE, uma de uma instância online. MTR indisponível, zero tasks MTR. Operations Health: `VERDE`; nenhum componente NO-GO após atualização das observações reais. Credencial e resultado histórico foram comparados por hash e permanecem iguais; flags dos Profiles/Skills anteriores foram preservadas.

O diagnóstico de regressão autorizado Site não abre / google.com terminou NORMAL no Agent 0.6.1, com DNS A/AAAA, TCP, HTTPS e ICMP concluídos e traceroute dispensado. ID: `eba5487d-be58-41a1-a35b-3e04e26d92dd`. O diagnóstico anterior também continua renderizando a conclusão e a ressalva de HTTP 301/redirecionamento.

A confirmação MTR exige versão compatível e anúncio pela API autenticada do Agent, registrado pela Central. Catálogo habilitado não é prova de disponibilidade. O planner/claim/conversa usam capabilities filtradas. O principal 0.6.1 ignora a extensão na política e continua executando as verificações existentes.

## Backup e rollback

Backup privado: `/opt/agent-center/backups/mtr-central-20261106T001337Z`. Inclui pg_dump custom completo, schema SQL, arquivos anteriores, compose e snapshot dos arquivos servidos pelo web. O dump foi produzido sem alterar dados ou retenção. Aplicação pode ser revertida preservando o schema aditivo e as evidências.

Rollback administrativo da Central, enquanto nenhum Agent 0.6.2 estiver com MTR disponível:

```bash
python3 /root/agent-center-mtr-central/deploy.py rollback /opt/agent-center/backups/mtr-central-20261106T001337Z
```

O rollback retira somente os novos Profiles/Skill MTR do catálogo do tenant autorizado, restaura os arquivos/imagem anteriores e recarrega FPM/workers. Se houver Agent 0.6.2 com MTR confirmado, ele recusa: é preciso retirar/reverter primeiro o Agent, mediante autorização operacional própria. Não restaurar o dump inteiro sobre um banco vivo para rollback comum, pois isso perderia evidências posteriores. O dump integral é reserva de recuperação, não aplicado automaticamente.

## Procedimento único da VM e kit

Seguir exclusivamente docs/MTR_TESTE_INSTALLATION.md, também incluído como INSTALL.md no kit. Verificar checksum externo, conferir ACL-base existente, criar teto revisado somente para google.com se já autorizado, preservar identidade/config/journal, preparar helper, validar privilégios e só então atualizar pelo updater existente. Em seguida executar os dois Profiles MTR no painel e revisar evidências. Não usar o instalador público genérico para essa implantação.

Kit: `mtr-secure-debian13.tar.gz`.

SHA-256 externo: `4820103960d3b4ca61ea71cdc1db370d8ab2c9a565dc5e7a28a3e86e51207007`.

Runtime 0.6.2: `37554891c5d0b9b896a7300b7f08f755a6d57d87defc4649bb11e04d1d0534a1`.

Helper: `a6ab1c55d490523a9a385574e9f10dc8638b4d3f489ae5199119c783ded74b81`.

Os bytes do runtime/helper continuam idênticos aos validados na entrega segura; somente a documentação/manifesto do pacote foram atualizados para este procedimento, sem tcpdump/Nmap. A oferta automática e o instalador público continuam 0.6.1; nenhum runtime, helper, ACL, configuração, identidade ou journal da VM foi alterado nesta tarefa.

## ACL e riscos residuais

Exemplo mínimo: base já autoriza google.com; teto root-owned 0600 com schema_version=1, allowed_dns_names=[google.com], allowed_networks=[]. Não modificar a base para contornar recusa. managed-settings.json não substitui a base nem o teto. CAP_NET_RAW existe somente no executor isolado; é sensível dentro dessa fronteira. Rotação DNS pode gerar recusa segura; respostas MTR não provam causalidade, encaminhamento ou caminho BGP. ASN ausente permanece Não identificado.

Sem tcpdump, Nmap, device actions, R2, alterações de retenção ou novas integrações. Kit entregue para instalação manual posterior com autorização específica.
