<?php

namespace App\Noc;

use App\Models\NocDevice;
use App\Noc\Analysis\Contracts;

/** Administrative metadata only: no transport, credentials, jobs or execution authority. */
final class ReadOnlyAdmin
{
    public const CONTRACTS = ['DEVICE_BGP_PEERS_READ', 'DEVICE_ROUTE_LOOKUP_READ', 'DEVICE_INTERFACES_READ', 'DEVICE_RESOURCES_READ'];

    public function forDevice(NocDevice $device): array
    {
        return ['mode' => 'ADMINISTRATIVE_DEMO', 'display_mode' => 'SIMULADO', 'device_freeze' => true,
            'operational_enabled' => false, 'transport' => 'NONE', 'credentials_enabled' => false,
            'contracts' => array_values(array_filter(app(Contracts::class)->forDevice($device), fn ($c) => in_array($c['id'], self::CONTRACTS, true)))];
    }
}
