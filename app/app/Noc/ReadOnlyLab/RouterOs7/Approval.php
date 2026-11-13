<?php

namespace App\Noc\ReadOnlyLab\RouterOs7;

use App\Models\Agent;
use App\Models\DeviceLabAccess;
use App\Models\NocDevice;
use App\Models\User;
use App\Noc\Analysis\Contracts;
use App\Noc\ReadOnlyLab\Access;
use App\Noc\ReadOnlyLab\Failure;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Local simulator attestation only. Never authorizes an operational task or real destination. */
final class Approval
{
    public function attest(User $actor, NocDevice $device, Agent $agent, Operation $operation, array $params, array $effectiveRights, string $independentFingerprint): void
    {
        Gate::forUser($actor)->authorize('update', $device);
        if (config('device_readonly_lab.routeros_protocol') !== true || app(Contracts::class)->platform($device) !== 'ccr-routeros7' || preg_replace('/^RouterOS | \(stable\)$/i', '', $device->os_version) !== Protocol::VERSION) {
            throw new Failure('ROUTEROS_LAB_DISABLED');
        }
        $params = app(Protocol::class)->parameters($operation, $params);
        sort($effectiveRights);
        if ($effectiveRights !== ['read', 'ssh']) {
            throw new Failure('EXCESSIVE_OR_UNKNOWN_RIGHTS');
        }
        DB::transaction(function () use ($actor, $device, $agent, $operation, $params, $independentFingerprint) {
            $device = NocDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();
            $row = app(Access::class)->preflight($device, \App\Noc\ReadOnlyLab\Operation::Resources, []);
            $this->agent($agent->id, $device);
            if (! hash_equals($this->fingerprint($row->host_public_key), $independentFingerprint)) {
                throw new Failure('HOST_IDENTITY_REJECTED');
            }
            $row->forceFill(['protocol_approval' => ['schema_version' => 1, 'mode' => 'SIMULATED', 'actor_id' => $actor->id,
                'agent_id' => $agent->id, 'operation' => $operation->value, 'parameters_digest' => $this->digest($params),
                'policy_revision' => 1, 'device_revision' => $device->revision, 'access_revision' => $row->revision,
                'host_fingerprint' => $independentFingerprint, 'effective_rights' => ['read', 'ssh'],
                'approved_at' => now()->toIso8601String(), 'expires_at' => now()->addMinutes(15)->toIso8601String(),
                'management_ip' => $device->management_ip, 'ssh_port' => 22, 'executor' => 'CENTRAL_LOOPBACK_SIMULATOR_ONLY']])->save();
            app(Audit::class)->record('noc.routeros_lab_attested', $actor, $device->tenant_id, 'noc_device', metadata: ['device_id' => $device->id, 'contract_id' => $operation->value, 'agent_id' => $agent->id, 'device_revision' => $device->revision, 'parameters_digest' => $this->digest($params), 'access_revision' => $row->revision]);
        });
    }

    public function check(User $actor, NocDevice $device, Operation $operation, array $params): DeviceLabAccess
    {
        Gate::forUser($actor)->authorize('simulate', $device);
        if (! app(Access::class)->enabled() || config('device_readonly_lab.routeros_protocol') !== true || app(Contracts::class)->platform($device) !== 'ccr-routeros7') {
            throw new Failure('ROUTEROS_LAB_DISABLED');
        }
        $row = app(Access::class)->preflight($device, \App\Noc\ReadOnlyLab\Operation::Resources, []);
        $a = $row->protocol_approval;
        if (! is_array($a) || ($a['schema_version'] ?? null) !== 1 || ($a['mode'] ?? '') !== 'SIMULATED'
            || ($a['actor_id'] ?? null) !== $actor->id || ($a['policy_revision'] ?? null) !== 1
            || ($a['device_revision'] ?? null) !== $device->revision || ($a['access_revision'] ?? null) !== $row->revision
            || ($a['operation'] ?? '') !== $operation->value || ($a['parameters_digest'] ?? '') !== $this->digest($params)
            || ($a['effective_rights'] ?? []) !== ['read', 'ssh'] || ($a['host_fingerprint'] ?? '') !== $this->fingerprint($row->host_public_key)
            || ($a['management_ip'] ?? '') !== $device->management_ip || ($a['ssh_port'] ?? null) !== 22
            || ($a['executor'] ?? '') !== 'CENTRAL_LOOPBACK_SIMULATOR_ONLY'
            || ! is_string($a['agent_id'] ?? null) || ! $this->window($a)) {
            throw new Failure('ROUTEROS_APPROVAL_UNKNOWN_DENIED_OR_STALE');
        }
        $this->agent($a['agent_id'] ?? '', $device);

        return $row;
    }

    private function window(array $approval): bool
    {
        $start = is_string($approval['approved_at'] ?? null) ? strtotime($approval['approved_at']) : false;
        $end = is_string($approval['expires_at'] ?? null) ? strtotime($approval['expires_at']) : false;

        return $start !== false && $end !== false && $start <= time() && $end > time() && $end > $start && $end - $start <= 900;
    }

    private function agent(string $id, NocDevice $device): void
    {
        $a = Agent::whereKey($id)->first();
        if (! $a || $a->tenant_id !== $device->tenant_id || $a->tenant_id !== app(TenantContext::class)->tenantId || $a->revoked_at !== null || $a->status !== 'active') {
            throw new Failure('AGENT_NOT_AUTHORIZED');
        }
    }

    public function fingerprint(string $key): string
    {
        $fields = preg_split('/\s+/', trim($key));
        $blob = base64_decode($fields[1] ?? '', true);
        if ($blob === false || $blob === '') {
            throw new Failure('HOST_IDENTITY_REJECTED');
        }

        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    private function digest(array $params): string
    {
        ksort($params);

        return hash('sha256', json_encode($params, JSON_THROW_ON_ERROR));
    }
}
