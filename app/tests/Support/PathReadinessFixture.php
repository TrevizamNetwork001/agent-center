<?php

namespace Tests\Support;

use App\Diagnostics\OperationalCapabilities;
use App\Models\Agent;

trait PathReadinessFixture
{
    private function pathReady(Agent $agent, string $target = '8.8.8.8', string $protocol = 'icmp', string $capability = 'mtr_path_analysis:v1', string $state = 'ALLOWED'): Agent
    {
        $reader = app(OperationalCapabilities::class);
        if (! $reader instanceof FakeOperationalCapabilities) {
            $reader = new FakeOperationalCapabilities;
            app()->instance(OperationalCapabilities::class, $reader);
        }
        $reader->set($agent, $capability, $target, $protocol, ['state' => $state, 'helper_healthy' => true]);
        $agent->forceFill(['last_heartbeat_at' => now()])->save();

        return $agent;
    }
}
