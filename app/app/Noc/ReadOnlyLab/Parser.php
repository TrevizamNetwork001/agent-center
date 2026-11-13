<?php

namespace App\Noc\ReadOnlyLab;

use App\Models\NocDevice;
use App\Noc\Analysis\Contracts;
use App\Noc\Analysis\Evidence;

/** Strict fixture dialects. Unrecognized/truncated vendor output fails closed, never implies health. */
final class Parser
{
    public function parse(NocDevice $d, Operation $operation, array $wire): array
    {
        $c = app(Contracts::class)->require($d, $operation->value);
        $huawei = $c['specialist'] === Platform::Huawei->value;
        $id = $this->identity(Platform::from($c['specialist']), $wire['identity']);
        if (strcasecmp($d->model, $id['model']) !== 0 || preg_replace('/^RouterOS | \(stable\)$/i', '', $d->os_version) !== $id['os_version']) {
            throw new Failure('HOST_IDENTITY_REJECTED');
        }
        $text = $wire['output'];
        $payload = match ($operation) {
            Operation::Bgp => $this->bgp($text, $huawei),
            Operation::Route => $this->route($text, $huawei),
            Operation::Interfaces => $this->interfaces($text, $huawei),
            Operation::Resources => $this->resources($text, $huawei),
        };

        return app(Evidence::class)->parse($d, $c, json_encode(['schema_version' => 1, 'mode' => 'SIMULATED', 'environment' => 'LAB',
            'identity' => $id,
            'contract_id' => $operation->value, 'status' => 'OK', 'payload' => $payload], JSON_THROW_ON_ERROR));
    }

    public function identity(Platform $platform, string $text): array
    {
        $huawei = $platform === Platform::Huawei;
        if ($huawei) {
            $id = $this->one('/\AVRP \(R\) software, Version 8\.230 \((V800R023C00SPC500)\)\nHUAWEI (NE8000 M4)\n\z/', $text);
            $version = $id[1];
            $model = $id[2];
        } else {
            $id = $this->one('/\Aversion: (7\.16\.2)\nboard-name: (CCR2004-16G-2S\+)\ncpu-load: ([0-9]{1,3})%\ntotal-memory: ([0-9]{1,16})\nfree-memory: ([0-9]{1,16})\n\z/', $text);
            $version = $id[1];
            $model = $id[2];
        }

        return ['manufacturer' => $huawei ? 'huawei' : 'mikrotik', 'model' => $model, 'platform_family' => $huawei ? 'VRP8' : 'ROUTEROS7', 'os_version' => $version];
    }

    private function one(string $pattern, string $text): array
    {
        if (strlen($text) > 65536 || ! preg_match($pattern, $text, $matches)) {
            throw new Failure('UNRECOGNIZED_OR_TRUNCATED_OUTPUT');
        }

        return $matches;
    }

    private function lines(string $text, string $header): array
    {
        if (! str_starts_with($text, $header."\n") || ! str_ends_with($text, "\n") || strlen($text) > 65536) {
            throw new Failure('UNRECOGNIZED_OR_TRUNCATED_OUTPUT');
        }
        $rows = explode("\n", substr($text, strlen($header) + 1, -1));
        if (count($rows) > 128 || in_array('', $rows, true)) {
            throw new Failure('INVALID_ROWS');
        }

        return $rows;
    }

    private function bgp(string $text, bool $h): array
    {
        $items = [];
        foreach ($this->lines($text, $h ? 'Peer V AS MsgRcvd MsgSent OutQ Up/Down State PrefRcv' : 'Flags: E - established') as $line) {
            $m = $this->one($h ? '/\A([0-9.]+) 4 [0-9]+ [0-9]+ [0-9]+ 0 ([0-9]{2}):([0-9]{2}):([0-9]{2}) (Established|Idle|Active) [0-9]+\z/' : '/\A[0-9]+ remote.address=([0-9.]+) uptime=([0-9]{2}):([0-9]{2}):([0-9]{2}) state=(established|idle|active) address-families=ip\z/', $line);
            if ((int) $m[3] > 59 || (int) $m[4] > 59) {
                throw new Failure('INVALID_ROWS');
            }
            $items[] = ['peer' => $m[1], 'state' => strtoupper($m[5]), 'uptime_seconds' => ((int) $m[2]) * 3600 + ((int) $m[3]) * 60 + (int) $m[4], 'address_family' => 'IPV4_UNICAST', 'transitions' => []];
        }

        return [$h ? 'peer_rows' : 'sessions' => $items];
    }

    private function route(string $text, bool $h): array
    {
        $m = $this->one($h ? '/\ADestination\/Mask Proto Pre Cost Flags NextHop Interface\n(8\.8\.8\.8\/32) (BGP) ([0-9]+) ([0-9]+) RD ([0-9.]+) [A-Za-z0-9\/]+\n\z/' : '/\AFlags: A - active; b - bgp\n0 Ab dst-address=(8\.8\.8\.8\/32) gateway=([0-9.]+) distance=([0-9]+) routing-table=main\n\z/', $text);
        $row = ['prefix' => $m[1], 'protocol' => 'BGP', 'next_hop' => $h ? $m[5] : $m[2], 'preference' => (int) $m[3], 'metric' => $h ? (int) $m[4] : null];

        return ['destination' => '8.8.8.8', 'table' => 'main', 'found' => true, $h ? 'route_rows' : 'items' => [$row]];
    }

    private function interfaces(string $text, bool $h): array
    {
        $items = [];
        foreach ($this->lines($text, $h ? 'Interface Admin Physical Speed RX-bytes TX-bytes RX-errors TX-errors' : 'Flags: R - running; X - disabled') as $line) {
            $m = $this->one($h ? '/\A([A-Za-z0-9\/]+) (UP|DOWN) (UP|DOWN) ([0-9]+) ([0-9]+) ([0-9]+) ([0-9]+) ([0-9]+)\z/' : '/\A[0-9]+ name=([A-Za-z0-9-]+) disabled=(false|true) running=(true|false) speed=([0-9]+) rx-byte=([0-9]+) tx-byte=([0-9]+) rx-error=([0-9]+) tx-error=([0-9]+)\z/', $line);
            $items[] = ['interface' => $m[1], 'admin_state' => $h ? $m[2] : ($m[2] === 'false' ? 'UP' : 'DOWN'),
                'oper_state' => $h ? $m[3] : ($m[3] === 'true' ? 'UP' : 'DOWN'), 'speed_bps' => (int) $m[4], 'flaps_total' => null,
                'rx_bytes' => (int) $m[5], 'tx_bytes' => (int) $m[6], 'rx_errors' => (int) $m[7], 'tx_errors' => (int) $m[8]];
        }

        return [$h ? 'interface_rows' : 'interfaces' => $items];
    }

    private function resources(string $text, bool $h): array
    {
        if ($h) {
            $m = $this->one('/\ASlot CPU Usage Memory Usage\n0 ([0-9]{1,3})% ([0-9]{1,3})%\n\z/', $text);
            $cpu = (int) $m[1];
            $memory = (int) $m[2];
            $total = null;
            $used = null;
        } else {
            $m = $this->one('/\Aversion: 7\.16\.2\nboard-name: CCR2004-16G-2S\+\ncpu-load: ([0-9]{1,3})%\ntotal-memory: ([0-9]{1,16})\nfree-memory: ([0-9]{1,16})\n\z/', $text);
            $cpu = (int) $m[1];
            $total = (int) $m[2];
            $free = (int) $m[3];
            if ($free > $total || $total <= 0) {
                throw new Failure('INVALID_MEMORY');
            }
            $used = $total - $free;
            $memory = round(100 * $used / $total, 2);
        }

        return ['cpu_percent' => $cpu, 'memory_percent' => $memory, 'memory_total_bytes' => $total, 'memory_used_bytes' => $used,
            'window_seconds' => null, 'references' => ['cpu_percent' => null, 'memory_percent' => null, 'source' => 'NONE']];
    }
}
