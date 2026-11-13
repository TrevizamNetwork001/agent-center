<?php

namespace App\Noc;

use App\Diagnostics\Digest;
use App\Http\Requests\NocDeviceRequest;
use App\Models\NocDevice;
use App\Models\NocDeviceRevision;
use App\Models\NocSimulation;
use App\Models\User;
use App\Noc\Analysis\SimulationScope;
use App\Noc\ReadOnlyLab\Failure;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Inventory
{
    public function save(User $actor, array $data, ?NocDevice $device = null, ?int $expected = null): NocDevice
    {
        Gate::forUser($actor)->authorize($device ? 'update' : 'create', $device ?? NocDevice::class);
        try {
            return DB::transaction(function () use ($actor, $data, $device, $expected) {
                $d = $device ? NocDevice::whereKey($device->id)->lockForUpdate()->firstOrFail() : new NocDevice;
                abort_if($device && $d->revision !== $expected, 409, 'Cadastro alterado por outra pessoa. Recarregue antes de salvar.');
                $before = $d->only(NocDeviceRequest::FIELDS);
                $values = array_intersect_key($data, array_flip(NocDeviceRequest::FIELDS));
                // Controller input has already passed the strict FormRequest. No unrestricted fill or remote access.
                if (! $device) {
                    $d->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => app(TenantContext::class)->tenantId, 'created_by' => $actor->id, 'revision' => 0]);
                }
                $d->forceFill([...$values, 'updated_by' => $actor->id]);
                if ($device && ! $d->isDirty(NocDeviceRequest::FIELDS)) {
                    return $d;
                }
                $d->revision++;
                $d->save();
                $action = ! $device ? 'created' : ($d->admin_status === 'disabled' && ($before['admin_status'] ?? null) !== 'disabled' ? 'deactivated' : 'updated');
                (new NocDeviceRevision)->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $d->tenant_id, 'device_id' => $d->id, 'revision' => $d->revision, 'actor_id' => $actor->id, 'action' => $action, 'snapshot' => $d->only(NocDeviceRequest::FIELDS), 'created_at' => now()])->save();
                app(Audit::class)->record('noc.device_'.$action, $actor, $d->tenant_id, 'noc_device', metadata: ['device_id' => $d->id]);

                return $d;
            });
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23505') {
                throw ValidationException::withMessages(['inventory' => 'Já existe equipamento com este nome, hostname ou IP neste tenant, inclusive desativado.']);
            }throw $e;
        }
    }

    public function simulate(User $actor, NocDevice $device, string $fixture, string $key, array $parameters = [], ?int $expectedRevision = null, SimulationScope $scope = SimulationScope::LaboratoryConversation): NocSimulation
    {
        Gate::forUser($actor)->authorize($scope === SimulationScope::AdministrativeDemo ? 'demonstrate' : 'simulate', $device);
        if ($scope === SimulationScope::AdministrativeDemo) {
            abort_unless(str_starts_with($fixture, 'da-'), 422);
        }
        if ((str_starts_with($fixture, 'da-') || str_starts_with($fixture, 'ssh-'))) {
            abort_unless($scope->enabled(), 403);
        }

        try {
            return DB::transaction(function () use ($actor, $device, $fixture, $key, $parameters, $expectedRevision, $scope) {
                if (str_starts_with($fixture, 'ssh-')) {
                    DB::statement("SET LOCAL lock_timeout = '2s'");
                }
                DB::select('SELECT pg_advisory_xact_lock(7830, ?::integer)', [$actor->id]);
                $d = NocDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();
                $old = NocSimulation::where('actor_id', $actor->id)->where('request_key', $key)->first();
                if ($old) {
                    abort_unless(($old->result['simulation_scope'] ?? SimulationScope::LaboratoryConversation->value) === $scope->value && $old->device_id === $d->id && $old->fixture === $fixture && Digest::hash($old->result['parameters'] ?? []) === Digest::hash($parameters) && ($expectedRevision === null || $old->result['device_revision'] === $expectedRevision), 409);

                    return $old;
                }
                if ((str_starts_with($fixture, 'da-') || str_starts_with($fixture, 'ssh-'))) {
                    abort_unless($expectedRevision !== null && $expectedRevision === $d->revision, 409, 'Revisão do equipamento mudou; reabra o cenário.');
                }
                $result = app(Catalog::class)->fixtureFor($d, $fixture, $parameters, $scope);
                $row = (new NocSimulation)->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $d->tenant_id, 'device_id' => $d->id, 'actor_id' => $actor->id, 'request_key' => $key, 'fixture' => $fixture, 'result' => $result, 'is_simulated' => true, 'created_at' => now()]);
                $row->save();
                app(Audit::class)->record('noc.simulation_created', $actor, $d->tenant_id, 'noc_simulation', metadata: ['device_id' => $d->id, 'simulation_scope' => $scope->value, 'contract_id' => $result['contract_id'] ?? null, 'parameters_digest' => Digest::hash($parameters), 'device_revision' => $d->revision, 'access_revision' => $result['access_revision'] ?? null]);

                return $row;
            });
        } catch (QueryException $e) {
            if (str_starts_with($fixture, 'ssh-') && ($e->errorInfo[0] ?? null) === '55P03') {
                app(Audit::class)->record('noc.lab_query_failed', $actor, $device->tenant_id, 'noc_device', metadata: ['device_id' => $device->id, 'reason' => 'LAB_BUSY']);
                throw new Failure('LAB_BUSY');
            }
            throw $e;
        } catch (Failure $e) {
            app(Audit::class)->record('noc.lab_query_failed', $actor, $device->tenant_id, 'noc_device', metadata: ['device_id' => $device->id, 'reason' => $e->reason, 'contract_id' => substr($fixture, str_starts_with($fixture, 'ssh-ros7-') ? 9 : 4), 'parameters_digest' => Digest::hash($parameters), 'device_revision' => $device->revision]);
            throw $e;
        }
    }
}
