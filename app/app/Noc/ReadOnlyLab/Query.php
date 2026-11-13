<?php

namespace App\Noc\ReadOnlyLab;

use App\Llm\SecretVault;
use App\Models\NocDevice;
use App\Models\User;
use App\Noc\Analysis\Contracts;
use App\Noc\Analysis\Interpretation;
use App\Noc\Analysis\SimulationScope;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Called only inside the existing inventory simulation transaction and authorization boundary. */
class Query
{
    public function result(NocDevice $device, string $key, array $parameters, SimulationScope $scope): array
    {
        abort_unless($scope === SimulationScope::LaboratoryConversation && app(Access::class)->enabled(), 403);
        $actor = User::withoutGlobalScopes()->findOrFail(app(TenantContext::class)->userId);
        Gate::forUser($actor)->authorize('simulate', $device);
        $operation = Operation::tryFrom(substr($key, 4));
        abort_unless(str_starts_with($key, 'ssh-') && $operation !== null, 422);
        $c = app(Contracts::class)->require($device, $operation->value);
        $params = app(Contracts::class)->parameters($c, $parameters);
        $access = app(Access::class)->preflight($device, $operation, $params);
        $secret = app(SecretVault::class)->decrypt($access);
        if ($secret === null) {
            throw new Failure('LAB_CREDENTIAL_UNAVAILABLE');
        }
        try {
            $wire = app(Ssh::class)->collect(Platform::from($c['specialist']), $operation, $params, $access->host_public_key, $secret, $device);
            $e = app(Parser::class)->parse($device, $operation, $wire);
        } catch (ValidationException) {
            throw new Failure('INVALID_EVIDENCE');
        } finally {
            unset($secret);
        }
        foreach (['peer' => 'peer', 'interface' => 'interface'] as $param => $field) {
            if (isset($params[$param])) {
                $e['data']['items'] = array_values(array_filter($e['data']['items'], fn ($row) => $row[$field] === $params[$param]));
                if ($e['data']['items'] === []) {
                    $e['complete'] = false;
                    $e['status'] = 'PARTIAL';
                }
            }
        }

        if ($operation === Operation::Resources) {
            foreach (['cpu_reference_percent' => 'cpu_percent', 'memory_reference_percent' => 'memory_percent'] as $p => $field) {
                if (isset($params[$p])) {
                    $e['data']['references'][$field] = $params[$p];
                    $e['data']['references']['source'] = 'LAB_OPERATOR';
                }
            }
        }

        return ['id' => $key, 'label' => 'SSH de laboratório · '.$operation->value, 'capability_id' => $c['capability_id'], 'contract_id' => $operation->value,
            'schema_version' => 1, 'simulation_scope' => $scope->value, 'display_mode' => 'SIMULADO', 'real_evidence' => false, 'task_eligible' => false, 'health_eligible' => false,
            'mode' => 'SIMULATED', 'environment' => 'LAB', 'simulation' => true, 'source' => 'FIXTURE', 'transport' => 'SSH_LOOPBACK_LAB', 'operational_enabled' => false,
            'specialist' => $c['specialist'], 'device_id' => $device->id, 'device_revision' => $device->revision, 'access_revision' => $access->revision,
            'parameters' => $params, 'generated_at' => now()->toIso8601String(), 'observed_at' => null, 'evidence' => $e, 'reuses_fixture' => null,
            ...app(Interpretation::class)->assess($c, $e)];
    }
}
