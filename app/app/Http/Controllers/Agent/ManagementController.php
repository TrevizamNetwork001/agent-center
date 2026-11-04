<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\EnrollmentRequest;
use App\Models\Agent;
use App\Models\AgentEnrollment;
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

    public function show(Agent $agent)
    {
        Gate::authorize('view', $agent);

        $events = Gate::allows('view-audit') ? AuditEvent::where('metadata->agent_id', $agent->id)->with(['actor', 'tenant'])->latest('id')->limit(10)->get() : collect();

        return view('agents.show', compact('agent', 'events'));
    }

    public function enrollment(EnrollmentRequest $r, TenantContext $c, Audit $audit)
    {
        $code = DB::transaction(function () use ($r, $c, $audit) {
            $id = (string) Str::uuid();
            $code = $id.'.'.bin2hex(random_bytes(32));
            $e = new AgentEnrollment;
            $e->forceFill(['id' => $id, 'tenant_id' => $c->tenantId, 'created_by' => $r->user()->id, 'name' => $r->validated('name'), 'secret_hash' => hash('sha256', $code), 'expires_at' => now()->addSeconds($r->validated('expires_in', 900))])->save();
            $audit->record('agent.enrollment_created', $r->user(), $c->tenantId, 'enrollment', null, ['enrollment_id' => $id]);

            return $code;
        });

        // Código somente nesta resposta; não colocar em sessão/flash/logs.
        return response()->view('agents.enrollment', compact('code'))->header('Cache-Control', 'no-store');
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
