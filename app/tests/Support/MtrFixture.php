<?php

namespace Tests\Support;

class MtrFixture
{
    public static function data(string $name = 'healthy', string $protocol = 'icmp'): array
    {
        $raw = json_decode(file_get_contents(base_path('tests/Fixtures/mtr/'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR);
        $raw['report']['mtr'] = ['protocol' => $protocol];
        $hops = [];
        foreach ($raw['report']['hubs'] as $h) {
            $responded = $h['host'] !== '???' && $h['Loss%'] < 100;
            $hops[] = ['hop_index' => $h['count'], 'address' => $h['host'] === '???' ? null : $h['host'], 'hostname' => null, 'sent' => $h['Snt'], 'received' => null, 'loss_percent' => $h['Loss%'],
                'avg_ms' => $responded ? $h['Avg'] : null, 'best_ms' => $responded ? $h['Best'] : null, 'worst_ms' => $responded ? $h['Wrst'] : null, 'stdev_ms' => $responded ? $h['StDev'] : null, 'responded' => $responded, 'asn' => null];
        }
        $last = end($hops);

        return ['destination' => '8.8.8.8', 'resolved_ip' => '8.8.8.8', 'protocol' => $protocol, 'cycles' => 5, 'total_duration_ms' => 6000,
            'destination_reached' => $last['address'] === '8.8.8.8' && $last['responded'], 'hops' => $hops, 'status' => 'collected', 'error' => null, 'raw_sanitized' => $raw];
    }
}
