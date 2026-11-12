<?php

namespace App\Noc\Analysis;

use App\Models\NocDevice;
use App\Noc\Catalog;
use Illuminate\Validation\ValidationException;

/** Pure fixture extension of the existing NOC simulator. No transports or operational tasks. */
class Simulation
{
    public function fixtures(?NocDevice $d = null): array
    {
        $all = json_decode(file_get_contents(resource_path('device-analysis-fixtures.json')), true, flags: JSON_THROW_ON_ERROR);
        if (! $d) {
            return $all;
        }$platform = app(Contracts::class)->platform($d);

        return array_filter($all, fn ($f) => $f['specialist'] === $platform);
    }

    public function result(NocDevice $d, string $key, array $parameters, SimulationScope $scope = SimulationScope::LaboratoryConversation): array
    {
        abort_unless($scope->enabled(), 403, 'Device Analysis SIMULATED / LAB não habilitado neste ambiente/tenant.');
        $fixture = $this->fixtures()[$key] ?? null;
        if (! $fixture) {
            throw ValidationException::withMessages(['fixture' => 'Cenário especializado inválido.']);
        }
        $contract = app(Contracts::class)->require($d, $fixture['contract_id']);
        if ($fixture['specialist'] !== $contract['specialist']) {
            throw ValidationException::withMessages(['fixture' => 'Cenário de outra plataforma.']);
        }
        $params = app(Contracts::class)->parameters($contract, $parameters);
        $raw = $fixture['raw'];
        $raw['identity']['model'] = $d->model;
        $raw['identity']['os_version'] = preg_replace('/^RouterOS | \(stable\)$/i', '', $d->os_version);
        $e = app(Evidence::class)->parse($d, $contract, json_encode($raw, JSON_THROW_ON_ERROR));
        $data = $e['data'];
        // Reuse the original NOC fixtures where the evidence is equivalent, without another persistence engine.
        $legacy = $fixture['reuses_fixture'] ? app(Catalog::class)->fixtures()[$fixture['reuses_fixture']] : null;
        if ($legacy && $contract['kind'] === 'bgp' && $fixture['reuses_fixture'] !== 'insufficient-data') {
            $index = $fixture['reuses_fixture'] === 'bgp-down' ? count($data['items']) - 1 : 0;
            $data['items'][$index]['state'] = $legacy['evidence']['state'];
            $data['items'][$index]['uptime_seconds'] = $legacy['evidence']['uptime_seconds'];
        }
        if ($legacy && $contract['kind'] === 'counters') {
            $data['items'][0]['crc_errors'] = $legacy['evidence']['crc_delta'];
            $data['items'][0]['sample_seconds'] = $legacy['evidence']['window_seconds'];
        }
        if ($contract['kind'] === 'route') {
            if ($params['destination'] !== $data['destination'] || isset($params['table']) && $params['table'] !== $data['table']) {
                throw ValidationException::withMessages(['parameters' => 'Este cenário representa somente o destino/tabela explícitos da fixture, sem consulta real.']);
            }
        }
        foreach (['peer' => 'peer', 'interface' => 'interface'] as $param => $field) {
            if (isset($params[$param]) && isset($data['items'])) {
                $data['items'] = array_values(array_filter($data['items'], fn ($row) => ($row[$field] ?? null) === $params[$param]));
            }
        }
        if (isset($data['items']) && $data['items'] === [] && $contract['kind'] !== 'route') {
            $e['complete'] = false;
            $e['status'] = 'PARTIAL';
        }
        if ($contract['kind'] === 'resources') {
            foreach (['cpu_reference_percent' => 'cpu_percent', 'memory_reference_percent' => 'memory_percent'] as $p => $field) {
                if (isset($params[$p])) {
                    $data['references'][$field] = $params[$p];
                    $data['references']['source'] = 'LAB_OPERATOR';
                }
            }
        }
        app(Schema::class)->check($data, $contract['output_schema']);
        $e['data'] = $data;
        $interpreted = app(Interpretation::class)->assess($contract, $e);

        return ['id' => $key, 'label' => $fixture['label'], 'capability_id' => $contract['capability_id'], 'contract_id' => $contract['id'], 'schema_version' => 1, 'simulation_scope' => $scope->value, 'display_mode' => 'SIMULADO', 'real_evidence' => false, 'task_eligible' => false, 'health_eligible' => false, 'mode' => 'SIMULATED', 'environment' => 'LAB', 'simulation' => true, 'source' => 'FIXTURE', 'operational_enabled' => false, 'specialist' => $contract['specialist'], 'device_id' => $d->id, 'device_revision' => $d->revision, 'parameters' => $params, 'generated_at' => now()->toIso8601String(), 'observed_at' => null, 'evidence' => $e, 'reuses_fixture' => $fixture['reuses_fixture'], ...$interpreted];
    }
}
