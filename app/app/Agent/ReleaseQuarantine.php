<?php

namespace App\Agent;

use App\Models\Agent;
use App\Models\AgentOperation;
use App\Models\AgentRelease;
use App\Models\AgentReleaseQuarantine;
use App\Models\User;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ReleaseQuarantine
{
    public function lock(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(190621, ?::integer)', [app(TenantContext::class)->tenantId]);
    }

    public function blocked(array $release): bool
    {
        return AgentReleaseQuarantine::where('sha256', $release['sha256'] ?? '')->exists();
    }

    public function assertAllowed(array $release): void
    {
        abort_if($this->blocked($release), 409, 'Release em quarentena.');
    }

    public function apply(AgentRelease $release, User $actor): AgentReleaseQuarantine
    {
        Gate::forUser($actor)->authorize('create', Agent::class);
        abort_unless(app(TenantContext::class)->tenantId === $release->tenant_id, 404);

        return DB::transaction(function () use ($release, $actor) {
            $this->lock();
            $release = AgentRelease::whereKey($release->id)->sole();
            $existing = AgentReleaseQuarantine::where('release_id', $release->id)->first();
            if ($existing) {
                return $existing;
            }
            abort_if(AgentOperation::where('release->sha256', $release->sha256)->whereNotIn('status', ['COMPLETED', 'FAILED'])->exists(), 409, 'Release com operação em andamento.');
            $record = new AgentReleaseQuarantine;
            $record->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $release->tenant_id,
                'release_id' => $release->id, 'sha256' => $release->sha256,
                'quarantined_by' => $actor->id, 'reason' => 'controlled_failure_test', 'quarantined_at' => now()])->save();
            app(Audit::class)->record('agent.release_quarantined', $actor, $release->tenant_id, 'agent_release', null, ['release_id' => $release->id]);

            return $record;
        });
    }
}
