<?php

namespace App\Diagnostics;

use App\Agent\Observability;
use App\Agent\Protocol;
use App\Models\Agent;

/** Read existing authenticated reports only. No permission grants or policy mutations. */
class PathReadiness
{
    public function check(Agent $agent, string $capability, string $destination, string $protocol): ?string
    {
        if (! in_array($capability, Protocol::executionCapabilities($agent), true)) {
            return 'capability_unavailable';
        }
        $view = Observability::view($agent);
        if (! $view['fresh'] || ! $view['policyApplied']) {
            return 'policy_not_verified';
        }
        $cap = collect($view['report']['capabilities'] ?? [])->firstWhere('name', $capability);
        if (! $cap || ($cap['installed'] ?? false) !== true || ($cap['healthy'] ?? false) !== true || ($cap['permitted'] ?? false) !== true || ($cap['state'] ?? '') !== 'AVAILABLE') {
            return ($cap['permitted'] ?? null) === false ? 'policy_denied' : 'capability_unavailable';
        }
        if ($capability === 'mtr_path_analysis:v1') {
            $helper = $view['report']['helper'] ?? [];
            foreach (['installed', 'service_active', 'socket_available', 'authenticated', 'healthy'] as $field) {
                if (($helper[$field] ?? false) !== true) {
                    return 'helper_unavailable';
                }
            }
        }
        $result = $view['result'];
        $matches = $result && ($result['capability'] ?? null) === $capability && ($result['protocol'] ?? null) === $protocol
            && ($result['destination'] ?? null) === strtolower(rtrim($destination, '.'));
        if (! $matches || ($result['state'] ?? '') === 'UNKNOWN') {
            return 'policy_not_verified';
        }

        return $result['state'] === 'ALLOWED' ? null : 'policy_denied';
    }

    public function explanation(string $reason): string
    {
        return match ($reason) {
            'policy_denied' => 'A política efetiva não autoriza esta capability, destino ou protocolo.',
            'helper_unavailable' => 'O helper MTR não está saudável e autenticado.',
            'capability_unavailable' => 'A capability de caminho não está instalada, saudável ou compatível neste Agent.',
            default => 'Falta uma verificação recente da política efetiva para este destino e protocolo. Use a verificação de permissões já existente no painel do Agent e solicite novamente.',
        };
    }
}
