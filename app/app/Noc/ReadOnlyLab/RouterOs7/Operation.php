<?php

namespace App\Noc\ReadOnlyLab\RouterOs7;

enum Operation: string
{
    case Identity = 'DEVICE_IDENTITY_READ';
    case Bgp = 'DEVICE_BGP_PEERS_READ';
    case Route = 'DEVICE_ROUTE_LOOKUP_READ';
    case Interfaces = 'DEVICE_INTERFACES_READ';
    case Counters = 'DEVICE_INTERFACE_COUNTERS_READ';
    case Resources = 'DEVICE_RESOURCES_READ';

    public function contract(): string
    {
        return $this === self::Identity ? 'DEVICE_IDENTIFICATION_READ' : $this->value;
    }
}
