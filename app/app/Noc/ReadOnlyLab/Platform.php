<?php

namespace App\Noc\ReadOnlyLab;

/** No endpoint/address argument. Inventory IPs and DNS are never used by this transport. */
enum Platform: string
{
    case Huawei = 'ne8000-vrp8';
    case RouterOs = 'ccr-routeros7';

    public function port(): int
    {
        return $this === self::Huawei ? 2222 : 2223;
    }

    public function identityCommand(): string
    {
        return $this === self::Huawei ? 'display version' : '/system resource print';
    }

    public function command(Operation $operation, array $parameters): string
    {
        // All dynamic values have a second, narrower validation at the protocol boundary.
        if ($operation === Operation::Route && (($parameters['destination'] ?? null) !== '8.8.8.8' || ($parameters['table'] ?? 'main') !== 'main')) {
            throw new Failure('LAB_ROUTE_NOT_SUPPORTED');
        }

        return match ($this) {
            self::Huawei => match ($operation) {
                Operation::Bgp => 'display bgp peer',
                Operation::Route => 'display ip routing-table 8.8.8.8',
                Operation::Interfaces => 'display interface',
                Operation::Resources => 'display health',
            },
            self::RouterOs => match ($operation) {
                Operation::Bgp => '/routing bgp session print detail without-paging',
                Operation::Route => '/ip route print detail without-paging where dst-address=8.8.8.8/32',
                Operation::Interfaces => '/interface print stats-detail without-paging',
                Operation::Resources => '/system resource print',
            },
        };
    }
}
