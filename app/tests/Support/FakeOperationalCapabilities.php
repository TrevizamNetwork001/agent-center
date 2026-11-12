<?php

namespace Tests\Support;

use App\Diagnostics\OperationalCapabilities;
use App\Models\Agent;

/** Synthetic execution proof only. Never packaged in production images. */
class FakeOperationalCapabilities extends OperationalCapabilities
{
    public array $decisions = [];

    public function set(Agent $agent, string $capability, string $destination, string $protocol, array $decision): void
    {
        $this->decisions[$agent->id][$capability][$destination][$protocol] = $decision;
    }

    public function permission(Agent $agent, string $capability, string $destination, string $protocol): array
    {
        return $this->decisions[$agent->id][$capability][$destination][$protocol] ?? parent::permission($agent, $capability, $destination, $protocol);
    }
}
