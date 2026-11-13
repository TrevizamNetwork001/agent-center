<?php

namespace App\Noc\Analysis;

use App\Models\NocDevice;

class Evidence
{
    public function parse(NocDevice $device, array $contract, string $json): array
    {
        $contract = app(Contracts::class)->require($device, $contract['id']);
        $schema = app(Schema::class);
        if (strlen($json) > $contract['max_evidence_bytes']) {
            $schema->invalid('size');
        }
        try {
            $r = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $schema->invalid('json');
        }
        if (! is_array($r) || array_diff(array_keys($r), ['schema_version', 'mode', 'environment', 'identity', 'contract_id', 'status', 'payload']) || count($r) !== 7 || $r['schema_version'] !== 1 || $r['mode'] !== 'SIMULATED' || $r['environment'] !== 'LAB' || $r['contract_id'] !== $contract['id'] || ! in_array($r['status'], ['OK', 'PARTIAL', 'UNAVAILABLE'], true) || ! is_array($r['payload'])) {
            $schema->invalid('envelope');
        }
        $id = $r['identity'];
        if (! is_array($id) || array_diff(array_keys($id), ['manufacturer', 'model', 'platform_family', 'os_version']) || count($id) !== 4) {
            $schema->invalid('identity');
        }
        foreach ($id as $value) {
            if (! is_string($value)) {
                $schema->invalid('identity_type');
            }
        }
        $platform = app(Contracts::class)->platforms()[$contract['specialist']];
        if ($id['manufacturer'] !== $platform['manufacturer'] || $id['platform_family'] !== $platform['family'] || ! preg_match('~'.$platform['model_pattern'].'~iD', (string) $id['model']) || ! preg_match('~'.$platform['version_pattern'].'~iD', (string) $id['os_version'])) {
            $schema->invalid('platform_version');
        }
        // A family declaration is not an identity proof. Never accept a different declared release.
        $declared = preg_replace('/^RouterOS | \(stable\)$/i', '', (string) $device->os_version);
        if (strcasecmp((string) $device->model, (string) $id['model']) !== 0 || strcasecmp($declared, $id['os_version']) !== 0) {
            $schema->invalid('inventory_revision_identity');
        }
        $parser = $contract['specialist'] === 'ne8000-vrp8' ? app(HuaweiVrp8::class) : app(RouterOs7::class);
        $data = $parser->normalize($contract, $r['payload']);
        $schema->check($data, $contract['output_schema']);
        if ($contract['kind'] === 'resources' && $data['memory_used_bytes'] !== null && $data['memory_total_bytes'] !== null && $data['memory_used_bytes'] > $data['memory_total_bytes']) {
            $schema->invalid('memory');
        }
        if ($contract['kind'] === 'resources' && isset($data['memory_percent'], $data['memory_used_bytes'], $data['memory_total_bytes']) && $data['memory_total_bytes'] > 0
            && abs($data['memory_percent'] - round(100 * $data['memory_used_bytes'] / $data['memory_total_bytes'], 2)) > 0.01) {
            $schema->invalid('memory_percent_consistency');
        }
        if ($contract['kind'] === 'route' && ($data['found'] !== ($data['items'] !== []))) {
            $schema->invalid('route_consistency');
        }

        if ($contract['kind'] === 'route') {
            foreach ($data['items'] as $route) {
                [$prefix,$bits] = explode('/', $route['prefix']);
                $network = inet_pton($prefix);
                $target = inet_pton($data['destination']);
                if (strlen($network) !== strlen($target)) {
                    $schema->invalid('route_family');
                }
                for ($i = 0; $i < strlen($network); $i++) {
                    $mask = max(0, min(8, (int) $bits - $i * 8));
                    if ($mask && (ord($network[$i]) >> (8 - $mask)) !== (ord($target[$i]) >> (8 - $mask))) {
                        $schema->invalid('route_prefix');
                    }
                }
            }
        }
        if (in_array($contract['kind'], ['bgp', 'interfaces', 'counters'], true)) {
            $identifiers = array_map(fn ($row) => $row[$contract['kind'] === 'bgp' ? 'peer' : 'interface'], $data['items']);
            if (count(array_unique($identifiers)) !== count($identifiers)) {
                $schema->invalid('duplicate_row');
            }
        }

        return ['schema_version' => 1, 'mode' => 'SIMULATED', 'environment' => 'LAB', 'identity' => $id, 'contract_id' => $contract['id'], 'status' => $r['status'], 'complete' => $r['status'] === 'OK', 'data' => $data];
    }
}
