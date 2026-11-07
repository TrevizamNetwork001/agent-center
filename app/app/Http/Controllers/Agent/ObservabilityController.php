<?php

namespace App\Http\Controllers\Agent;

use App\Agent\ManagedSettings;
use App\Agent\Observability;
use App\Agent\Protocol;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ObservabilityController extends Controller
{
    public function check(Request $request, Agent $agent, Audit $audit)
    {
        Gate::authorize('observe', $agent);
        $input = $request->validate([
            'capability' => ['required', Rule::in(Protocol::CAPABILITIES)],
            'destination' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9:][a-zA-Z0-9.:-]*$/D'],
            'protocol' => ['required', Rule::in(['icmp', 'tcp'])],
        ]);
        $input['protocol'] = match ($input['capability']) {
            'agent_info' => 'none',
            'dns_lookup:v1' => 'dns',
            'http_probe:v1' => 'https',
            'tcp_connect:v1' => 'tcp',
            'icmp_ping:v1', 'traceroute:v1' => 'icmp',
            default => $input['protocol'],
        };
        DB::transaction(function () use ($request, $agent, $input, $audit) {
            $locked = Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            Gate::authorize('observe', $locked);
            abort_unless(Observability::view($locked)['fresh'], 409, 'Agent sem relatório recente compatível.');
            abort_if(Observability::pending($locked), 409, 'Consulta em andamento.');
            $id = (string) Str::uuid();
            $meta = $locked->metadata;
            unset($meta['permission_result']);
            $meta['permission_request'] = [...$input, 'destination' => strtolower(rtrim($input['destination'], '.')), 'id' => $id, 'revision' => ManagedSettings::current($locked)['revision'], 'digest' => Observability::managedDigest($locked), 'expires_at' => now()->addSeconds(60)->toIso8601String()];
            $locked->forceFill(['metadata' => $meta])->save();
            // No target, policy, credentials or raw Agent output in audit events.
            $audit->record('agent.permission_requested', $request->user(), $locked->tenant_id, 'agent', null, ['agent_id' => $locked->id]);
        });

        return redirect()->to(route('agents.show', $agent).'#agent-observability');
    }
}
