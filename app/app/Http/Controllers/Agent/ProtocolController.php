<?php

namespace App\Http\Controllers\Agent;

use App\Agent\AgentContext;
use App\Agent\Protocol;
use App\Diagnostics\Engine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\ProtocolRequest;
use App\Models\Agent;
use App\Models\AgentEnrollment;
use App\Models\Tenant;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProtocolController extends Controller
{
    public function enroll(ProtocolRequest $r, TenantContext $c, Audit $audit)
    {
        try {
            $c->apply();
            AgentContext::clear();
            $code = $r->validated('code');
            [$id] = explode('.', $code);
            $hash = hash('sha256', $code);
            DB::select("SELECT set_config('app.enrollment_id',?,false),set_config('app.enrollment_hash',?,false)", [$id, $hash]);
            $result = DB::transaction(function () use ($r, $c, $audit, $id, $hash) {
                $enrollment = AgentEnrollment::withoutGlobalScopes()->whereKey($id)->first();
                abort_unless($enrollment && hash_equals($enrollment->secret_hash, $hash), 401);
                $c->apply(tenantId: $enrollment->tenant_id);
                AgentContext::clear();
                $enrollment = AgentEnrollment::whereKey($id)->lockForUpdate()->firstOrFail();
                abort_if($enrollment->consumed_at, 409, 'Enrollment indisponível.');
                abort_if($enrollment->expires_at->isPast(), 410, 'Enrollment expirado.');
                abort_unless(Tenant::whereKey($enrollment->tenant_id)->where('status', 'active')->exists(), 403);
                $id = (string) Str::uuid();
                $token = $id.'.'.bin2hex(random_bytes(32));
                $agent = new Agent;
                $agent->forceFill(['id' => $id, 'tenant_id' => $enrollment->tenant_id, 'name' => $enrollment->name, 'hostname' => $r->validated('hostname'), 'version' => $r->validated('version'), 'status' => 'active', 'token_hash' => hash('sha256', $token), 'enrolled_at' => now(), 'capabilities' => $r->validated('capabilities', []), 'metadata' => ['protocol' => 1], 'last_seen_ip' => null])->save();
                $enrollment->forceFill(['consumed_at' => now()])->save();
                $audit->record('agent.enrolled', null, $agent->tenant_id, 'agent', null, ['agent_id' => $agent->id]);

                return ['agent_id' => $id, 'token' => $token, 'version' => '0.5.2', 'policy' => Protocol::policy($id)];
            });

            return response()->json($result, 201)->header('Cache-Control', 'no-store');
        } finally {
            AgentContext::clear();
            $c->apply();
        }
    }

    public function heartbeat(ProtocolRequest $r)
    {
        $a = $r->attributes->get('agent');
        $a->forceFill(['version' => $r->validated('version'), 'last_heartbeat_at' => now()])->save();

        return response()->json(['status' => 'ok', 'policy' => Protocol::policy($a->id, ($a->metadata['task_protocol'] ?? 0) === 1)]);
    }

    public function capabilities(ProtocolRequest $r, Audit $audit)
    {
        $a = $r->attributes->get('agent');
        $a->forceFill(['metadata' => [...($a->metadata ?? []), 'task_protocol' => $r->integer('task_protocol')]])->save();
        DB::transaction(function () use ($a, $r, $audit) {
            if ($a->capabilities !== $r->validated('capabilities')) {
                $a->forceFill(['capabilities' => $r->validated('capabilities')])->save();
                $audit->record('agent.capabilities_updated', null, $a->tenant_id, 'agent', null, ['agent_id' => $a->id]);
            }
        });

        return response()->json(['status' => 'ok', 'capabilities' => $a->capabilities]);
    }

    public function claim(ProtocolRequest $r, Engine $engine)
    {
        $a = $r->attributes->get('agent');
        $enabled = $r->integer('task_protocol') === 1 && ($a->metadata['task_protocol'] ?? 0) === 1;
        $task = $engine->claim($a, $enabled ? $r->integer('capacity', 1) : 0);

        return response()->json(['task' => $task, 'policy' => Protocol::policy($a->id, $enabled), 'cancel_execution_ids' => $engine->activeCancellations($a)])->header('Cache-Control', 'no-store');
    }

    public function start(ProtocolRequest $r, Engine $engine)
    {
        return response()->json($engine->operation($r->attributes->get('agent'), 'start', $r->validated()));
    }

    public function renew(ProtocolRequest $r, Engine $engine)
    {
        return response()->json($engine->operation($r->attributes->get('agent'), 'renew', $r->validated()));
    }

    public function result(ProtocolRequest $r, Engine $engine)
    {
        return response()->json($engine->operation($r->attributes->get('agent'), 'result', $r->validated()));
    }

    public function reconcile(ProtocolRequest $r, Engine $engine)
    {
        $a = $r->attributes->get('agent');

        return response()->json(['executions' => $engine->reconcile($a, $r->validated('executions')), 'tasks_enabled' => ($a->metadata['task_protocol'] ?? 0) === 1]);
    }
}
