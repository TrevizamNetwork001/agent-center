# Correção do precheck: credenciais nativas, 0.5.6

Após o reparo 0.5.5, o host teste anunciou companion 0.5.5 e a primeira nova
verificação terminou FAILED/self_check_privilege_failed, sem instalar runtime.
O operador confirmou CAP_SETUID/CAP_SETGID no bounding set, PrivateUsers=no,
NoNewPrivileges=yes, RestrictSUIDSGID=yes e ausência de drop-ins. Isso não prova
qual chamada falhou no callback anterior e não justifica reduzir o sandbox.

O self-check deixa de usar preexec_fn. UID/GID são resolvidos no processo pai e
passados como números em user/group, com extra_groups=[] para remover grupos
suplementares. Nenhum código Python/NSS customizado executa após fork. Essa API
é suportada pelo Python 3.13 instalado: [documentação oficial](https://docs.python.org/3.13/library/subprocess.html#subprocess.Popen).
Não se remove a troca para usuário sem privilégios nem se altera a unit,
capabilities ou NoNewPrivileges/RestrictSUIDSGID. É necessário validar o novo
companion no host; não se declara resolvido o precheck remoto antes disso.

A versão é 0.5.6 para preservar a imutabilidade dos pacotes 0.5.5 publicados.
O instalador aceita e substitui companions de hash aprovado 0.5.3/0.5.5 pelo
0.5.6, preservando runtime, cadastro, configuração e journal. As proteções de
backup/helper.lock e manutenção ocupada continuam ativas. O operador executa
o mesmo instalador e solicita nova verificação pelo painel.

Verificação isolada com NoNewPrivileges e apenas CAP_SETUID/CAP_SETGID: subprocesso
real executou UID/GID 65534, grupos suplementares vazios; self-check 0.5.6 PASS.
Teste novo garante ausência de preexec_fn e os argumentos nativos esperados.
Agent: 56 testes PASS. Instalador: 8 testes PASS, incluindo transição do companion
0.5.5 para o novo preservando runtime. Laravel/PostgreSQL: 37 testes / 436
assertions PASS, incluindo autorização e isolamento por tenant. Sintaxe Bash,
integridade do download público, leitura de código na imagem como www-data e
diff check conferidos.
Sem acesso a equipamentos, identidade real ou DNS externo.

Publicação Central: imagem agent-center-central:agent056 derivada de agent055,
compatibilidade explícita 0.5.6, catálogo, mensagem e instalador. Apenas web é
recriado. Vhost libera somente o arquivo de kit 0.5.6 e SHA256SUMS; preservadas
demais regras. Hashes em docs/INSTALLER_DOWNLOAD_HASHES.json.

Rollback: snapshot privado backups/agent-fixes056 guarda fonte/instaladores,
catálogo, compose e vhost anteriores. Voltar somente web para agent055,
restaurar instalador/helper e hashes correspondentes, limpar views. Manter
compatibilidade de relatórios enquanto houver companions 0.5.6. Reversão local
dos updaters usa os backups privados de repairs sob helper.lock e restaura o
timer conforme rollback.json. Não apagar identidade/state nem reverter banco.
