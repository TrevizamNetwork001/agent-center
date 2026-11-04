<?php

namespace App\Http\Controllers\Agent;

use App\Agent\Releases;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\EnrollmentRequest;
use App\Models\Agent;
use App\Models\AgentEnrollment;
use App\Models\AgentOperation;
use App\Models\AuditEvent;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ManagementController extends Controller
{
    public function index(Request $r)
    {
        Gate::authorize('viewAny', Agent::class);

        $data = ['agents' => Agent::orderBy('name')->paginate(25)];
        if ($r->boolean('fragment')) {
            return response()->view('agents.list', $data)->header('X-AC-Fragment', 'agents-panel');
        }

        return view('agents.index', $data);
    }

    public function show(Request $r, Agent $agent)
    {
        Gate::authorize('view', $agent);

        $enrollment = AgentEnrollment::where('agent_id', $agent->id)->latest()->first();
        $releases = app(Releases::class)->all();
        $operations = AgentOperation::where('agent_id', $agent->id)->latest()->limit(20)->get();
        $events = Gate::allows('view-audit') ? AuditEvent::where('metadata->agent_id', $agent->id)->with(['actor', 'tenant'])->latest('id')->limit(10)->get() : collect();

        if ($r->boolean('fragment')) {
            return response()->view('agents.detail', compact('agent', 'events', 'releases', 'operations', 'enrollment'))->header('X-AC-Fragment', 'agent-detail');
        }

        return view('agents.show', compact('agent', 'events', 'releases', 'operations', 'enrollment'));
    }

    public function enrollment(EnrollmentRequest $r, TenantContext $c, Audit $audit)
    {
        $code = DB::transaction(function () use ($r, $c, $audit) {
            $id = (string) Str::uuid();
            $code = $id.'.'.bin2hex(random_bytes(32));
            $agent = new Agent;
            $agent->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $c->tenantId, 'name' => $r->validated('name'), 'hostname' => '', 'version' => 'unavailable', 'status' => 'registered', 'token_hash' => hash('sha256', random_bytes(32)), 'enrolled_at' => null, 'capabilities' => [], 'metadata' => ['onboarding' => ['registered' => now()->toIso8601String(), 'installation_code_generated' => now()->toIso8601String()]]])->save();
            $audit->record('agent.created', $r->user(), $c->tenantId, 'agent', null, ['agent_id' => $agent->id]);
            $e = new AgentEnrollment;
            $e->forceFill(['id' => $id, 'agent_id' => $agent->id, 'tenant_id' => $c->tenantId, 'created_by' => $r->user()->id, 'name' => $r->validated('name'), 'secret_hash' => hash('sha256', $code), 'expires_at' => now()->addSeconds($r->validated('expires_in', 900))])->save();
            $audit->record('agent.enrollment_created', $r->user(), $c->tenantId, 'enrollment', null, ['enrollment_id' => $id]);

            return $code;
        });

        // Código somente nesta resposta; não colocar em sessão/flash/logs.
        return response()->view('agents.enrollment', compact('code'))->header('Cache-Control', 'no-store');
    }

    public function approve(Request $r, Agent $agent, Audit $audit)
    {
        Gate::authorize('revoke', $agent);
        $r->validate(['identity_reviewed' => ['accepted']]);
        DB::transaction(function () use ($r, $agent, $audit) {
            $e = AgentEnrollment::where('agent_id', $agent->id)->lockForUpdate()->latest('created_at')->firstOrFail();
            abort_unless($agent->status === 'pending' && ! $agent->revoked_at && ! $e->approved_at && $e->requested_at && $e->requested_at->gt(now()->subDay()), 409);
            $e->forceFill(['approved_at' => now(), 'approved_by' => $r->user()->id])->save();
            $agent->refresh()->forceFill(['metadata' => [...$agent->metadata, 'onboarding' => [...$agent->metadata['onboarding'], 'identity_verified' => now()->toIso8601String(), 'approved' => now()->toIso8601String()]]])->save();
            $audit->record('agent.enrollment_approved', $r->user(), $agent->tenant_id, 'agent', null, ['agent_id' => $agent->id, 'enrollment_id' => $e->id]);
        });

        return redirect()->route('agents.show', $agent);
    }

    public function revoke(Request $r, Agent $agent, Audit $audit)
    {
        Gate::authorize('revoke', $agent);
        DB::transaction(function () use ($r, $agent, $audit) {
            if (! $agent->revoked_at) {
                $agent->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
                $audit->record('agent.revoked', $r->user(), $agent->tenant_id, 'agent', null, ['agent_id' => $agent->id]);
            }
        });

        return redirect()->route('agents.show', $agent);
    }
}
