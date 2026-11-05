<?php

namespace App\Http\Controllers\Agent;

use App\Agent\Operations;
use App\Agent\Protocol;
use App\Agent\Releases;
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
        abort_if($d['reason'] === 'restart' && ($d['kind'] !== 'update' || $release['version'] !== $agent->version || ! $r->boolean('install_after_check')), 422);
        $created = DB::transaction(function () use ($r, $agent, $release, $d, $audit) {
            $agent = Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            abort_unless($agent->status === 'active' && ! $agent->revoked_at, 409);
            abort_if($d['reason'] === 'restart' && $release['version'] !== $agent->version, 409);
            if (AgentOperation::where('agent_id', $agent->id)->whereNotIn('status', ['COMPLETED', 'FAILED'])->exists()) {
                return false;
            }
            $o = new AgentOperation;
            $o->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $agent->tenant_id, 'agent_id' => $agent->id, 'requested_by' => $r->user()->id, 'kind' => $d['kind'], 'status' => 'PRECHECK_REQUESTED', 'from_version' => $agent->version, 'release' => $release, 'reason' => $d['reason']])->save();
            if ($r->boolean('install_after_check')) {
                $o->forceFill(['approved_by' => $r->user()->id, 'approved_at' => now()])->save();
                $audit->record('agent.update_authorized', $r->user(), $agent->tenant_id, 'agent', null, ['agent_id' => $agent->id, 'operation_id' => $o->id, 'version' => $release['version'], 'sha256' => $release['sha256']]);
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
            $o = AgentOperation::whereKey($operation->id)->lockForUpdate()->firstOrFail();
            abort_unless($o->status === 'PLANNED' && $agent->status === 'active' && ! $agent->revoked_at && $o->updated_at->gt(now()->subMinutes(15)), 409);
            $o->forceFill(['status' => 'APPROVED', 'approved_by' => $r->user()->id, 'approved_at' => now()])->save();
            $audit->record('agent.update_approved', $r->user(), $agent->tenant_id, 'agent', null, ['agent_id' => $agent->id, 'operation_id' => $o->id]);
        });

        return redirect()->route('agents.show', $agent);
    }

    public function claim(Request $r, Operations $ops)
    {
        $data = $r->validate(['updater_version' => ['sometimes', Rule::in(Protocol::VERSIONS)], 'release_download_protocol' => ['sometimes', 'integer', Rule::in([1])]]);

        return response()->json(['operation' => $ops->claim($r->attributes->get('agent'), $data['updater_version'] ?? null, isset($data['release_download_protocol']) ? (int) $data['release_download_protocol'] : null)])->header('Cache-Control', 'no-store');
    }

    public function report(Request $r, Operations $ops)
    {
        $d = $r->validate(['operation_id' => ['required', 'uuid'], 'status' => ['required', Rule::in(['PLANNED', 'COMPLETED', 'FAILED', 'UNCERTAIN'])], 'error' => ['nullable', Rule::in(Operations::ERRORS)], 'evidence' => ['nullable', 'array:sha256,version,backup,freeze_preserved,postcheck,rollback'], 'evidence.sha256' => ['sometimes', 'regex:/^[a-f0-9]{64}$/D'], 'evidence.version' => ['sometimes', 'regex:/^[0-9]+\\.[0-9]+\\.[0-9]+$/D'], 'evidence.backup' => ['sometimes', 'uuid'], 'evidence.freeze_preserved' => ['sometimes', 'accepted'], 'evidence.postcheck' => ['sometimes', 'boolean'], 'evidence.rollback' => ['sometimes', 'boolean'], 'tenant_id' => ['prohibited'], 'agent_id' => ['prohibited']]);
        $ops->report($r->attributes->get('agent'), $d);

        return response()->json(['status' => 'ok']);
    }
}
