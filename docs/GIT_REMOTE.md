# GitHub privado — RECOVERY-8

Repositório autorizado e criado pelo operador:
`https://github.com/TrevizamNetwork001/agent-center`.

O operador confirmou **Privado**. Antes do primeiro push: acesso SSH autenticado ao
repositório vazio, API anônima404 e varredura de todo histórico/árvores. A combinação de
acesso autenticado e invisibilidade anônima sustenta a privacidade informada; o campo
`private=true` da API autenticada não foi consultável pelo conector, sem repositórios
liberados. Essa limitação está registrada na evidência, sem inventar metadados.

## Histórico e autenticação

- `main`: linha RECOVERY-7/8, primeiro envio em82dcd2c, com todo histórico ancestral.
- `production-recovery8`: preserva a linha implantada, inicialmente6bbb4ba.
- `production-local`: nome reservado ao antigo origin local `/opt/agent-center`.
- `origin`: GitHub via SSH443, mesmo owner/repo informado pelo operador.

Deploy key Ed25519 exclusiva, com escrita somente neste repositório, cadastrada pelo
operador. Privada em secrets/github-agent-center0600, fora do Git; host key pinada a
partir da documentação oficial GitHub. core.sshCommand é configuração local deste
checkout, sem alterar SSH/global de outros projetos. Não imprimir nem copiar a privada
para GitHub; deploy keys recebem somente pública. Backups dessa identidade operacional
precisam de criptografia. Ela não é a identidade age de recuperação.

Primeiro push sem force, remote refs, clone limpo SSH, fsck e comparação integral de
`rev-list main` passaram. Evidência: evidence/recovery8/closure/github-first-push.json.
Commits novos desta continuação devem ter o mesmo readback ao final; o hash corrente
remoto fica no relatório de fechamento. Não considerar somente adicionar origin como sucesso.

## Recuperar em host autorizado

Provisionar credencial de leitura independente pelo próprio GitHub. A chave da VPS
não pode ser o único meio de recuperar a conta/repo após perder a VPS. Manter MFA,
recuperação da conta e responsáveis fora do host. Em máquina nova:

```sh
git clone git@github.com:TrevizamNetwork001/agent-center.git agent-center
cd agent-center
git fsck --full
git log --oneline -5
```

Comparar branch/HEAD e commit do backup. Se necessário obter
`production-recovery8` para reconstruir o runtime implantado anteriormente. Não
substituir main por essa branch antiga nem usar --mirror/--force.

Antes de cada push: secret-scan.sh, audit-secret-history.py e diff --check. O scanner
histórico verifica todas as árvores alcançáveis, incluindo arquivos removidos, secrets
atuais, private keys/age identities, nomes proibidos e assinaturas de dump/bundle.
É um controle adicional, não garantia universal de ausência de todos os padrões secretos.
.env, secrets, dumps, bundles, arquivos criptografados e ferramentas vendorizadas não
entram no GitHub. GitHub guarda código/histórico, nunca banco real ou backup sensível.

Referência: [host keys SSH oficiais](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints).


O painel R2 e sua chave privada não participam do Git: a configuração fica em
ciphertext no storage persistente excluído de Git/imagens, e a identidade de
recuperação permanece com o operador fora da VPS. JavaScript age é empacotado com
package-lock reproduzível em deploy/browser-crypto; não há dependência de CDN.


No fechamento, a branch production-recovery8 acompanha somente a promoção operacional
aprovada da Central; main preserva o desenvolvimento testado e a implementação anterior
do Agent0.5.3. A conciliação não modifica arquivos agent/ nem instala Agent. Ao restaurar,
respeitar commit/schema do manifesto e o catálogo de artefatos aprovados, com SHA-256.
