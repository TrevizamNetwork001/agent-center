# Git remoto privado

O origin inicial aponta para /opt/agent-center nesta VPS. É checkout local de produção,
não remoto externo, não réplica independente e não deve receber push durante recuperação.
Nenhuma URL privada externa autorizada foi encontrada. Não criar repositório público.

## Conexão pelo operador

1. Criar repositório **privado** na organização/conta autorizada, sem importar secrets.
Escolher controle de acesso mínimo, MFA/SSO e proteção de branches. Fornecer URL exata
sem token embutido e credencial de máquina restrita ao repositório.
2. Conferir secret scan e histórico; .env, secrets, chaves, dumps, backups e agent/dist
estão ignorados. Não git add -f nem inserir tokens em remotes/logs. Revogar um secret
exposto exige plano próprio: apagar arquivo do HEAD não remove histórico.
3. Preservar a referência local: `git remote rename origin production-local`.
Depois `git remote add origin URL_PRIVADA_AUTORIZADA` (placeholder, não executar literalmente).
4. Validar acesso com `git ls-remote origin`; confirmar privacidade pelo provedor.
Enviar somente branch revisada: `git push -u origin NOME_DA_BRANCH_REVISADA`.
Não usar --mirror/--force nem publicar refs extras sem revisar conteúdo.
5. Em diretório/máquina independente, fazer clone do remoto, conferir HEAD contra o commit
revisado e `git fsck --full`. Esse ensaio está bloqueado até URL/credencial autorizada.

Backup local gera bundle --all e verifica integridade; clone independente local deve
conferir HEAD/fsck. Bundle é parte do arquivo externo criptografado quando houver destino.
Git externo não substitui dump, storage ou secrets; repo privado e backup em conta separada
reduzem dependência do mesmo provedor. Não inventar URL nem confundir origin local com Git remoto.
