# Prompts

Prompts são tenant-specific e ficam em /advanced/prompts, somente administradores do contexto. O mesmo mecanismo append-only de Knowledge usa prompt_version_contents + version_events, projetados pela view security_invoker prompt_versions. Publicação usa CAS do draft e versões publicadas nunca são editadas.

Purpose allowlist: diagnostic_summary, diagnostic_explanation, knowledge_assistance. A interpretação implementada usa diagnostic_explanation; os demais ficam preparados para consumidores futuros, sem Conversation Engine.

Preview é uma renderização literal local. Não usa Blade::render, eval, HTTP, modelo, tools ou rede. Variáveis permitidas: classification, profile, target, evidence, knowledge, no formato {{nome}}. Variáveis desconhecidas ou delimitadores quebrados falham; valores inseridos não são interpolados uma segunda vez. Preview usa valores fixos identificados como exemplo.

expected_output_schema é fixado no servidor: summary, explanation e next_steps textuais; additionalProperties=false. Não aceita classification, commands, tools ou estados técnicos. O schema do provider é auxiliar: validação local é obrigatória, independentemente da resposta remota.

Boundary de autoridade permanece fixo no código, fora do template editável. Templates administrativos e Knowledge são enviados como contexto de baixa autoridade; nenhuma instrução textual cria capacidades de execução. O cliente não registra ou envia tools/functions. Respostas com tool_calls/function_call são rejeitadas, sem loop de execução.

Auditoria: prompt.created, version_created, published, superseded, archived e rollback_created quando usado pelo serviço. Conteúdo/template não entra no audit metadata e não é gravado em logs. Arquivamento impede nova seleção; IDs antigos permanecem para proveniência.
