<?php

namespace Tests\Support;

use App\Agent\ManagedSettings;
use App\Agent\Observability;
use App\Models\Agent;

trait PathReadinessFixture
{
    private function pathReady(Agent $agent, string $target = '8.8.8.8', string $protocol = 'icmp', string $capability = 'mtr_path_analysis:v1', string $state = 'ALLOWED'): Agent
    {
        $meta = $agent->metadata ?? [];
        $digest = Observability::managedDigest($agent);
        $revision = ManagedSettings::current($agent)['revision'];
        $meta['observability'] = ['schema_version' => 1, 'received_at' => now()->toIso8601String(), 'collected_at' => now()->toIso8601String(),
            'policy_revision' => $revision, 'policy_digest' => $digest,
            'helper' => ['name' => 'mtr', 'version' => '0.6.2', 'installed' => true, 'service_active' => true, 'socket_available' => true, 'authenticated' => true, 'healthy' => true],
            'capabilities' => [['name' => $capability, 'installed' => true, 'healthy' => true, 'permitted' => true, 'state' => 'AVAILABLE']]];
        $meta['permission_result'] = ['state' => $state, 'received_at' => now()->toIso8601String(), 'checked_at' => now()->toIso8601String(), 'revision' => $revision, 'digest' => $digest,
            'destination' => $target, 'protocol' => $protocol, 'capability' => $capability];
        $agent->forceFill(['metadata' => $meta, 'last_heartbeat_at' => now()])->save();

        return $agent;
    }
}
