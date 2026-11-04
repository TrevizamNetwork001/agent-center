<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\EnrollmentRequest;
use App\Models\Agent;
use App\Models\AgentEnrollment;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ManagementController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', Agent::class);

        return view('agents.index', ['agents' => Agent::orderBy('name')->paginate(25)]);
    }

    public function show(Agent $agent)
    {
        Gate::authorize('view', $agent);

        return view('agents.show', compact('agent'));
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
