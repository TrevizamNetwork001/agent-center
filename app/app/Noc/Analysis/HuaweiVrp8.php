<?php

namespace App\Noc\Analysis;

class HuaweiVrp8 implements EvidenceParser
{
    public function normalize(array $c, array $p): array
    {
        return match ($c['kind']) {
            'bgp' => $this->rows($p, 'peer_rows'), 'interfaces' => $this->rows($p, 'interface_rows'), 'counters' => $this->rows($p, 'counter_rows'),
            'route' => $this->route($p),default => $p,
        };
    }

    private function rows(array $p, string $key): array
    {
        if (array_keys($p) !== [$key]) {
            app(Schema::class)->invalid('huawei_payload');
        }

        return ['items' => $p[$key]];
    }

    private function route(array $p): array
    {
        if (array_diff(array_keys($p), ['destination', 'table', 'found', 'route_rows'])) {
            app(Schema::class)->invalid('huawei_route');
        }$p['items'] = $p['route_rows'] ?? null;
        unset($p['route_rows']);

        return $p;
    }
}
