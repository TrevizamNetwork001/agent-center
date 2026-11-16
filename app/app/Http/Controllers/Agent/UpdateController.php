<?php

namespace App\Http\Controllers\Agent;

use App\Agent\Operations;
use App\Agent\Protocol;
use App\Agent\ReleaseQuarantine;
use App\Agent\Releases;
use App\Agent\ReleaseTransitions;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentOperation;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateController extends Controller
{
    public function request(Request $r, Agent $agent, Releases $releases, Audit $audit)
    {
        Gate::authorize('revoke', $agent);
        $d = $r->validate(['kind' => ['required', Rule::in(['update', 'rollback'])], 'release_sha' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'reason' => ['required', Rule::in(['maintenance', 'repair', 'failed_release', 'restart'])], 'install_after_check' => ['sometimes', 'accepted']]);
        abort_if($r->boolean('install_after_check') && $d['kind'] !== 'update', 422);
        abort_unless($agent->status === 'active' && ! $agent->revoked_at, 409);
        $release = $releases->find($d['release_sha']);
        abort_if(version_compare($release['version'], '0.6.4', '>=') && version_compare($agent->metadata['updater']['version'] ?? '0.0.0', '0.6.4', '<'), 409, 'Atualizador requer transição assinada pelo Lifecycle.');
        abort_if($d['kind'] === 'rollback' && ! empty($agent->metadata['lifecycle_components']), 409, 'Componentes exigem recuperação conjunta; rollback de runtime isolado bloqueado.');
        abort_if(! empty($release['components']) && ($agent->metadata['updater']['component_protocol'] ?? 0) !== 1, 409, 'Atualizador sem suporte a componentes.');
        abort_if(isset($release['lifecycle']) && ($agent->metadata['updater']['signed_release_protocol'] ?? 0) !== 1, 409, 'Atualizador sem suporte a releases assinadas.');
        abort_if($d['reason'] === 'restart' && ($d['kind'] !== 'update' || $release['version'] !== $agent->version || ! $r->boolean('install_after_check')), 422);
        $created = DB::transaction(function () use ($r, $agent, $release, $d, $audit) {
            app(ReleaseQuarantine::class)->lock();
            app(ReleaseQuarantine::class)->assertAllowed($release);
            $agent = Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            abort_unless($agent->status === 'active' && ! $agent->revoked_at, 409);
            abort_if($d['reason'] === 'restart' && $release['version'] !== $agent->version, 409);
            if (AgentOperation::where('agent_id', $agent->id)->whereNotIn('status', ['COMPLETED', 'FAILED'])->exists()) {
                return false;
            }
            $target = $release;
            [$release, $transition] = $d['kind'] === 'update' ? app(ReleaseTransitions::class)->plan($agent, $target) : [$target, null];
            $o = new AgentOperation;
            $o->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $agent->tenant_id, 'agent_id' => $agent->id, 'requested_by' => $r->user()->id, 'kind' => $d['kind'], 'status' => 'PRECHECK_REQUESTED', 'from_version' => $agent->version, 'release' => $release, 'reason' => $d['reason'], 'evidence' => $transition ? ['transition' => $transition] : null])->save();
            if ($r->boolean('install_after_check')) {
                $o->forceFill(['approved_by' => $r->user()->id, 'approved_at' => now()])->save();
                $audit->record('agent.update_authorized', $r->user(), $agent->tenant_id, 'agent', null, ['agent_id' => $agent->id, 'operation_id' => $o->id, 'version' => $target['version'], 'sha256' => $target['sha256']]);
            }
            $audit->record('agent.'.$d['kind'].'_requested', $r->user(), $agent->tenant_id, 'agent', null, ['agent_id' => $agent->id, 'operation_id' => $o->id]);

            return true;
        });

        return redirect()->to($r->boolean('install_after_check') ? route('agents.show', ['agent' => $agent, 'updating' => 1]) : route('agents.show', $agent).'#agent-update')->with('success', $created
            ? 'Solicitação registrada. Acompanhe a verificação do atualizador abaixo.'
            : 'Já existe uma operação pendente para este Agent. Acompanhe o andamento abaixo.');
    }

    public function approve(Request $r, Agent $agent, AgentOperation $operation, Audit $audit)
    {
        Gate::authorize('revoke', $agent);
        abort_unless($operation->agent_id === $agent->id, 404);
        $r->validate(['plan_reviewed' => ['accepted']]);
        DB::transaction(function () use ($r, $agent, $operation, $audit) {
            app(ReleaseQuarantine::class)->lock();
            $o = AgentOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            app(ReleaseQuarantine::class)->assertAllowed($o->release);
            abort_unless($o->status === 'PLANNED' && $agent->status === 'active' && ! $agent->revoked_at && $o->updated_at->gt(now()->subMinutes(15)), 409);
            $o->forceFill(['status' => 'APPROVED', 'approved_by' => $r->user()->id, 'approved_at' => now()])->save();
            $audit->record('agent.update_approved', $r->user(), $agent->tenant_id, 'agent', null, ['agent_id' => $agent->id, 'operation_id' => $o->id]);
        });

        return redirect()->route('agents.show', $agent);
    }

    public function claim(Request $r, Operations $ops)
    {
        $data = $r->validate(['runtime_package_protocol' => ['sometimes', 'integer', Rule::in([1, 2, 3])], 'updater_version' => ['sometimes', Rule::in(Protocol::VERSIONS)], 'release_download_protocol' => ['sometimes', 'integer', Rule::in([1])], 'signed_release_protocol' => ['sometimes', 'integer', Rule::in([1])], 'component_protocol' => ['sometimes', 'integer', Rule::in([1])], 'recovery_protocol' => ['sometimes', 'integer', Rule::in([1])]]);

        return response()->json(['operation' => $ops->claim($r->attributes->get('agent'), $data['updater_version'] ?? null, isset($data['release_download_protocol']) ? (int) $data['release_download_protocol'] : null, (int) ($data['signed_release_protocol'] ?? 0), (int) ($data['component_protocol'] ?? 0), (int) ($data['recovery_protocol'] ?? 0), (int) ($data['runtime_package_protocol'] ?? 1))])->header('Cache-Control', 'no-store');
    }

    public function reconcile(Request $r, Agent $agent, AgentOperation $operation, Audit $audit)
    {
        Gate::authorize('revoke', $agent);
        abort_unless($operation->agent_id === $agent->id, 404);
        $r->validate(['recovery_reviewed' => ['accepted']]);
        $precheckRecovered = DB::transaction(function () use ($r, $agent, $operation, $audit) {
            $current = Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $o = AgentOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->status === 'active' && ! $current->revoked_at && $o->status === 'UNCERTAIN' && isset($o->release['lifecycle']), 409);
            $evidence = $o->evidence ?? [];
            // The transaction that grants apply always writes update_started.
            // A fresh Agent and the absence of that grant distinguish an
            // interrupted download from a possibly interrupted installation.
            $precheckOnly = $o->kind === 'update' && $o->error === 'interrupted'
                && ! isset($evidence['backup']) && $o->claimed_at
                && $current->version === $o->from_version
                && $current->last_heartbeat_at?->gte($o->claimed_at)
                && $current->last_heartbeat_at?->gt(now()->subSeconds(60))
                && $current->last_poll_at?->gt(now()->subSeconds(30))
                && (int) ($current->metadata['lifecycle_release_sequence'] ?? 0) < (int) $o->release['sequence']
                && ! DB::table('audit_events')->where('tenant_id', $current->tenant_id)->where('event_type', 'agent.update_started')->where('metadata->operation_id', $o->id)->exists();
            $evidence['reconciliation'] = ['requested_at' => now()->toIso8601String(), 'requested_by' => $r->user()->id];
            if ($precheckOnly) {
                $evidence['original_failure'] = ['status' => $o->status, 'error' => $o->error, 'evidence' => $o->evidence];
                $evidence['reconciliation'] += ['verified_at' => now()->toIso8601String(), 'precheck_only' => true];
                $o->forceFill(['status' => 'FAILED', 'evidence' => $evidence])->save();
                $audit->record('agent.recovery_requested', $r->user(), $current->tenant_id, 'agent', null, ['agent_id' => $current->id, 'operation_id' => $o->id]);
                $audit->record('agent.recovery_verified', $r->user(), $current->tenant_id, 'agent', null, ['agent_id' => $current->id, 'operation_id' => $o->id]);

                return true;
            }
            $o->forceFill(['evidence' => $evidence])->save();
            $audit->record('agent.recovery_requested', $r->user(), $current->tenant_id, 'agent', null, ['agent_id' => $current->id, 'operation_id' => $o->id]);

            return false;
        });

        return redirect()->route('agents.show', $agent)->with('success', $precheckRecovered ? 'Pré-verificação interrompida encerrada: nenhuma instalação foi autorizada. Você pode solicitar uma nova atualização.' : 'Verificação da recuperação solicitada. Novas atualizações seguem bloqueadas até a confirmação do Agent.');
    }

    public function report(Request $r, Operations $ops)
    {
        $d = $r->validate(['operation_id' => ['required', 'uuid'], 'status' => ['required', Rule::in(['PLANNED', 'COMPLETED', 'FAILED', 'UNCERTAIN'])], 'error' => ['nullable', Rule::in(Operations::ERRORS)], 'evidence' => ['nullable', 'array:sha256,version,backup,freeze_preserved,postcheck,rollback,components,recovery_verified'], 'evidence.recovery_verified' => ['sometimes', 'boolean'], 'evidence.sha256' => ['sometimes', 'regex:/^[a-f0-9]{64}$/D'], 'evidence.version' => ['sometimes', 'regex:/^[0-9]+\\.[0-9]+\\.[0-9]+$/D'], 'evidence.backup' => ['sometimes', 'uuid'], 'evidence.freeze_preserved' => ['sometimes', 'accepted'], 'evidence.postcheck' => ['sometimes', 'boolean'], 'evidence.rollback' => ['sometimes', 'boolean'], 'evidence.components' => ['sometimes', 'array', 'max:1'], 'evidence.components.*' => ['array:id,version,profile,status,operational_verified'], 'evidence.components.*.id' => ['required', Rule::in(['mtr'])], 'evidence.components.*.version' => ['required', 'regex:/^[0-9]+\\.[0-9]+\\.[0-9]+$/D'], 'evidence.components.*.profile' => ['required', Rule::in(['network-raw-helper'])], 'evidence.components.*.status' => ['required', Rule::in(['installed', 'rolled_back', 'uncertain'])], 'evidence.components.*.operational_verified' => ['required', 'boolean'], 'tenant_id' => ['prohibited'], 'agent_id' => ['prohibited']]);
        $ops->report($r->attributes->get('agent'), $d);

        return response()->json(['status' => 'ok']);
    }
}
