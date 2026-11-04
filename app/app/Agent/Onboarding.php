<?php

namespace App\Agent;

use App\Models\Agent;
use App\Models\AgentEnrollment;
use App\Models\Tenant;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Onboarding
{
    public function request(array $data, ?string $ip): array
    {
        return $this->lookup($data['code'], false, function ($e) use ($data, $ip) {
            abort_if($e->consumed_at, 409);
            abort_if($e->expires_at->isPast(), 410);
            $a = $e->agent_id ? Agent::findOrFail($e->agent_id) : new Agent;
            if (! $a->exists) {
                $a->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $e->tenant_id, 'name' => $e->name, 'enrolled_at' => null, 'token_hash' => hash('sha256', random_bytes(32))]);
            }
            abort_if($a->revoked_at, 403);
            $a->forceFill(['hostname' => $data['hostname'], 'version' => $data['version'], 'capabilities' => $data['capabilities'] ?? [], 'last_seen_ip' => $ip, 'status' => 'pending', 'metadata' => [...($a->metadata ?? []), 'protocol' => 1, 'onboarding' => [...($a->metadata['onboarding'] ?? []), 'installation_started' => now()->toIso8601String(), 'enrollment_requested' => now()->toIso8601String(), 'awaiting_approval' => now()->toIso8601String()], 'fingerprint' => $data['fingerprint'] ?? null]])->save();
            $receipt = $e->id.'.'.bin2hex(random_bytes(32));
            $e->forceFill(['agent_id' => $a->id, 'consumed_at' => now(), 'requested_at' => now(), 'receipt_hash' => hash('sha256', $receipt)])->save();
            app(Audit::class)->record('agent.enrollment_requested', null, $a->tenant_id, 'agent', null, ['agent_id' => $a->id, 'enrollment_id' => $e->id]);

            return ['state' => 'AWAITING_APPROVAL', 'receipt' => $receipt, 'agent_id' => $a->id];
        });
    }

    public function finish(string $receipt): array
    {
        return $this->lookup($receipt, true, function ($e) {
            abort_if(! $e->requested_at || $e->requested_at->lt(now()->subDay()), 410);
            abort_if($e->credential_issued_at, 409);
            $a = Agent::whereKey($e->agent_id)->lockForUpdate()->firstOrFail();
            abort_if($a->revoked_at, 403);
            if (! $e->approved_at) {
                return ['state' => 'AWAITING_APPROVAL'];
            }
            $token = $a->id.'.'.bin2hex(random_bytes(32));
            $a->forceFill(['status' => 'active', 'token_hash' => hash('sha256', $token), 'enrolled_at' => now(), 'metadata' => [...$a->metadata, 'onboarding' => [...$a->metadata['onboarding'], 'credential_issued' => now()->toIso8601String()]]])->save();
            $e->forceFill(['credential_issued_at' => now()])->save();
            app(Audit::class)->record('agent.enrolled', null, $a->tenant_id, 'agent', null, ['agent_id' => $a->id]);

            return ['state' => 'ENROLLED', 'agent_id' => $a->id, 'token' => $token, 'version' => '0.5.2', 'policy' => Protocol::policy($a->id)];
        });
    }

    private function lookup(string $secret, bool $receipt, callable $action): array
    {
        $c = app(TenantContext::class);
        try {
            $c->apply();
            AgentContext::clear();
            [$id] = explode('.', $secret);
            $hash = hash('sha256', $secret);
            DB::select("SELECT set_config('app.enrollment_id',?,false),set_config('app.enrollment_hash',?,false)", [$id, $hash]);

            return DB::transaction(function () use ($id, $hash, $receipt, $action, $c) {
                $e = AgentEnrollment::withoutGlobalScopes()->find($id);
                abort_unless($e && hash_equals(($receipt ? $e->receipt_hash : $e->secret_hash) ?? '', $hash), 401);
                $c->apply(tenantId: $e->tenant_id);
                AgentContext::clear();
                abort_unless(Tenant::whereKey($e->tenant_id)->where('status', 'active')->exists(), 403);

                return $action(AgentEnrollment::whereKey($id)->lockForUpdate()->firstOrFail());
            });
        } finally {
            AgentContext::clear();
            $c->apply();
        }
    }
}
