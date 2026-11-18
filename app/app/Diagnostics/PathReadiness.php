<?php

namespace App\Diagnostics;

use App\Models\Agent;

/** Read existing authenticated reports only. No permission grants or policy mutations. */
class PathReadiness
{
    public function check(Agent $agent, string $capability, string $destination, string $protocol): ?string
    {
        return app(OperationalCapabilities::class)->path($agent, $capability, $destination, $protocol);
    }

    public function explanation(string $reason): string
    {
        return match ($reason) {
            'preflight_pending' => 'Consulta de autorização pendente; aguardando resposta autenticada do Agent.',
            'preflight_stale' => 'A decisão anterior venceu; uma nova consulta de autorização foi solicitada.',
            'preflight_timeout' => 'A consulta anterior terminou sem resposta no prazo. Isso não identifica, por si só, uma falha de rede específica.',
            'preflight_busy' => 'Outra consulta está em andamento neste Agent. Nenhuma autorização foi presumida.',
            'preflight_incompatible' => 'Autorização efetiva não comprovada: a versão deste Agent não suporta o contrato de preflight solicitado.',
            'preflight_method_unsupported' => 'Autorização efetiva de tracert/traceroute ainda não pode ser comprovada pelo contrato atual, que cobre somente MTR. Nenhum método substituto foi selecionado.',
            'policy_denied' => 'A política efetiva não autoriza esta capability, destino ou protocolo.',
            'helper_unavailable' => 'O helper MTR não está saudável e autenticado.',
            'helper_unverified' => 'A saúde atual do helper MTR não pôde ser comprovada pelo contrato operacional disponível.',
            'agent_unavailable' => 'O Agent está offline, revogado ou sem heartbeat recente.',
            'capability_unavailable' => 'A capability de caminho não está instalada, saudável ou compatível neste Agent.',
            default => 'Autorização efetiva não comprovada: não há uma decisão atual e verificável por destino/protocolo e saúde do helper. A consulta pode estar pendente, indisponível ou incompatível; solicite novamente o planejamento. A capability anunciada não autoriza a execução. Nenhuma ACL foi alterada.',
        };
    }
}
