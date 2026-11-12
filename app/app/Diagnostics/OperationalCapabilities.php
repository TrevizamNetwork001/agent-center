<?php

namespace App\Diagnostics;

use App\Agent\ManagedSettings;
use App\Agent\Protocol;
use App\Models\Agent;

/** Read-only adapter to the published Agent API. Never evaluates or grants destination ACLs. */
class OperationalCapabilities
{
    public function snapshot(Agent $agent): array
    {
        $online = $agent->status === 'active' && ! $agent->revoked_at && ! $agent->deleted_at
            && $agent->last_heartbeat_at?->gt(now()->subSeconds(60)) && $agent->last_heartbeat_at?->lte(now()->addSeconds(5));
        $compatible = in_array($agent->version, Protocol::VERSIONS, true) && ($agent->metadata['task_protocol'] ?? null) === 1;
        $policy = Protocol::policy($agent->id, $compatible, ManagedSettings::current($agent));

        return ['schema_version' => 1, 'online' => (bool) $online, 'compatible' => $compatible,
            'capabilities' => array_values(array_intersect(Protocol::executionCapabilities($agent), $policy['allowed_capabilities'])),
            'helper_health' => 'UNKNOWN', 'destination_permission' => 'UNKNOWN',
            'source' => 'published_agent_api'];
    }

    public function permission(Agent $agent, string $capability, string $destination, string $protocol): array
    {
        return app(Preflight::class)->permission($agent, $capability, $destination, $protocol);
    }

    public function path(Agent $agent, string $capability, string $destination, string $protocol): ?string
    {
        $snapshot = $this->snapshot($agent);
        if (! $snapshot['online']) {
            return 'agent_unavailable';
        }
        if (! $snapshot['compatible'] || ! in_array($capability, $snapshot['capabilities'], true)) {
            return 'capability_unavailable';
        }
        if (! in_array($protocol, $capability === 'mtr_path_analysis:v1' ? ['icmp', 'tcp'] : ['icmp'], true)) {
            return 'capability_unavailable';
        }
        $decision = $this->permission($agent, $capability, $destination, $protocol);
        if (($decision['state'] ?? '') === 'DENIED') {
            return 'policy_denied';
        }
        if (($decision['state'] ?? '') === 'UNKNOWN' && ($decision['helper_healthy'] ?? null) === false) {
            return 'helper_unavailable';
        }
        if (($decision['state'] ?? '') !== 'ALLOWED') {
            return 'effective_policy_unverified';
        }
        if ($capability === 'mtr_path_analysis:v1' && ($decision['helper_healthy'] ?? null) !== true) {
            return ($decision['helper_healthy'] ?? null) === false ? 'helper_unavailable' : 'helper_unverified';
        }

        return null;
    }
}
