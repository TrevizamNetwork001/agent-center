<?php

namespace App\Http\Controllers\Agent;

use App\Agent\Bootstrap;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BootstrapController extends Controller
{
    public function request(Request $r, Bootstrap $bootstrap)
    {
        $data = $r->validate(['hostname' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D'], 'fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'tenant_id' => ['prohibited'], 'agent_id' => ['prohibited']]);

        return response()->json($bootstrap->request($data, $r->ip()), 202)->header('Cache-Control', 'no-store');
    }

    public function status(Request $r, Bootstrap $bootstrap)
    {
        $data = $r->validate(['receipt' => ['required', 'string', 'regex:/^[a-f0-9-]{36}\\.[a-f0-9]{64}$/D']]);

        return response()->json($bootstrap->status($data['receipt']))->header('Cache-Control', 'no-store');
    }

    public function index(Request $r, Bootstrap $bootstrap)
    {
        Gate::authorize('viewAny', Tenant::class);
        $data = ['requests' => $bootstrap->pending(), 'tenants' => Tenant::where('status', 'active')->orderBy('name')->get()];

        $response = response()->view($r->boolean('fragment') ? 'agents.requests-list' : 'agents.requests', $data);
        if ($r->boolean('fragment')) {
            $response->header('X-AC-Fragment', 'agent-requests');
        }

        return $response;
    }

    public function accept(Request $r, string $requestId, Bootstrap $bootstrap)
    {
        Gate::authorize('viewAny', Tenant::class);
        $data = $r->validate(['tenant_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:120']]);
        $bootstrap->accept($requestId, $data['tenant_id'], $data['name'], $r->user());

        return redirect()->route('agent-requests.index')->with('success', 'Agent aceito. O instalador concluirá a conexão automaticamente.');
    }
}
