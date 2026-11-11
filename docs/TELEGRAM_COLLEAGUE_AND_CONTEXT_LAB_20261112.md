# Conversa contextual e associação de colaborador — laboratório aprovado

**NATURAL CONVERSATION CONTEXT FIX LAB PASS**

Entrega adicional solicitada durante a missão: selecionar um colaborador já cadastrado no tenant e associar o ID Telegram como administrador. As duas alterações estão integradas na branch `lab/conversation-and-colleague-association`, em `/root/agent-center-integration-lab`. Nenhuma publicação realizada.

## O fluxo que foi implementado

Em **Grupos e colaboradores**, o administrador pode selecionar a conta existente pelo nome, informar o ID numérico, confirmar que conferiu a identidade e clicar em **Associar ID e liberar colaborador**. A associação é direta e auditada: não é um pré-cadastro com status “Aguardando confirmação”. O colaborador não precisa gerar outro código de vínculo no painel. Ele precisa abrir/iniciar o bot no Telegram para conversar, conforme o funcionamento do Telegram.

O texto antigo que obrigava todos a fazer o vínculo por conta própria foi substituído. A tela apresenta **Colaboradores e grupos no Telegram** e explica a seleção da pessoa já cadastrada. O vínculo feito pelo próprio colaborador continua disponível como alternativa. O guia da própria conta identifica **Identidade associada pelo administrador**, sem afirmar que somente o titular poderia ter feito essa associação.

A ação mantém o papel, as permissões e os dados da conta existentes. Se a troca de senha inicial estiver pendente, o administrador consegue salvar o ID, mas essa pendência continua impedindo o uso do bot até ser resolvida pelo fluxo nativo. Não foi alterada nenhuma conta real, inclusive a conta mencionada pelo usuário durante o suporte.

## Autorização e dados

A atribuição é uma decisão explícita do administrador autenticado, registrada como `telegram.link_admin_assigned`, com ator, tenant, colaborador e UUID do vínculo. A proveniência fica no log imutável existente; não houve migration nem nova tabela. A confirmação administrativa não é apresentada como confirmação pessoal do colaborador nem como consulta externa ao Telegram.

Apenas contas ativas de Operador/Administrador do tenant selecionado são elegíveis. A consulta e a gravação usam autenticação, sessão, CSRF, Gates e FORCE RLS nativos, com validação explícita de tenant. O bot e sua geração são conferidos para rejeitar páginas antigas após troca de credencial. IDs ocupados por outra pessoa, códigos de vínculo em andamento e trocas silenciosas de um vínculo existente são rejeitados. Repetir a mesma associação não duplica vínculo, autorização ou evento de atribuição.

As permissões nativas do usuário continuam sendo verificadas a cada processamento e entrega. Um ID administrativo só identifica aquela conta existente, sem ampliar suas permissões. A revogação explícita de um membro agora prevalece também no privado quando não há grupo ativo; a existência de um vínculo permanece insuficiente para contornar uma revogação. Não houve mudança no parser, Task Engine, RAG/fontes, credenciais, Agent, Lifecycle, R2 ou equipamentos.

A nova criação administrativa é controlada por `telegram.admin_association_enabled`, com variável `TELEGRAM_ADMIN_ASSOCIATION_ENABLED` e padrão falso. Ela foi habilitada somente nos containers de laboratório. O fluxo existente permanece disponível quando esse recurso está desabilitado.

## Conversa contextual

O ajuste de linguagem, código `a7e3580`, está detalhado em [NATURAL_CONTEXT_FIX_LAB_20261112.md](NATURAL_CONTEXT_FIX_LAB_20261112.md). A investigação real mostrou que as duas respostas institucionais eram assistidas; a frase sobre lentidão vinha de uma regra local, apesar de o tom estar RELAXED. O gerador recebia só as falas do usuário, sem as próprias respostas anteriores.

A correção mantém até cinco turnos sanitizados e limitados a 800 caracteres de conteúdo, incluindo respostas conversacionais do assistente. Resultados operacionais e respostas RAG ficam fora desse contexto. As permissões de uso externo e os limites OpenAI anteriores permanecem. Perguntas sociais, respostas de bem-estar e queixas informais de lentidão recebem continuidade apropriada, sem repetição institucional, diagnóstico inventado ou autoridade para executar Skills.

Foram feitas seis chamadas OpenAI reais, apenas no laboratório, três por canal, com 4.767 tokens informados no total. A limitação compartilhada por usuário foi respeitada; a segunda sequência foi feita após a janela normal, sem aumento ou limpeza de quota. O teste de limite produziu fallback adequado. Exemplos reais assistidos: “Que bom! 😄”, seguido de “Poxa, é chato quando fica assim! Tá demorando pra abrir os vídeos ou eles ficam parando no meio?”.

Na imagem integrada, a sequência inteira também foi reproduzida para um colaborador associado administrativamente, com mensagens separadas na Web e Telegram. Exemplo local: “Boa! Que bom que tá tudo certo.”, depois “Essa lentidão atrapalha, hein. Os vídeos demoram pra começar ou ficam parando no meio?”. Não houve reapresentação institucional nem criação de operações. `/historico` continuou apontando à conversa atual.

## Validações executadas

- **423 testes e 2.965 assertions PASS**, com todos os 41 arquivos de testes cobertos em três blocos disjuntos e sequenciais via `scripts/test.sh`, cada um em PostgreSQL descartável. A execução longa anterior foi interrompida pelo executor; foi substituída pela execução completa em blocos, sem declarar a execução interrompida como sucesso.
- Concorrência Task Engine em processos separados, fencing, idempotência e pré-requisitos DNS PASS. As regressões de parser, confirmação, RAG/fontes, RBAC/FORCE RLS e isolamento entre tenants passaram.
- Associação administrativa: perfil permitido, consentimento explícito, colisões de ID, vínculo em andamento, geração do bot, conta desativada, outro tenant, campos protegidos e reenvio idempotente testados. Troca de senha obrigatória não é contornada. Revogação bloqueia também sem grupo.
- Após o último ajuste textual no guia, revalidação focada da interface: um teste, onze assertions PASS, além da repetição da conferência visual na imagem final.
- Pint em onze arquivos PHP, build Vite, `git diff --check` e varredura local de padrões de secrets PASS. Nenhuma nova dependência. Scanner especializado de secrets não instalado.
- Navegador real na imagem candidata: seleção do colaborador existente e envio do formulário, rejeição de POST sem CSRF (419), identificação correta no painel do titular e ausência do formulário administrativo para Operador. Seis combinações 1440/768/390 e claro/escuro, sem overflow/erros JS. A orientação antiga de aprovação exclusivamente pessoal não aparece para o fluxo administrativo.
- Transporte Telegram simulado: webhook autenticado pelo segredo configurado, Ingress/Runner/Deliver nativos, identificação da mesma conta no privado e no grupo, contextos separados e nenhuma tarefa/diagnóstico novo. Não houve envio externo ao Telegram real.
- Imagem `agent-center-central:context-colleague-lab-20261112`, construída sobre a imagem atual publicada. Onze arquivos de runtime conferidos por SHA-256 contra o código final. Os arquivos existentes da base da imagem conferem com o baseline Git, preservando os demais componentes operacionais.

Evidências sanitizadas em [evidence/telegram-colleague-integration-20261112](evidence/telegram-colleague-integration-20261112), com resumo dos blocos, logs, transcrições, hashes e capturas. Os testes com configuração real foram revertidos em transação e o arquivo temporário cifrado foi removido. A produção continua no commit `4e5b1d5`, imagem `conversation-polish-v1-20261111`, saudável; a imagem candidata não foi ativada.

## Commits e rollback

- `a7e3580`: contexto e naturalidade, um arquivo de runtime.
- `6107fe8`: investigação e evidências de linguagem.
- `3c5e542`: associação administrativa de colaborador existente.
- `c342f6d`: integração dos dois trabalhos, preservando ambos os conjuntos de testes.
- `6dcb5d8`: esclarecimento final do guia da identidade; revisão de runtime da imagem candidata.

Uma publicação futura exige autorização operacional específica, backup e conferência do estado de produção naquele momento. Preservar alterações locais e configurar explicitamente o novo flag no ambiente do serviço web; não basta criar uma variável que o Compose não encaminhe. Atualizar web/worker/telegram-scheduler com imagem compatível. Não há migration, rotação de token ou associação automática de colaboradores reais nessa publicação.

Rollback funcional da associação: desabilitar `TELEGRAM_ADMIN_ASSOCIATION_ENABLED`, mantendo o backend compatível e a regra de revogação explícita. Isso esconde a criação administrativa e rejeita novos POSTs, preservando vínculos, autorizações e auditoria existentes. Não voltar cegamente a uma imagem que ignore membro revogado no privado sem grupo. Se for necessária uma reversão completa de imagem depois de uso real, preparar uma imagem compatível que preserve essa regra ou revogar os vínculos afetados pelos controles nativos antes de qualquer corte. Nunca apagar histórico ou restaurar dump sobre produção.

Rollback da linguagem é independente: restaurar somente `Language.php` ao baseline publicado, mantendo os componentes administrativos compatíveis, sem tocar dados, RAG ou máquinas de estados. Os construtores de jobs e o schema permanecem inalterados.

Resultado: laboratório aprovado para as duas alterações. Aguardar autorização específica para publicar.
