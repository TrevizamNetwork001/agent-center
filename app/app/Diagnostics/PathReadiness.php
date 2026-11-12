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
            'policy_denied' => 'A política efetiva não autoriza esta capability, destino ou protocolo.',
            'helper_unavailable' => 'O helper MTR não está saudável e autenticado.',
            'helper_unverified' => 'A saúde atual do helper MTR não pôde ser comprovada pelo contrato operacional disponível.',
            'agent_unavailable' => 'O Agent está offline, revogado ou sem heartbeat recente.',
            'capability_unavailable' => 'A capability de caminho não está instalada, saudável ou compatível neste Agent.',
            default => 'Autorização efetiva não comprovada: não há uma decisão atual e verificável por destino/protocolo e saúde do helper. A consulta pode estar pendente, indisponível ou incompatível; solicite novamente o planejamento. A capability anunciada não autoriza a execução. Nenhuma ACL foi alterada.',
        };
    }
}
