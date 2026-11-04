<?php

namespace App\Http\Controllers\Agent;

use App\Agent\Onboarding;
use App\Agent\Protocol;
use App\Diagnostics\Engine;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\ProtocolRequest;
use App\Models\Agent;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;

class ProtocolController extends Controller
{
    public function enroll(ProtocolRequest $r, Onboarding $onboarding)
    {
        return response()->json($onboarding->request($r->validated(), $r->ip()), 202)->header('Cache-Control', 'no-store');
    }

    public function enrollmentStatus(ProtocolRequest $r, Onboarding $onboarding)
    {
        return response()->json($onboarding->finish($r->validated('receipt')))->header('Cache-Control', 'no-store');
    }

    public function heartbeat(ProtocolRequest $r)
    {
        $a = DB::transaction(function () use ($r) {
            $a = Agent::whereKey($r->attributes->get('agent')->id)->lockForUpdate()->firstOrFail();
            $metadata = $a->metadata ?? [];
            if ($r->has('telemetry')) {
                $metadata['telemetry'] = [...$r->validated('telemetry'), 'collected_at' => now()->toIso8601String()];
            }
            if (! isset($metadata['onboarding']['agent_ready']) && isset($metadata['onboarding']['credential_issued'])) {
                $metadata['onboarding']['agent_ready'] = now()->toIso8601String();
            }
            $a->forceFill(['version' => $r->validated('version'), 'last_heartbeat_at' => now(), 'metadata' => $metadata])->save();

            return $a;
        });

        return response()->json(['status' => 'ok', 'policy' => Protocol::policy($a->id, ($a->metadata['task_protocol'] ?? 0) === 1)]);
    }

    public function capabilities(ProtocolRequest $r, Audit $audit)
    {
        $a = DB::transaction(function () use ($r, $audit) {
            $a = Agent::whereKey($r->attributes->get('agent')->id)->lockForUpdate()->firstOrFail();
            $a->forceFill(['metadata' => [...($a->metadata ?? []), 'task_protocol' => $r->integer('task_protocol')]])->save();
            if ($a->capabilities !== $r->validated('capabilities')) {
                $a->forceFill(['capabilities' => $r->validated('capabilities')])->save();
                $audit->record('agent.capabilities_updated', null, $a->tenant_id, 'agent', null, ['agent_id' => $a->id]);
            }

            return $a;
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
