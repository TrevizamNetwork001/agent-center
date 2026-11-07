<?php

namespace App\Agent;

use App\Models\Agent;
use App\Models\AgentEnrollment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Unassigned requests belong exclusively to platform administration, never to a tenant. */
class Bootstrap
{
    private function key(string $id): string
    {
        return 'agent-bootstrap:request:'.$id;
    }

    public function request(array $data, ?string $ip): array
    {
        return Cache::lock('agent-bootstrap:index-lock', 10)->block(5, function () use ($data, $ip) {
            $index = array_filter(Cache::get('agent-bootstrap:index', []), fn ($expiry) => $expiry > time());
            abort_if(count($index) >= 1000, 429);
            $id = (string) Str::uuid();
            $receipt = $id.'.'.bin2hex(random_bytes(32));
            $item = ['id' => $id, 'hostname' => $data['hostname'], 'version' => Protocol::INSTALLER_VERSION, 'fingerprint' => $data['fingerprint'], 'ip' => $ip, 'state' => 'pending', 'receipt_hash' => hash('sha256', $receipt), 'created_at' => now()->toIso8601String(), 'expires_at' => time() + 1800];
            Cache::put($this->key($id), $item, 1800);
            $index[$id] = $item['expires_at'];
            Cache::put('agent-bootstrap:index', $index, 1800);

            return ['state' => 'AWAITING_APPROVAL', 'receipt' => $receipt, 'request_id' => $id];
        });
    }

    public function pending(): array
    {
        $rows = [];
        foreach (Cache::get('agent-bootstrap:index', []) as $id => $expiry) {
            $item = $expiry > time() ? Cache::get($this->key($id)) : null;
            if ($item && $item['state'] === 'pending') {
                unset($item['receipt_hash']);
                $rows[] = $item;
            }
        }

        return $rows;
    }

    public function status(string $receipt): array
    {
        [$id] = explode('.', $receipt);
        $item = Cache::get($this->key($id));
        abort_unless($item && hash_equals($item['receipt_hash'], hash('sha256', $receipt)), 401);
        abort_if($item['expires_at'] <= time(), 410);
        if ($item['state'] !== 'approved') {
            return ['state' => 'AWAITING_APPROVAL'];
        }

        return ['state' => 'APPROVED', 'code' => Crypt::decryptString($item['code']), 'request_id' => $id];
    }

    public function accept(string $id, int $tenantId, string $name, User $actor): void
    {
        Cache::lock('agent-bootstrap:accept:'.$id, 30)->block(5, function () use ($id, $tenantId, $name, $actor) {
            $item = Cache::get($this->key($id));
            abort_unless($item && $item['expires_at'] > time(), 410);
            abort_unless($item['state'] === 'pending', 409);
            $context = app(TenantContext::class);
            $previousTenant = $context->tenantId;
            try {
                $context->forUser($actor, $tenantId);
                $code = DB::transaction(function () use ($item, $tenantId, $name, $actor) {
                    abort_unless(Tenant::whereKey($tenantId)->where('status', 'active')->exists(), 422);
                    $id = $item['id'];
                    $code = $id.'.'.bin2hex(random_bytes(32));
                    $agent = new Agent;
                    $agent->forceFill(['id' => $item['id'], 'tenant_id' => $tenantId, 'name' => $name, 'hostname' => $item['hostname'], 'version' => Protocol::INSTALLER_VERSION, 'status' => 'registered', 'token_hash' => hash('sha256', random_bytes(32)), 'enrolled_at' => null, 'capabilities' => [], 'metadata' => ['onboarding' => ['registered' => now()->toIso8601String(), 'identity_verified' => now()->toIso8601String(), 'approved' => now()->toIso8601String()]]])->save();
                    $enrollment = new AgentEnrollment;
                    $enrollment->forceFill(['id' => $id, 'agent_id' => $agent->id, 'tenant_id' => $tenantId, 'created_by' => $actor->id, 'name' => $name, 'secret_hash' => hash('sha256', $code), 'expires_at' => now()->addMinutes(15), 'approved_at' => now(), 'approved_by' => $actor->id])->save();
                    foreach (['agent.created', 'agent.enrollment_created', 'agent.enrollment_approved'] as $event) {
                        app(Audit::class)->record($event, $actor, $tenantId, 'agent', null, ['agent_id' => $agent->id, 'enrollment_id' => $enrollment->id]);
                    }

                    return $code;
                });
                $item['state'] = 'approved';
                $item['code'] = Crypt::encryptString($code);
                $item['expires_at'] = time() + 900;
                Cache::put($this->key($id), $item, 900);
            } finally {
                $context->forUser($actor, $previousTenant);
            }
        });
    }
}
