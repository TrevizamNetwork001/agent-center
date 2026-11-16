<?php

namespace App\Agent;

use App\Models\Agent;
use App\Models\AgentOperation;
use App\Models\AgentRelease;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Support\Str;

/** Signed dependency planning within the existing Lifecycle operation protocol. */
class ReleaseTransitions
{
    public static function requiredProtocol(array $release): int
    {
        return (int) ($release['runtime_package_protocol'] ?? 1);
    }

    public function expireUnsupportedTransition(Agent $agent, AgentOperation $operation): void
    {
        if (isset($operation->evidence['transition_parent_id']) && $operation->status === 'PRECHECK_REQUESTED' && $operation->created_at->lt(now()->subMinutes(5))) {
            $operation->forceFill(['status' => 'FAILED', 'error' => 'incompatible', 'finished_at' => now()])->save();
            app(Audit::class)->record('agent.update_failed', null, $agent->tenant_id, 'agent', null, ['agent_id' => $agent->id, 'operation_id' => $operation->id]);
        }
    }

    public function plan(Agent $agent, array $target): array
    {
        $supported = (int) ($agent->metadata['updater']['runtime_package_protocol'] ?? 1);
        if (self::requiredProtocol($target) <= $supported) {
            return [$target, null];
        }
        abort_unless(in_array(self::requiredProtocol($target), [2, 3], true) && isset($target['id'], $target['lifecycle'], $target['bootstrap_sha256']), 409, 'Transição assinada indisponível.');
        $bridge = AgentRelease::where('sha256', $target['bootstrap_sha256'])->first();
        abort_unless($bridge, 409, 'Publique o pacote de transição assinado antes do pacote final.');
        $release = $bridge->descriptor();
        app(ReleaseQuarantine::class)->assertAllowed($release);
        abort_unless(self::requiredProtocol($release) === 1 && $release['version'] === $target['version'] && $release['platform'] === $target['platform'] && $release['sequence'] < $target['sequence'], 409, 'Transição incompatível.');
        $floor = (int) ($agent->metadata['lifecycle_release_sequence'] ?? 0);
        if ($release['sequence'] <= $floor) {
            // A completed bridge may be waiting for the next companion timer.
            abort_unless(AgentOperation::where('agent_id', $agent->id)->where('release->sha256', $bridge->sha256)->where('status', 'COMPLETED')->exists(), 409, 'Sequência de transição incompatível.');

            return [$target, null];
        }

        return [$release, ['target' => $target]];
    }

    public function completeBridge(Agent $agent, AgentOperation $parent, ?User $approver): void
    {
        $transition = $parent->evidence['transition'] ?? null;
        if (! is_array($transition) || isset($transition['next_operation_id'])) {
            return;
        }
        $target = $transition['target'];
        $blocked = app(ReleaseQuarantine::class)->blocked($target);
        $child = new AgentOperation;
        $authorized = $approver?->status === 'active' && $parent->approved_at?->gte(now()->subMinutes(15)) && $agent->status === 'active' && ! $agent->revoked_at;
        $child->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $agent->tenant_id, 'agent_id' => $agent->id,
            'requested_by' => $parent->requested_by, 'kind' => 'update', 'status' => $blocked ? 'FAILED' : 'PRECHECK_REQUESTED',
            'from_version' => $parent->release['version'], 'release' => $target, 'reason' => $parent->reason,
            'approved_by' => $authorized && ! $blocked ? $approver->id : null,
            'approved_at' => $authorized && ! $blocked ? $parent->approved_at : null,
            'evidence' => ['transition_parent_id' => $parent->id],
            'error' => $blocked ? 'invalid_release' : null, 'finished_at' => $blocked ? now() : null])->save();
        $parent->forceFill(['evidence' => [...$parent->evidence, 'transition' => [...$transition, 'next_operation_id' => $child->id]]])->save();
        app(Audit::class)->record('agent.update_requested', $authorized ? $approver : null, $agent->tenant_id, 'agent', null, ['agent_id' => $agent->id, 'operation_id' => $child->id]);
        if ($authorized && ! $blocked) {
            app(Audit::class)->record('agent.update_authorized', $approver, $agent->tenant_id, 'agent', null, ['agent_id' => $agent->id, 'operation_id' => $child->id, 'version' => $target['version'], 'sha256' => $target['sha256']]);
        }
    }
}
