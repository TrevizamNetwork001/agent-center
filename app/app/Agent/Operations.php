<?php

namespace App\Agent;

use App\Models\Agent;
use App\Models\AgentOperation;
use App\Models\AgentTask;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;

class Operations
{
    public const ERRORS = ['precheck_failed', 'release_not_staged', 'sha256_mismatch', 'incompatible', 'update_failed', 'rollback_failed', 'interrupted', 'postcheck_failed', 'backup_failed', 'busy'];

    public function claim(Agent $agent): ?array
    {
        return DB::transaction(function () use ($agent) {
            $agent = Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            if ($agent->status !== 'active' || $agent->revoked_at) {
                return null;
            }
            $agent->forceFill(['metadata' => [...($agent->metadata ?? []), 'updater' => ['protocol' => 1, 'last_poll_at' => now()->toIso8601String()]]])->save();
            $o = AgentOperation::where('agent_id', $agent->id)->whereIn('status', ['PRECHECK_REQUESTED', 'APPROVED'])->orderBy('created_at')->lockForUpdate()->first();
            if (! $o) {
                return null;
            }
            // Updates do not race active diagnostic attempts. No cancellation or lease bypass.
            if (AgentTask::where('agent_id', $agent->id)->whereIn('status', ['CLAIMED', 'RUNNING'])->exists()) {
                return null;
            }
            $precheck = $o->status === 'PRECHECK_REQUESTED';
            if (! $precheck && ($o->approved_at->lt(now()->subMinutes(15)) || $agent->version !== $o->from_version)) {
                $o->forceFill(['status' => 'FAILED', 'error' => 'incompatible', 'finished_at' => now()])->save();
                app(Audit::class)->record('agent.'.$o->kind.'_failed', null, $agent->tenant_id, 'agent', null, ['agent_id' => $agent->id, 'operation_id' => $o->id]);

                return null;
            }
            $o->forceFill(['status' => $precheck ? 'PRECHECKING' : 'RUNNING', 'claimed_at' => now()])->save();
            if (! $precheck && $o->kind === 'update') {
                app(Audit::class)->record('agent.update_started', null, $agent->tenant_id, 'agent', null, ['agent_id' => $agent->id, 'operation_id' => $o->id]);
            }

            return ['id' => $o->id, 'kind' => $o->kind, 'phase' => $precheck ? 'precheck' : 'apply', 'release' => $o->release, 'from_version' => $o->from_version];
        });
    }

    public function report(Agent $agent, array $data): void
    {
        DB::transaction(function () use ($agent, $data) {
            $o = AgentOperation::where('agent_id', $agent->id)->whereKey($data['operation_id'])->lockForUpdate()->firstOrFail();
            if (in_array($o->status, ['COMPLETED', 'FAILED', 'UNCERTAIN'], true)) {
                abort_unless($o->status === $data['status'], 409);

                return;
            }
            abort_unless(($o->status === 'PRECHECKING' && in_array($data['status'], ['PLANNED', 'FAILED', 'UNCERTAIN'], true)) || ($o->status === 'RUNNING' && in_array($data['status'], ['COMPLETED', 'FAILED', 'UNCERTAIN'], true)), 409);
            if (in_array($data['status'], ['PLANNED', 'COMPLETED'], true)) {
                abort_unless(($data['evidence']['sha256'] ?? null) === $o->release['sha256'] && ($data['evidence']['version'] ?? null) === $o->release['version'] && ($data['evidence']['freeze_preserved'] ?? false) === true, 422);
                if ($data['status'] === 'COMPLETED') {
                    abort_unless(isset($data['evidence']['backup']), 422);
                }
            }
            $terminal = $data['status'] !== 'PLANNED';
            $o->forceFill(['status' => $data['status'], 'error' => $data['error'] ?? null, 'evidence' => $data['evidence'] ?? null, 'finished_at' => $terminal ? now() : null])->save();
            if ($terminal) {
                app(Audit::class)->record('agent.'.$o->kind.'_'.($o->status === 'COMPLETED' ? 'completed' : 'failed'), null, $agent->tenant_id, 'agent', null, ['agent_id' => $agent->id, 'operation_id' => $o->id]);
            }
        });
    }
}
