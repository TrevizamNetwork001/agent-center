<?php

namespace Tests\Feature;

use App\Agent\Protocol;
use App\Diagnostics\Catalog;
use App\Diagnostics\Engine;
use App\Diagnostics\Timing;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\AgentTaskAttempt;
use App\Models\DiagnosticStep;
use App\Models\Profile;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;

class DiagnosticPerformanceTest extends IdentityTestCase
{
    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context()->forUser($this->operator);
        app(Catalog::class)->ensure();
        $this->agent = new Agent;
        $this->agent->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'name' => 'Performance Lab', 'hostname' => 'perf', 'status' => 'active', 'version' => '0.6.0', 'token_hash' => hash('sha256', 'fixture-only'), 'capabilities' => Protocol::CAPABILITIES, 'metadata' => ['task_protocol' => 1]])->save();
    }

    private function create(string $key = 'SITE_NAO_ABRE')
    {
        return app(Engine::class)->create(Profile::where('key', $key)->sole(), $this->agent, 'example.com', $this->operator);
    }

    private function finish(array $task): void
    {
        $env = array_intersect_key($task, array_flip(['task_id', 'execution_id', 'generation', 'lease_token']));
        $e = app(Engine::class);
        $e->operation($this->agent, 'start', $env);
        $data = match ($task['capability']) {
            'dns_lookup:v1' => ['answers' => $task['input']['record_type'] === 'A' ? ['8.8.8.8'] : [], 'record_type' => $task['input']['record_type'], 'duration_ms' => 1],
            'tcp_connect:v1' => ['connected' => true, 'duration_ms' => 2],
            'http_probe:v1' => ['status' => 301, 'tls' => true, 'resolved_ip' => '8.8.8.8', 'duration_ms' => 10],
            default => ['sent' => 5, 'received' => 5, 'loss_percent' => 0, 'avg_ms' => 3, 'duration_ms' => 4000]
        };
        $result = ['ok' => true, 'data' => $data];
        $e->operation($this->agent, 'result', $env + ['result' => $result]);
    }

    public function test_results_advance_dependencies_without_waiting_for_worker_and_keep_durable_timing(): void
    {
        $d = $this->create();
        $e = app(Engine::class);
        $e->tick();
        for ($i = 0; $i < 5; $i++) {
            $t = $e->claim($this->agent);
            $this->assertNotNull($t);
            $this->finish($t);
        }
        $this->assertSame('COMPLETED', $d->fresh()->status);
        $this->assertSame('SKIPPED', DiagnosticStep::where('diagnostic_id', $d->id)->where('key', 'trace')->sole()->status);
        $steps = DiagnosticStep::where('diagnostic_id', $d->id)->get();
        $sum = Timing::summary($d->fresh(), $steps);
        $this->assertEquals(4014, $sum['probe_ms']);
        $this->assertNotNull($d->fresh()->performance['planned_at']);
        $this->assertNotNull($d->fresh()->performance['completed_at']);
        foreach ($steps->where('status', 'COMPLETED') as $s) {
            foreach (['enqueued_at', 'claimed_at', 'started_at', 'result_received_at', 'result_recorded_at'] as $key) {
                $this->assertArrayHasKey($key, $s->performance);
            }
        }
        $this->assertStringNotContainsString('lease_token', json_encode($d->fresh()->performance));
    }

    public function test_round_robin_prevents_one_diagnostic_draining_queue_and_only_one_lane_is_active(): void
    {
        $a = $this->create('PROBLEMA_DNS');
        $b = $this->create('PROBLEMA_DNS');
        $e = app(Engine::class);
        $e->tick();
        $first = $e->claim($this->agent);
        $firstDiag = AgentTask::findOrFail($first['task_id'])->diagnostic_id;
        $this->assertNull($e->claim($this->agent));
        $this->finish($first);
        $next = $e->claim($this->agent);
        $nextDiag = AgentTask::findOrFail($next['task_id'])->diagnostic_id;
        $this->assertNotSame($firstDiag, $nextDiag);
        $this->assertSame(1, AgentTaskAttempt::whereIn('status', ['CLAIMED', 'RUNNING'])->count());
    }

    public function test_duplicate_result_does_not_create_or_transition_successor_twice(): void
    {
        $d = $this->create();
        $e = app(Engine::class);
        $e->tick();
        $t = $e->claim($this->agent);
        $this->finish($t);
        $a = AgentTaskAttempt::findOrFail($t['execution_id']);
        $before = $a->performance;
        $s = DiagnosticStep::findOrFail(AgentTask::findOrFail($t['task_id'])->step_id);
        $env = array_intersect_key($t, array_flip(['task_id', 'execution_id', 'generation', 'lease_token']));
        $out = $e->operation($this->agent, 'result', $env + ['result' => $s->result]);
        $this->assertTrue($out['duplicate']);
        $this->assertSame($before, $a->fresh()->performance);
        $this->assertSame(2, AgentTask::where('diagnostic_id', $d->id)->count());
    }

    public function test_other_tenant_cannot_read_timing_or_poll_context(): void
    {
        $d = $this->create();
        app(Engine::class)->tick();
        $this->actingAs($this->other)->get('/diagnostics/'.$d->id)->assertNotFound();
    }

    public function test_legacy_summary_does_not_invent_timing_and_excludes_skipped_steps(): void
    {
        $d = $this->create();
        $d->performance = null;
        $a = new DiagnosticStep;
        $a->forceFill(['status' => 'COMPLETED', 'result' => ['ok' => true, 'data' => ['duration_ms' => 4420]]]);
        $b = new DiagnosticStep;
        $b->forceFill(['status' => 'SKIPPED', 'result' => null]);
        $v = Timing::summary($d, collect([$a, $b]));
        $this->assertFalse($v['instrumented']);
        $this->assertEquals(4420, $v['probe_ms']);
        $this->assertFalse($v['partial']);
    }
}
