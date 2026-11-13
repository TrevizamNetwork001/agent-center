<?php

namespace App\Noc\ReadOnlyLab;

use App\Llm\SecretVault;
use App\Models\DeviceLabAccess;
use App\Models\NocDevice;
use App\Models\User;
use App\Noc\Analysis\Contracts;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use phpseclib3\Crypt\PublicKeyLoader;

/** Lab provisioning only. No HTTP route accepts credentials or trust-on-first-use. */
class Access
{
    public function enabled(): bool
    {
        return app(Contracts::class)->labEnabled() && config('device_readonly_lab.enabled') === true
            && in_array(app(TenantContext::class)->tenantId, config('device_readonly_lab.tenants', []), true);
    }

    public function approve(User $actor, NocDevice $device, string $approvedPublicKey, #[\SensitiveParameter] string $privateKey): DeviceLabAccess
    {
        Gate::forUser($actor)->authorize('update', $device);
        abort_unless($this->enabled() && $device->environment === 'lab' && $device->admin_status === 'active', 403);
        // Both keys must be explicitly supplied by the isolated lab provisioning process.
        try {
            PublicKeyLoader::loadPublicKey($approvedPublicKey);
            PublicKeyLoader::loadPrivateKey($privateKey);
        } catch (\Throwable) {
            throw new Failure('INVALID_LAB_KEY');
        }

        return DB::transaction(function () use ($actor, $device, $approvedPublicKey, $privateKey) {
            $d = NocDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();
            abort_unless($d->environment === 'lab' && $d->admin_status === 'active' && app(Contracts::class)->platform($d) !== null, 403);
            $row = DeviceLabAccess::where('device_id', $d->id)->lockForUpdate()->first() ?? new DeviceLabAccess;
            $row->forceFill(['id' => $row->id ?? (string) Str::uuid(), 'tenant_id' => $d->tenant_id]);
            $cipher = app(SecretVault::class)->encrypt($privateKey, $row->id, $d->tenant_id);
            $row->forceFill([...$cipher, 'device_id' => $d->id, 'device_revision' => $d->revision, 'revision' => ($row->revision ?? 0) + 1,
                'state' => 'approved_lab', 'host_public_key' => $approvedPublicKey, 'expires_at' => now()->addHour(), 'updated_by' => $actor->id])->save();
            app(Audit::class)->record('noc.lab_access_rotated', $actor, $d->tenant_id, 'noc_device', metadata: ['device_id' => $d->id, 'revision' => $row->revision]);

            return $row;
        });
    }

    public function revoke(User $actor, NocDevice $device): void
    {
        Gate::forUser($actor)->authorize('update', $device);
        abort_unless($this->enabled(), 403);
        DB::transaction(function () use ($actor, $device) {
            NocDevice::whereKey($device->id)->lockForUpdate()->firstOrFail();
            $row = DeviceLabAccess::where('device_id', $device->id)->lockForUpdate()->firstOrFail();
            $row->forceFill(['state' => 'revoked', 'revision' => $row->revision + 1, 'updated_by' => $actor->id])->save();
            app(Audit::class)->record('noc.lab_access_revoked', $actor, $device->tenant_id, 'noc_device', metadata: ['device_id' => $device->id]);
        });
    }

    public function preflight(NocDevice $device, Operation $operation, array $parameters): DeviceLabAccess
    {
        abort_unless($this->enabled() && $device->environment === 'lab' && $device->admin_status === 'active', 403);
        $c = app(Contracts::class)->require($device, $operation->value);
        app(Contracts::class)->parameters($c, $parameters);
        Platform::from($c['specialist'])->command($operation, $parameters);
        $row = DeviceLabAccess::where('device_id', $device->id)->lockForUpdate()->first();
        if (! $row || $row->state !== 'approved_lab' || $row->device_revision !== $device->revision || $row->expires_at->isPast()) {
            throw new Failure('LAB_ACCESS_NOT_APPROVED');
        }

        return $row;
    }
}
