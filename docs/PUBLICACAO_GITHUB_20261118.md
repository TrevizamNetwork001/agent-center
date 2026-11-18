# Conciliação e publicação no GitHub — 18/11/2026

Escopo autorizado: corrigir pendências do repositório, consolidar documentação e publicar fonte/histórico no GitHub. Não é deploy da Central, release operacional assinada, atualização de Agent ou autorização de device.

## Bases preservadas

- Checkout inicial: `f227293ee7e01bcdb10e909a7fe0a40293bbd995`.
- GitHub inicial: `818d57bc022e07ecea3daa0a5d85316a4e92fbc3`.
- Base comum: `ff168b9c96397c7b7b1e6c180796792eb7e7c2ed`.
- Diferença inicial: 120 commits exclusivos locais e 19 exclusivos no remoto.
- As alterações de fonte, testes, interface e documentação que estavam sem commit foram preservadas em snapshot de integração.

A conciliação foi preparada em `/tmp/agent-center-publication-20261118`, sem alterar containers de produção. O merge conserva os dois históricos. Nos conflitos de Agent, Laravel, catálogo, workers e views, a implementação local posterior preserva Lifecycle, Preflight, observabilidade, freeze e contratos atuais. Arquivos exclusivos do remoto foram revisados e incorporados, incluindo testes de onboarding, telemetria, instalador e backup. Documentos RECOVERY continuam históricos, com indicação do fluxo consolidado.

## Correções desta revisão

- README e fluxo consolidado com links para os módulos e fechamentos de implantação, separando LAB, runtime e GitHub.
- Testes remotos usam a versão efetiva do pacote e o contrato atual de falha do self-check. Recuperação não verificada permanece UNCERTAIN, em vez de afirmar FAILED sem comprovar rollback.
- Runner de laboratório usa `grep -Fx` para verificar rede interna sem exigir `rg` instalado.
- Wrapper Compose preserva os overlays atuais e permite arquivo de ambiente explícito para validações isoladas.
- Descrição da unit do Agent não fixa uma versão obsoleta.
- Artefatos locais de build e pacotes foram excluídos pelo `.gitignore`; nenhum arquivo de `artifacts/` foi incorporado. Distribuição operacional continua pelo Lifecycle assinado.
- Whitespace de relatórios textuais novos normalizado; conteúdo de resultados preservado. Patches mantêm seus espaços de contexto, com atributo Git específico para evitar tratá-los como erro de whitespace do documento.

## Verificações

Resultados e limitações da rodada serão registrados aqui antes do envio final. Os logs novos ficam no diretório `docs/evidence/publicacao-github-20261118/`; resultados antigos não substituem esta validação.

A primeira execução Python revelou incompatibilidades em testes históricos; a primeira preparação Laravel não encontrou DNS para baixar dependências. Foram corrigidos os testes e usadas as dependências já instaladas, compatíveis com o lockfile, sem mudar versões. O build frontend usou instalação limpa via lockfile. A indisponibilidade de rede do Composer impede apresentar essa rodada como auditoria atualizada de advisories.

A varredura inicial de todas as referências do clone encontrou um bundle Git antigo em uma branch auxiliar (`faeda52`), ausente dos históricos de main local e remoto. As referências auxiliares não participam da publicação; nenhum histórico de main precisou ser reescrito. O bundle permanece apenas na referência local original, fora do conjunto enviado.

## Preservação e recuperação

Secrets, banco real, backups, credenciais, dependências vendorizadas e artefatos de build permanecem fora do GitHub. Não foram acessados equipamentos nem acionadas APIs Telegram/OpenAI/R2 reais. Os testes criam e removem recursos descartáveis próprios; migrations de teste não usam a base operacional.

A publicação usa push normal, sem force, seguido de consulta ao hash remoto. O estado anterior do GitHub é o commit `818d57b`, preservado como ancestral do merge. Uma correção futura deve ser outro commit revisado; não apagar histórico nem executar reset remoto. Para rollback operacional, seguir o relatório da imagem/release efetivamente implantada, com autorização própria e preservação de dados. Reverter fonte no GitHub não altera runtime nem banco automaticamente.
