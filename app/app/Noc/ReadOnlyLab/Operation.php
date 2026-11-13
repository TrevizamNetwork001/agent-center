<?php

namespace App\Noc\ReadOnlyLab;

enum Operation: string
{
    case Bgp = 'DEVICE_BGP_PEERS_READ';
    case Route = 'DEVICE_ROUTE_LOOKUP_READ';
    case Interfaces = 'DEVICE_INTERFACES_READ';
    case Resources = 'DEVICE_RESOURCES_READ';
}
