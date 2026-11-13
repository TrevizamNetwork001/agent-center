<?php

namespace App\Noc\ReadOnlyLab\RouterOs7;

use App\Noc\Analysis\Schema;
use App\Noc\ReadOnlyLab\Failure;

/** Typed projection of the closed console dialect. No raw console text is persisted. */
final class Evidence
{
    public function parse(Operation $op, array $wire, array $params): array
    {
        $p = app(Protocol::class);
        $resource = $p->resource($wire['identity']);
        $outputs = array_map(fn (string $output) => str_replace("\r\n", "\n", $output), $wire['outputs']);
        $data = match ($op) {
            Operation::Identity => $this->identity($outputs[0]),
            Operation::Bgp => $this->bgp($outputs[0], $outputs[1]),
            Operation::Route => $this->routes($outputs, $params),
            Operation::Interfaces => $this->interfaces($outputs[0], false),
            Operation::Counters => $this->interfaces($outputs[0], true),
            Operation::Resources => $this->resources($outputs[0]),
        };
        if (isset($params['peer'])) {
            $data['items'] = array_values(array_filter($data['items'], fn ($r) => $r['remote_address'] === $params['peer']));
        }
        if (isset($params['interface'])) {
            $data['items'] = array_values(array_filter($data['items'], fn ($r) => $r['interface'] === $params['interface']));
        }

        app(Schema::class)->check($data, $p->contract($op)['output_schema']);

        return ['schema_version' => 1, 'contract_id' => $op->value, 'mode' => 'SIMULATED', 'environment' => 'LAB',
            'identity' => ['manufacturer' => 'mikrotik', 'model' => $resource['board-name'], 'platform_family' => 'ROUTEROS7', 'os_version' => Protocol::VERSION],
            'complete' => false, 'status' => 'PARTIAL', 'data' => $data];
    }

    private function identity(string $output): array
    {
        if (! preg_match('/\A\s*name: ([A-Za-z0-9_.-]{1,64})\n\z/', $output, $m)) {
            throw new Failure('INVALID_IDENTITY');
        }

        return ['name' => $m[1], 'version' => Protocol::VERSION];
    }

    private function bgp(string $output, string $configured): array
    {
        if (! preg_match('/\A[0-9]{1,6}\n\z/', $configured)) {
            throw new Failure('INVALID_BGP_ROW');
        }
        $p = app(Protocol::class);
        $items = [];
        foreach ($p->rows($output, ['name', 'remote.address', 'remote.as', 'remote.afi', 'local.afi', 'uptime', 'established', 'instance', 'remote.port', 'remote.id', 'remote.capabilities', 'remote.messages', 'remote.bytes', 'remote.eor', 'remote.hold-time', 'local.role', 'local.address', 'local.port', 'local.as', 'local.id', 'local.cluster-id', 'local.capabilities', 'local.afi', 'local.messages', 'local.bytes', 'local.eor', 'output.procid', 'input.procid', 'multihop', 'hold-time', 'keepalive-time', 'last-started', 'prefix-count']) as $r) {
            if (! isset($r['name'], $r['remote.address']) || ! filter_var($r['remote.address'], FILTER_VALIDATE_IP)) {
                throw new Failure('INVALID_BGP_ROW');
            }
            $asn = $p->number($r['remote.as'] ?? null);
            if ($asn !== null && ($asn < 1 || $asn > 4294967295)) {
                throw new Failure('INVALID_BGP_ROW');
            }
            $families = isset($r['remote.afi']) ? explode(',', $r['remote.afi']) : null;
            if ($families !== null && array_diff($families, ['ip', 'ipv6', 'l2vpn', 'l2vpn-cisco', 'vpnv4', 'vpnv6', 'evpn'])) {
                throw new Failure('INVALID_BGP_ROW');
            }
            if (isset($r['established']) && ! in_array($r['established'], ['true', 'false'], true)) {
                throw new Failure('INVALID_BGP_ROW');
            }
            $established = str_contains($r['flags'], 'E') || ($r['established'] ?? '') === 'true';
            if (str_contains($r['flags'], 'E') && ($r['established'] ?? '') === 'false') {
                throw new Failure('INVALID_BGP_ROW');
            }
            $items[] = ['peer' => $this->label($r['name']), 'remote_address' => $r['remote.address'], 'remote_asn' => $asn,
                'state' => $established ? 'ESTABLISHED' : 'NOT_ESTABLISHED', 'uptime_seconds' => $p->duration($r['uptime'] ?? null),
                'advertised_families' => $families, 'negotiated_families' => null];
        }
        $this->unique($items, 'peer');
        if ($items !== [] && (int) $configured === 0) {
            throw new Failure('INVALID_BGP_ROW');
        }

        return ['items' => $items, 'availability_reason' => $items === [] ? ((int) $configured === 0 ? 'NO_BGP_CONNECTIONS_CONFIGURED' : 'NO_SESSIONS_OBSERVED') : null,
            'configuration_present' => (int) $configured > 0 ? true : null, 'connections_configured' => (int) $configured > 0];
    }

    private function routes(array $outputs, array $params): array
    {
        $p = app(Protocol::class);
        $items = [];
        $total = 0;
        $commands = $p->commands(Operation::Route, $params);
        foreach ($outputs as $index => $output) {
            preg_match('/dst-address=([^ ]+)/', $commands[$index], $prefix);
            foreach ($p->rows($output, ['dst-address', 'routing-table', 'gateway', 'immediate-gw', 'distance', 'active', 'dynamic', 'disabled', 'bgp', 'ospf', 'static', 'connect', 'belongs-to', 'afi', 'contribution', 'pref-src', 'scope', 'target-scope', 'vrf-interface', 'suppress-hw-offload', 'local-address', 'debug.fwp-ptr', 'rpki', 'bgp.peer-cache-id', 'bgp.as-path', 'bgp.origin']) as $r) {
                if (++$total > 128 || ($r['dst-address'] ?? '') !== $prefix[1] || ($r['routing-table'] ?? '') !== ($params['table'] ?? 'main')) {
                    throw new Failure('INVALID_ROUTE_ROW');
                }
                foreach (['active', 'dynamic', 'disabled', 'bgp', 'ospf', 'static', 'connect'] as $flag) {
                    if (isset($r[$flag]) && ! in_array($r[$flag], ['true', 'false'], true)) {
                        throw new Failure('INVALID_ROUTE_ROW');
                    }
                }
                $active = str_contains($r['flags'], 'A') || ($r['active'] ?? '') === 'true';
                if (! $active || str_contains($r['flags'], 'X') || ($r['disabled'] ?? '') === 'true') {
                    continue;
                }
                $gateway = $r['gateway'] ?? null;
                if ($gateway !== null && ! filter_var($gateway, FILTER_VALIDATE_IP)) {
                    $gateway = null; // Interface gateways and recursive resolution are not IP next hops.
                }
                $protocol = match (true) {
                    str_contains($r['flags'], 'b'), ($r['bgp'] ?? '') === 'true' => 'BGP',
                    str_contains($r['flags'], 'o'), ($r['ospf'] ?? '') === 'true' => 'OSPF',
                    str_contains($r['flags'], 's'), ($r['static'] ?? '') === 'true' => 'STATIC',
                    str_contains($r['flags'], 'c'), ($r['connect'] ?? '') === 'true' => 'CONNECTED',
                    default => 'UNKNOWN',
                };
                $items[] = ['prefix' => $r['dst-address'], 'protocol' => $protocol, 'configured_gateway' => $gateway,
                    'effective_next_hop' => null, 'preference' => $p->number($r['distance'] ?? null), 'metric' => null,
                    'active' => true, 'dynamic' => isset($r['dynamic']) ? $r['dynamic'] === 'true' : (str_contains($r['flags'], 'D') ? true : null)];
            }
            if ($items !== []) {
                break; // First nonempty active ancestor is the longest matching observed RIB prefix.
            }
        }

        return ['destination' => $params['destination'], 'table' => $params['table'] ?? 'main', 'found' => $items !== [], 'items' => $items,
            'lookup_semantics' => 'LONGEST_ACTIVE_OBSERVED_RIB_PREFIX', 'forwarding_verified' => false,
            'availability_reason' => $items === [] ? 'NO_ACTIVE_ROUTE_OBSERVED' : null];
    }

    private function interfaces(string $output, bool $counters): array
    {
        $p = app(Protocol::class);
        $items = [];
        foreach ($p->rows($output, ['name', 'type', 'mtu', 'actual-mtu', 'l2mtu', 'max-l2mtu', 'mac-address', 'last-link-up-time', 'last-link-down-time', 'link-downs', 'disabled', 'running', 'rx-byte', 'tx-byte', 'rx-packet', 'tx-packet', 'rx-drop', 'tx-drop', 'tx-queue-drop', 'rx-error', 'tx-error', 'fp-rx-byte', 'fp-tx-byte', 'fp-rx-packet', 'fp-tx-packet']) as $r) {
            if (! isset($r['name'])) {
                throw new Failure('INVALID_INTERFACE_ROW');
            }
            foreach (['disabled', 'running'] as $flag) {
                if (isset($r[$flag]) && ! in_array($r[$flag], ['true', 'false'], true)) {
                    throw new Failure('INVALID_INTERFACE_ROW');
                }
            }
            $row = ['interface' => $this->label($r['name']),
                'admin_state' => isset($r['disabled']) ? ($r['disabled'] === 'true' ? 'DOWN' : 'UP') : (str_contains($r['flags'], 'X') ? 'DOWN' : 'UNKNOWN'),
                'oper_state' => isset($r['running']) ? ($r['running'] === 'true' ? 'UP' : 'DOWN') : (str_contains($r['flags'], 'R') ? 'UP' : 'UNKNOWN'),
                'speed_bps' => null];
            foreach (['rx-byte', 'tx-byte', 'rx-packet', 'tx-packet', 'rx-drop', 'tx-drop', 'tx-queue-drop', 'rx-error', 'tx-error', 'link-downs'] as $key) {
                $row[str_replace('-', '_', $key)] = $p->number($r[$key] ?? null);
            }
            $row['sample_timestamp'] = now()->toIso8601String();
            $row['rate'] = null;
            $items[] = $row;
        }
        $this->unique($items, 'interface');

        return ['items' => $items, 'availability_reason' => $items === [] ? 'NO_INTERFACES_OBSERVED' : null, 'sample_kind' => $counters ? 'ACCUMULATED_COUNTERS' : 'STATE'];
    }

    private function resources(string $output): array
    {
        $p = app(Protocol::class);
        $r = $p->resource($output);
        $total = $p->bytes($r['total-memory'] ?? null);
        $free = $p->bytes($r['free-memory'] ?? null);
        if ($total !== null && $free !== null && ($total <= 0 || $free > $total)) {
            throw new Failure('INVALID_MEMORY');
        }
        if (isset($r['cpu-load']) && ! preg_match('/\A[0-9]{1,3}%?\z/', $r['cpu-load'])) {
            throw new Failure('INVALID_CPU');
        }
        $cpu = isset($r['cpu-load']) ? $p->number(rtrim($r['cpu-load'], '%')) : null;
        if ($cpu !== null && $cpu > 100) {
            throw new Failure('INVALID_CPU');
        }

        return ['cpu_percent' => $cpu, 'memory_total_bytes' => $total, 'memory_free_bytes' => $free,
            'memory_percent' => $total !== null && $free !== null ? round(100 * ($total - $free) / $total, 2) : null,
            'uptime_seconds' => $p->duration($r['uptime'] ?? null), 'version' => Protocol::VERSION,
            'availability_reason' => $cpu === null || $total === null || $free === null ? 'MEASUREMENTS_INCOMPLETE' : null];
    }

    private function label(string $value): string
    {
        if (! preg_match('/\A[A-Za-z0-9_.:+\/-]{1,120}\z/', $value)) {
            throw new Failure('INVALID_LABEL');
        }

        return $value;
    }

    private function unique(array $items, string $key): void
    {
        $ids = array_column($items, $key);
        if (count($ids) !== count(array_unique($ids))) {
            throw new Failure('DUPLICATE_ROW');
        }
    }
}
