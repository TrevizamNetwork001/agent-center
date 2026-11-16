<?php

namespace App\Agent;

use App\Models\Agent;
use App\Models\AgentOperation;
use App\Models\AgentTask;
use App\Models\User;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class Operations
{
    public const ERRORS = ['component_environment_unavailable', 'component_install_failed', 'component_files_require_review', 'component_stop_failed', 'component_bootstrap_required', 'component_permission_not_authorized', 'component_dependency_missing', 'component_dependency_incompatible', 'component_busy', 'component_dropins_require_review', 'component_policy_exceeds_base', 'invalid_component_policy', 'empty_component_policy', 'component_hash_mismatch', 'component_requires_lifecycle_service', 'principal_hardening_changed', 'precheck_failed', 'release_download_failed', 'invalid_release', 'release_not_staged', 'sha256_mismatch', 'incompatible', 'update_failed', 'rollback_failed', 'interrupted', 'postcheck_failed', 'backup_failed', 'busy', 'self_check_failed', 'self_check_permission_denied', 'self_check_timeout', 'self_check_exec_failed', 'self_check_privilege_failed', 'self_check_invalid_output', 'service_restart_failed'];

    public function claim(Agent $agent, ?string $updaterVersion = null, ?int $downloadProtocol = null, int $signedProtocol = 0, int $componentProtocol = 0, int $recoveryProtocol = 0, int $runtimePackageProtocol = 1): ?array
    {
        return DB::transaction(function () use ($agent, $updaterVersion, $downloadProtocol, $signedProtocol, $componentProtocol, $recoveryProtocol, $runtimePackageProtocol) {
            app(ReleaseQuarantine::class)->lock();
            $agent = Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            if ($agent->status !== 'active' || $agent->revoked_at) {
                return null;
            }
            $agent->forceFill(['metadata' => [...($agent->metadata ?? []), 'updater' => ['protocol' => 1, 'version' => $updaterVersion, 'download_protocol' => $downloadProtocol, 'signed_release_protocol' => $signedProtocol, 'component_protocol' => $componentProtocol, 'runtime_package_protocol' => $runtimePackageProtocol, 'last_poll_at' => now()->toIso8601String()]]])->save();
            $held = AgentOperation::where('agent_id', $agent->id)->where('status', 'UNCERTAIN')->lockForUpdate()->first();
            if ($held) {
                $request = $held->evidence['reconciliation'] ?? [];
                $approver = isset($request['requested_by']) ? $this->authorizedApprover($agent, $request['requested_by']) : null;
                if ($recoveryProtocol !== 1 || $signedProtocol !== 1 || $componentProtocol !== 1 || ! $approver || $approver->status !== 'active' || ! isset($request['requested_at']) || Carbon::parse($request['requested_at'])->lt(now()->subMinutes(15)) || AgentTask::where('agent_id', $agent->id)->whereIn('status', ['CLAIMED', 'RUNNING'])->exists()) {
                    return null;
                }

                return ['id' => $held->id, 'kind' => $held->kind, 'phase' => 'reconcile', 'release' => $held->release, 'from_version' => $held->from_version];
            }
            $o = AgentOperation::where('agent_id', $agent->id)->whereIn('status', ['PRECHECK_REQUESTED', 'APPROVED'])->orderBy('created_at')->lockForUpdate()->first();
            if (! $o) {
                return null;
            }
            if (app(ReleaseQuarantine::class)->blocked($o->release)) {
                return null;
            }
            if (isset($o->release['lifecycle']) && $signedProtocol !== 1) {
                return null;
            }
            if (ReleaseTransitions::requiredProtocol($o->release) > $runtimePackageProtocol) {
                app(ReleaseTransitions::class)->expireUnsupportedTransition($agent, $o);

                return null;
            }
            if (! empty($o->release['components']) && $componentProtocol !== 1) {
                return null;
            }
            // Updates do not race active diagnostic attempts. No cancellation or lease bypass.
            if (AgentTask::where('agent_id', $agent->id)->whereIn('status', ['CLAIMED', 'RUNNING'])->exists()) {
                return null;
            }
            if (version_compare($o->release['version'], '0.6.4', '>=') && version_compare($updaterVersion ?? '0.0.0', '0.6.4', '<')) {
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
            app(ReleaseQuarantine::class)->lock();
            $agent = Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $o = AgentOperation::where('agent_id', $agent->id)->whereKey($data['operation_id'])->lockForUpdate()->firstOrFail();
            // These keys are server-owned and prohibited in the Agent report schema.
            $reserved = array_intersect_key($o->evidence ?? [], array_flip(['transition', 'transition_parent_id']));
            if ($reserved) {
                $data['evidence'] = [...($data['evidence'] ?? []), ...$reserved];
            }
            if (($data['evidence']['recovery_verified'] ?? false) === true) {
                if ($o->status === 'FAILED' && isset($o->evidence['reconciliation']['verified_at'])) {
                    abort_unless(($o->evidence['reconciliation']['report'] ?? null) == $data, 409);

                    return;
                }
                $request = $o->evidence['reconciliation'] ?? [];
                abort_unless($o->status === 'UNCERTAIN' && isset($o->release['lifecycle'], $request['requested_at'], $request['requested_by']), 409);
                $approver = $this->authorizedApprover($agent, $request['requested_by']);
                abort_unless($approver?->status === 'active' && $agent->status === 'active' && ! $agent->revoked_at && Carbon::parse($request['requested_at'])->gte(now()->subMinutes(15)), 409);
                $e = $data['evidence'];
                abort_unless($data['status'] === 'FAILED' && ($data['error'] ?? null) === 'interrupted' && ($e['backup'] ?? null) === $o->id && ($e['version'] ?? null) === $o->from_version && ($e['sha256'] ?? null) === $o->release['sha256'] && ($e['postcheck'] ?? false) === true && ($e['freeze_preserved'] ?? false) === true, 422);
                $original = ['status' => $o->status, 'error' => $o->error, 'evidence' => $o->evidence, 'finished_at' => $o->finished_at?->toIso8601String()];
                $o->forceFill(['status' => 'FAILED', 'evidence' => ['original_failure' => $original, 'reconciliation' => [...$request, 'verified_at' => now()->toIso8601String(), 'report' => $data]]])->save();
                $audit = app(Audit::class);
                $audit->record('agent.recovery_verified', $approver, $agent->tenant_id, 'agent', null, ['agent_id' => $agent->id, 'operation_id' => $o->id]);

                return;
            }
            if ($o->reason === 'restart' && $data['status'] === 'COMPLETED' && ($data['evidence']['postcheck'] ?? false) !== true) {
                $data['status'] = 'FAILED';
                $data['error'] = 'service_restart_failed';
            }
            if ((in_array($o->status, ['PLANNED', 'COMPLETED', 'FAILED', 'UNCERTAIN'], true) && $o->status === $data['status']) || ($o->status === 'APPROVED' && $data['status'] === 'PLANNED')) {
                abort_unless($o->error === ($data['error'] ?? null) && ($o->evidence ?? null) == ($data['evidence'] ?? null), 409);

                return;
            }
            abort_unless(($o->status === 'PRECHECKING' && in_array($data['status'], ['PLANNED', 'FAILED', 'UNCERTAIN'], true)) || ($o->status === 'RUNNING' && in_array($data['status'], ['COMPLETED', 'FAILED', 'UNCERTAIN'], true)), 409);
            if (in_array($data['status'], ['PLANNED', 'COMPLETED'], true)) {
                app(ReleaseQuarantine::class)->assertAllowed($o->release);
            }
            if (in_array($data['status'], ['PLANNED', 'COMPLETED'], true)) {
                if (! empty($o->release['components']) && $data['status'] === 'COMPLETED') {
                    $components = $data['evidence']['components'] ?? [];
                    abort_unless(count($components) === count($o->release['components']), 422);
                    foreach ($o->release['components'] as $index => $required) {
                        $observed = $components[$index];
                        abort_unless($observed['id'] === $required['id'] && $observed['version'] === $required['version'] && $observed['profile'] === $required['profile'] && $observed['status'] === 'installed', 422);
                        abort_unless($observed['operational_verified'] === true || (! app()->environment('production') && config('agent_releases.allow_component_simulation') === true), 422);
                    }
                    $agent->forceFill(['metadata' => [...($agent->metadata ?? []), 'lifecycle_components' => $components]])->save();
                }
                if (isset($o->release['lifecycle']) && $data['status'] === 'COMPLETED') {
                    abort_unless(($data['evidence']['postcheck'] ?? false) === true, 422);
                }
                abort_unless(($data['evidence']['sha256'] ?? null) === $o->release['sha256'] && ($data['evidence']['version'] ?? null) === $o->release['version'] && ($data['evidence']['freeze_preserved'] ?? false) === true, 422);
                if ($data['status'] === 'COMPLETED') {
                    abort_unless(isset($data['evidence']['backup']), 422);
                }
            }
            if ($data['status'] === 'COMPLETED' && isset($o->release['lifecycle'])) {
                $agent->forceFill(['metadata' => [...($agent->metadata ?? []), 'lifecycle_release_sequence' => $o->release['sequence']]])->save();
            }
            $terminal = $data['status'] !== 'PLANNED';
            $o->forceFill(['status' => $data['status'], 'error' => $data['error'] ?? null, 'evidence' => $data['evidence'] ?? null, 'finished_at' => $terminal ? now() : null])->save();
            if ($data['status'] === 'PLANNED' && $o->kind === 'update' && $o->approved_by) {
                $approver = $this->authorizedApprover($agent, $o->approved_by);
                if ($o->approved_at?->gt(now()->subMinutes(15)) && $agent->status === 'active' && ! $agent->revoked_at && $agent->version === $o->from_version && $approver?->status === 'active') {
                    $o->forceFill(['status' => 'APPROVED'])->save();
                    app(Audit::class)->record('agent.update_approved', $approver, $agent->tenant_id, 'agent', null, ['agent_id' => $agent->id, 'operation_id' => $o->id]);
                } else {
                    $o->forceFill(['approved_by' => null, 'approved_at' => null])->save();
                }
            }
            if ($terminal) {
                if ($o->status === 'COMPLETED' && isset($o->evidence['transition'])) {
                    app(ReleaseTransitions::class)->completeBridge($agent, $o, $o->approved_by ? $this->authorizedApprover($agent, $o->approved_by) : null);
                }
                app(Audit::class)->record('agent.'.$o->kind.'_'.($o->status === 'COMPLETED' ? 'completed' : 'failed'), null, $agent->tenant_id, 'agent', null, ['agent_id' => $agent->id, 'operation_id' => $o->id]);
            }
        });
    }

    private function authorizedApprover(Agent $agent, int $userId): ?User
    {
        $context = app(TenantContext::class);
        $saved = [$context->tenantId, $context->userId, $context->platform];
        try {
            // Read only the recorded authorizer, under the operation tenant and self policy.
            $context->apply(tenantId: $agent->tenant_id, userId: $userId);
            $user = User::withoutGlobalScopes()->whereKey($userId)->first();
            if (! $user) {
                return null;
            }
            $context->forUser($user, $agent->tenant_id);

            return Gate::forUser($user)->allows('revoke', $agent) ? $user : null;
        } finally {
            $context->apply(tenantId: $saved[0], userId: $saved[1], platform: $saved[2]);
        }
    }
}
