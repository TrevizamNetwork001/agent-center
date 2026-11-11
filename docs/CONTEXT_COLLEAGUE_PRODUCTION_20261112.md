# Conversa contextual e ID do colaborador — PUBLICADO

Autorização operacional específica do usuário: “pode publicar”, após aprovação da entrega de laboratório. Publicadas as duas alterações, sem atribuir automaticamente um ID a qualquer colaborador real.

Imagem de produção: `agent-center-central:context-colleague-v1-20261112`, id `sha256:d1ec6e84558828604a114086ce0e97c30e00e90af94f0a1ef75fe14288d9941c`. Código fonte `d91a3a0`; revisão de runtime `6dcb5d8`. Linguagem `a7e3580`, associação administrativa `3c5e542`, integração `c342f6d`. Os onze arquivos de runtime conferem por SHA-256 com a imagem aprovada; arquivos existentes da base conferem com o baseline publicado.

Em **Telegram → Grupos e colaboradores**, o administrador pode selecionar o colaborador já cadastrado, informar o ID numérico e clicar em **Associar ID e liberar colaborador**, confirmando que conferiu a identidade. O vínculo por iniciativa do titular permanece como alternativa. A associação administrativa é identificada e auditada, preservando o papel da conta e a troca de senha inicial quando pendente. Revogação explícita continua bloqueando o privado mesmo sem grupo. O colaborador precisa abrir/iniciar o bot no Telegram para conversar; não é necessário gerar outro código no painel quando o administrador já fez a associação.

A conversa utiliza contexto curto dos dois lados, mantém o tom apropriado e responde à sequência de bem-estar/retorno/queixa de lentidão sem reapresentação institucional. Parser, Task Engine, RAG/fontes e confirmação operacional não foram reconstruídos. Limites e consentimentos OpenAI foram preservados.

## Publicação e preservação

Backup local `20261111T234836Z`, restauração em banco isolado aprovada, com dezesseis tabelas protegidas idênticas, FORCE RLS e credencial OpenAI decifrável com a chave do backup. Nenhuma replicação externa adicional nesta operação. Sem migrations ou mudança de grants em produção.

Fonte atualizada por fast-forward, preservando alterações locais alheias. Atualizados somente web, worker e telegram-scheduler; o migrator foi configurado com a mesma imagem compatível, sem execução. `TELEGRAM_ADMIN_ASSOCIATION_ENABLED=true` foi encaminhado explicitamente ao serviço web. O reconhecimento e a revogação de identidades nos jobs usam as regras compatíveis da nova imagem e não dependem desse flag de criação administrativa. Diagnostic-worker, Python Agent, Lifecycle, Observability, R2 e equipamentos preservados.

Comparação final: dezesseis tabelas protegidas inalteradas, configuração do Agent e credenciais preservadas, arquivos locais de outras tarefas intactos. Tarefas 97 e diagnósticos 28, sem alteração. Health VERDE, web/worker saudáveis e scheduler em execução.

## Validações

Laboratório: 423 testes e 2.965 assertions, cobrindo os 41 arquivos em três blocos disjuntos; concorrência Task Engine, RLS/RBAC, idempotência, confirmação, RAG e isolamento aprovados. Pint em onze PHP, build, secret scan, browser e transporte Telegram simulado aprovados; ver relatório de laboratório para exemplos e limites.

Produção: TLS, PDO verify-full, fila database→worker→cache, assets e readiness PASS. Navegador autenticado: 24 combinações de páginas/tamanho/tema, formulário administrativo visível e opção do Vladion presente; português, São Paulo, navegação sem flutuar e ausência de overflow/erros JS aprovados. POSTs negativos retornaram 419 sem CSRF, 422 para ID inválido e 422 sem confirmação administrativa. Nenhum POST de associação válida foi feito. A sessão temporária de validação foi removida.

Smoke no runtime publicado, com mensagens separadas e transações revertidas: Web respondeu “Tudo certo por aqui! E você, tá bem?”, depois “Que bom! 😄”, e então “Poxa, é chato quando fica assim! Tá demorando pra abrir os vídeos ou eles ficam travando durante a reprodução?”. Foram três chamadas OpenAI reais, dentro dos limites existentes, com store=false e até 1.024 tokens de saída por chamada.

Na identidade Telegram, o limitador compartilhado após as três chamadas Web selecionou o fallback esperado: “Aí sim! 😄” e “Pelo que você contou, tá difícil usar youtube.com. Os vídeos demoram pra começar ou ficam parando no meio?”. Isso confirma que a quota não foi ampliada para passar o teste. O caminho assistido Telegram já havia sido homologado em laboratório após a janela normal. A formatação nativa foi capturada por transporte de teste, sem envio externo ao Telegram, e correspondeu ao texto do histórico, sem rodapé social automático. Não foram criadas operações ou mantidos registros transitórios dessas conversas.

## Rollback compatível

Imagem preparada: `agent-center-central:context-colleague-compatible-rollback-20261112`. Ela restaura a linguagem anterior, conserva a regra de revogação explícita e o backend compatível com associações administrativas. Seus hashes foram verificados. Script `/root/agent-center-context-colleague-production-20261112/rollback.sh` restaura a configuração operacional anterior, aponta os três serviços à imagem compatível e deixa a criação administrativa desabilitada. Scripts passaram em validação de sintaxe. Não houve execução de rollback, pois as verificações passaram.

Não restaurar dump sobre produção, executar migration rollback ou voltar cegamente à imagem antiga que ignorava um membro revogado no privado sem grupo. Auditoria, vínculos e autorizações existentes devem ser preservados. O flag pode ser desativado isoladamente para impedir novas associações sem apagar dados.

Evidências sanitizadas: [evidence/context-colleague-production-20261112](evidence/context-colleague-production-20261112). Sessões, chaves, tokens e arquivos de ambiente não estão no Git.

Resultado: **CONTEXT AND COLLEAGUE ASSOCIATION PRODUCTION PASS**.
