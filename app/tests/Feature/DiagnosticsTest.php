<?php

namespace Tests\Feature;

use App\Agent\Protocol;
use App\Diagnostics\Catalog;
use App\Diagnostics\Classifier;
use App\Diagnostics\Digest;
use App\Diagnostics\Engine;
use App\Diagnostics\Normalizer;
use App\Diagnostics\Planner;
use App\Diagnostics\WorkerHealth;
use App\Models\Agent;
use App\Models\AgentOperation;
use App\Models\AgentTask;
use App\Models\AgentTaskAttempt;
use App\Models\AuditEvent;
use App\Models\Diagnostic;
use App\Models\DiagnosticConclusion;
use App\Models\DiagnosticPlan;
use App\Models\DiagnosticStep;
use App\Models\Profile;
use App\Models\Skill;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\IdentityTestCase;

class DiagnosticsTest extends IdentityTestCase
{
    private Agent $agent;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context()->forUser($this->operator);
        $this->token = Str::uuid().'.'.str_repeat('a', 64);
        $this->agent = new Agent;
        $this->agent->forceFill(['id' => explode('.', $this->token)[0], 'tenant_id' => $this->a->id, 'name' => 'Diagnostic Lab', 'hostname' => 'diag-lab', 'status' => 'active', 'version' => '0.5.2', 'token_hash' => hash('sha256', $this->token), 'capabilities' => Protocol::CAPABILITIES, 'metadata' => ['task_protocol' => 1], 'enrolled_at' => now()])->save();
        app(Catalog::class)->ensure();
    }

    private function diagnostic(string $profile = 'SITE_LENTO', string $target = 'example.test'): Diagnostic
    {
        return app(Engine::class)->create(Profile::where('key', $profile)->sole(), $this->agent, $target, $this->operator);
    }

    private function claim(): ?array
    {
        return app(Engine::class)->claim($this->agent);
    }

    private function envelope(array $task): array
    {
        return array_intersect_key($task, array_flip(['task_id', 'execution_id', 'generation', 'lease_token']));
    }

    private function api(string $endpoint, array $payload)
    {
        $r = $this->postJson('/api/v1/agent/'.$endpoint, $payload, ['Authorization' => 'Bearer '.$this->token]);
        $this->context()->forUser($this->operator);

        return $r;
    }

    private function success(array $task): array
    {
        return match ($task['capability']) {
            'dns_lookup:v1' => ['ok' => true, 'data' => ['answers' => $task['input']['record_type'] === 'A' ? ['127.0.0.1'] : [], 'record_type' => $task['input']['record_type'], 'duration_ms' => 1]],
            'tcp_connect:v1' => ['ok' => true, 'data' => ['connected' => true, 'duration_ms' => 2]],
            'http_probe:v1' => ['ok' => true, 'data' => ['status' => 200, 'tls' => true, 'duration_ms' => 10, 'resolved_ip' => '127.0.0.1']],
            'icmp_ping:v1' => ['ok' => false, 'error' => 'timeout'],default => ['ok' => true, 'data' => ['hops' => [], 'reached_destination' => false, 'duration_ms' => 1]]
        };
    }

    public function test_catalog_closed_and_planner_snapshot_digest_and_waves(): void
    {
        $this->assertSame(10, Skill::count());
        $this->assertSame(8, Profile::count());
        app(Catalog::class)->ensure();
        $this->assertSame(10, Skill::count());
        $d = $this->diagnostic();
        app(Engine::class)->tick();
        $plan = DiagnosticPlan::sole();
        $this->assertCount(6, $plan->document['steps']);
        $this->assertSame(64, strlen($plan->digest));
        $this->assertSame(2, AgentTask::count());
        app(Engine::class)->tick();
        $this->assertSame(1, DiagnosticPlan::count());
        $this->assertSame(2, AgentTask::count());
        $this->assertSame('RUNNING', $d->fresh()->status);
        $this->assertSame([1, 1, 2, 3, 4, 5], array_column($plan->document['steps'], 'wave'));
    }

    public function test_complete_contract_end_to_end_and_optional_icmp_does_not_override_normal(): void
    {
        $d = $this->diagnostic();
        $engine = app(Engine::class);
        $engine->tick();
        $completed = 0;
        for ($i = 0; $i < 12; $i++) {
            $task = $this->api('claim', ['task_protocol' => 1])->assertOk()->json('task');
            if (! $task) {
                $engine->tick();
                if (! in_array($d->fresh()->status, ['PENDING', 'RUNNING'])) {
                    break;
                }

                continue;
            }
            $envelope = $this->envelope($task);
            $this->api('start', $envelope)->assertOk();
            $this->api('start', $envelope)->assertOk();
            $this->api('renew', $envelope)->assertOk();
            $payload = $envelope + ['result' => $this->success($task)];
            $this->api('result', $payload)->assertOk();
            $this->api('result', $payload)->assertOk()->assertJsonPath('duplicate', true);
            $engine->tick();
            $completed++;
        }
        $this->assertSame(5, $completed);
        $this->assertSame('COMPLETED', $d->fresh()->status);
        $this->assertSame('NORMAL', DiagnosticConclusion::sole()->classification);
        $this->assertSame(Classifier::NORMAL, DiagnosticConclusion::sole()->summary);
        $this->assertSame('SKIPPED', DiagnosticStep::where('key', 'trace')->sole()->status);
        $this->assertSame(1, AuditEvent::where('event_type', 'diagnostic.completed')->count());
    }

    public function test_claim_is_single_active_for_agent_and_old_client_never_receives_work(): void
    {
        $this->diagnostic();
        app(Engine::class)->tick();
        $this->api('claim', [])->assertOk()->assertJsonPath('task', null)->assertJsonPath('policy.tasks_enabled', false);
        $t = $this->claim();
        $this->assertNotNull($t);
        $this->assertNull($this->claim());
        $this->assertSame(1, AgentTaskAttempt::count());
        $this->assertStringNotContainsString($t['lease_token'], AgentTaskAttempt::sole()->toJson());
    }

    public function test_expired_claim_requeues_with_new_generation_but_running_never_reexecutes(): void
    {
        $this->diagnostic(target: '127.0.0.1');
        $engine = app(Engine::class);
        $engine->tick();
        $old = $this->claim();
        AgentTaskAttempt::whereKey($old['execution_id'])->update(['lease_expires_at' => now()->subSecond()]);
        $engine->tick();
        $new = $this->claim();
        $this->assertSame($old['task_id'], $new['task_id']);
        $this->assertSame(2, $new['generation']);
        $this->api('start', $this->envelope($old))->assertConflict();
        $this->api('start', $this->envelope($new))->assertOk();
        AgentTaskAttempt::whereKey($new['execution_id'])->update(['lease_expires_at' => now()->subSecond()]);
        $engine->tick();
        $this->assertSame('EXPIRED', AgentTask::find($new['task_id'])->status);
        $this->api('renew', $this->envelope($new))->assertConflict();
        $this->api('result', $this->envelope($new) + ['result' => $this->success($new)])->assertConflict();
    }

    public function test_result_before_start_bad_generation_bad_token_and_result_conflict_rejected(): void
    {
        $this->diagnostic();
        app(Engine::class)->tick();
        $t = $this->claim();
        $e = $this->envelope($t);
        $this->api('result', $e + ['result' => $this->success($t)])->assertConflict();
        $this->api('start', array_replace($e, ['generation' => 10]))->assertConflict();
        $this->api('start', array_replace($e, ['lease_token' => str_repeat('b', 64)]))->assertConflict();
        $this->api('start', $e)->assertOk();
        $this->api('result', $e + ['result' => $this->success($t)])->assertOk();
        $this->api('result', $e + ['result' => ['ok' => false, 'error' => 'timeout']])->assertConflict();
    }

    public function test_cancel_preserves_evidence_and_reconcile_tells_active_execution_to_stop(): void
    {
        $d = $this->diagnostic();
        $engine = app(Engine::class);
        $engine->tick();
        $first = $this->claim();
        $e = $this->envelope($first);
        $this->api('start', $e)->assertOk();
        $this->api('result', $e + ['result' => $this->success($first)])->assertOk();
        $next = $this->claim();
        $e2 = $this->envelope($next);
        $this->api('start', $e2)->assertOk();
        $engine->cancel($d, $this->operator);
        $this->api('renew', $e2)->assertOk()->assertJsonPath('cancel', true);
        $this->api('reconcile', ['executions' => [['task_id' => $next['task_id'], 'execution_id' => $next['execution_id'], 'state' => 'executing']]])->assertOk()->assertJsonPath('executions.0.disposition', 'cancel');
        $this->api('result', $e2 + ['result' => ['ok' => false, 'error' => 'cancelled']])->assertOk();
        $engine->tick();
        $this->assertSame('CANCELLED', $d->fresh()->status);
        $this->assertSame('INCONCLUSIVO', DiagnosticConclusion::sole()->classification);
        $this->assertNotEmpty(DiagnosticConclusion::sole()->evidence);
        $this->assertSame(1, DiagnosticStep::where('status', 'COMPLETED')->count());
    }

    public function test_reconcile_pending_result_and_acknowledged_and_expired(): void
    {
        $this->diagnostic();
        app(Engine::class)->tick();
        $t = $this->claim();
        $e = $this->envelope($t);
        $this->api('start', $e)->assertOk();
        $r = ['executions' => [['task_id' => $t['task_id'], 'execution_id' => $t['execution_id'], 'state' => 'result_pending']]];
        $this->api('reconcile', $r)->assertOk()->assertJsonPath('executions.0.disposition', 'resend_result');
        $this->api('result', $e + ['result' => $this->success($t)])->assertOk();
        $this->api('reconcile', $r)->assertOk()->assertJsonPath('executions.0.disposition', 'acknowledged');
    }

    public function test_dns_failure_blocks_tcp_http_and_target_validation_prevents_credentials(): void
    {
        $this->diagnostic();
        $engine = app(Engine::class);
        $engine->tick();
        for ($i = 0; $i < 2; $i++) {
            $t = $this->claim();
            $e = $this->envelope($t);
            $this->api('start', $e)->assertOk();
            $this->api('result', $e + ['result' => ['ok' => false, 'error' => 'dns_failed']])->assertOk();
        }$engine->tick();
        $engine->tick();
        $this->assertSame(2, AgentTask::count());
        $this->assertSame('BLOCKED', DiagnosticStep::where('key', 'https')->sole()->status);
        $this->assertSame('INDICIO_DE_FALHA', DiagnosticConclusion::sole()->classification);
        $this->expectException(ValidationException::class);
        app(Planner::class)->target('https://user:pass@example.test');
    }

    public function test_direct_rls_and_queries_and_ui_prevent_cross_tenant_reads_and_writes(): void
    {
        $d = $this->diagnostic();
        app(Engine::class)->tick();
        $this->claim();
        $this->context()->forUser($this->other);
        foreach (['skills', 'profiles', 'diagnostics', 'diagnostic_plans', 'diagnostic_steps', 'agent_tasks', 'agent_task_attempts', 'diagnostic_conclusions'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame(0, DB::table('diagnostics')->where('id', $d->id)->update(['status' => 'FAILED']));
        try {
            DB::transaction(fn () => DB::table('profiles')->insert(['id' => Str::uuid(), 'tenant_id' => $this->a->id, 'key' => 'evil', 'display_name' => 'evil', 'description' => 'evil', 'definition' => '{}', 'created_at' => now(), 'updated_at' => now()]));
            $this->fail('RLS insert');
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->getCode());
        }
        $this->actingAs($this->other)->get('/diagnostics/'.$d->id)->assertNotFound();
        $this->post('/diagnostics/'.$d->id.'/cancel')->assertNotFound();
    }

    public function test_roles_ui_and_catalog_toggle_audited_and_mass_assignment_closed(): void
    {
        $this->actingAs($this->viewer)->get('/diagnostics')->assertOk();
        $this->get('/diagnostics/create')->assertForbidden();
        $this->get('/diagnostic-catalog')->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->operator)->get('/diagnostics/create')->assertOk();
        $this->get('/operations')->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->admin)->get('/diagnostic-catalog')->assertOk()->assertSee('Schemas');
        $this->get('/operations')->assertOk();
        $this->context()->forUser($this->admin);
        $s = Skill::where('key', 'dns_lookup')->sole();
        $this->put('/diagnostic-catalog/skills/'.$s->id, ['enabled' => 0, 'capability' => 'device_future'])->assertSessionHasErrors('capability');
        $this->put('/diagnostic-catalog/skills/'.$s->id, ['enabled' => 0])->assertRedirect();
        $this->context()->forUser($this->admin);
        $this->assertFalse($s->fresh()->enabled);
        $this->assertSame(1, AuditEvent::where('event_type', 'skill.disabled')->where('metadata->skill_id', $s->id)->count());
        $this->assertTrue(Gate::forUser($this->admin)->allows('create', Diagnostic::class));
    }

    public function test_immutable_plans_and_conclusions_and_runtime_not_owner(): void
    {
        $d = $this->diagnostic();
        app(Engine::class)->tick();
        app(Engine::class)->cancel($d, $this->operator);
        foreach (['diagnostic_plans', 'diagnostic_conclusions'] as $table) {
            try {
                DB::transaction(fn () => DB::table($table)->update(['id' => Str::uuid()]));
                $this->fail('Append-only grants');
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->getCode());
            }
        }
        $this->assertSame(33, (int) DB::selectOne("SELECT count(*) n FROM pg_class WHERE relnamespace='public'::regnamespace AND relrowsecurity AND relforcerowsecurity")->n);
    }

    public function test_offline_deadline_ends_without_invented_success(): void
    {
        $d = $this->diagnostic();
        app(Engine::class)->tick();
        Diagnostic::whereKey($d->id)->update(['created_at' => now()->subMinutes(6)]);
        app(Engine::class)->tick();
        $this->assertSame('FAILED', $d->fresh()->status);
        $this->assertSame('INCONCLUSIVO', DiagnosticConclusion::sole()->classification);
    }

    public function test_golden_scenarios_fixed_expected_and_invalid_outputs_rejected(): void
    {
        $fixtures = json_decode(file_get_contents(resource_path('fixtures/diagnostics.json')), true);
        $this->assertGreaterThanOrEqual(10, count(array_filter($fixtures, fn ($f) => $f['profile'] === 'SITE_LENTO')));
        foreach ($fixtures as $f) {
            $r = app(Classifier::class)->classify($f['profile'], $f['evidence'], $f['thresholds'] ?? [], $f['cancelled'] ?? false);
            $this->assertSame($f['expected'], $r['classification'], $f['name']);
        }
        foreach ([['ok' => true, 'data' => ['connected' => true, 'duration_ms' => 1, 'secret' => 'never']], ['ok' => false, 'error' => 'Authorization: secret']] as $result) {
            try {
                app(Normalizer::class)->normalize('tcp_connect:v1', $result);
                $this->fail('Unsafe result');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_worker_health_uses_monotonic_marker_and_clears_tenant_context(): void
    {
        $path = WorkerHealth::path();
        $old = is_file($path) ? file_get_contents($path) : null;
        try {
            WorkerHealth::write(1, 0);
            $this->assertTrue(WorkerHealth::healthy());
            $v = json_decode(file_get_contents($path), true);
            $v['loop_ns'] = hrtime(true) - 16_000_000_000;
            file_put_contents($path, json_encode($v));
            touch($path);
            $this->assertFalse(WorkerHealth::healthy());
            $this->artisan('agent-center:diagnostic-worker', ['--once' => true])->assertExitCode(0);
            $this->assertNull($this->context()->tenantId);
            $this->assertFalse($this->context()->platform);
            $this->assertTrue(WorkerHealth::healthy());
        } finally {
            if ($old !== null) {
                file_put_contents($path, $old);
            } else {
                @unlink($path);
            }
        }
    }

    public function test_explicit_trace_and_plan_digest_survives_jsonb_key_order(): void
    {
        $d = app(Engine::class)->create(Profile::where('key', 'SITE_LENTO')->sole(), $this->agent, 'example.test', $this->operator, ['trace_requested' => true]);
        app(Engine::class)->tick();
        $p = DiagnosticPlan::sole();
        $this->assertSame($p->digest, Digest::hash($p->document));
        $this->assertTrue(app(Planner::class)->condition('trace_needed', [], $p->document['thresholds']));
    }

    public function test_disabled_skill_and_missing_capability_cannot_materialize_required_work(): void
    {
        Skill::where('key', 'dns_lookup')->update(['enabled' => false]);
        $d = $this->diagnostic();
        app(Engine::class)->tick();
        app(Engine::class)->tick();
        $this->assertSame(0, AgentTask::count());
        $this->assertSame('COMPLETED', $d->fresh()->status);
        $this->assertSame('INCONCLUSIVO', DiagnosticConclusion::sole()->classification);
    }

    public function test_invalid_target_fixtures_are_rejected_by_planner_and_request(): void
    {
        foreach (['https://user:secret@example.test', 'http://example.test', 'https://example.test?token=x', 'example.test;command', 'https://example.test:444', 'https://example.test/#secret'] as $target) {
            try {
                app(Planner::class)->target($target);
                $this->fail('Unsafe target');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->actingAs($this->operator)->post('/diagnostics', ['target' => 'https://user:secret@example.test', 'profile_id' => Profile::where('key', 'SITE_LENTO')->sole()->id, 'agent_id' => $this->agent->id])->assertSessionHasErrors('target');
    }

    public function test_start_refreshes_valid_claim_lease_and_numeric_result_fields_are_strict(): void
    {
        $this->diagnostic();
        app(Engine::class)->tick();
        $t = $this->claim();
        AgentTaskAttempt::whereKey($t['execution_id'])->update(['lease_expires_at' => now()->addSeconds(2)]);
        $this->api('start', $this->envelope($t))->assertOk();
        $this->assertTrue(AgentTaskAttempt::find($t['execution_id'])->lease_expires_at->gt(app(Engine::class)->clock()->addSeconds(40)));
        foreach ([['cap' => 'tcp_connect:v1', 'data' => ['connected' => true, 'duration_ms' => '1']], ['cap' => 'icmp_ping:v1', 'data' => ['sent' => 5, 'received' => 6, 'loss_percent' => 0, 'duration_ms' => 1]], ['cap' => 'dns_lookup:v1', 'data' => ['answers' => ['::1'], 'record_type' => 'A', 'duration_ms' => 1]]] as $f) {
            try {
                app(Normalizer::class)->normalize($f['cap'], ['ok' => true, 'data' => $f['data']]);
                $this->fail('Invalid typed evidence');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_human_diagnostic_detail_and_cancel_and_platform_context(): void
    {
        $d = $this->diagnostic();
        app(Engine::class)->tick();
        $this->actingAs($this->viewer)->get('/diagnostics/'.$d->id)->assertOk()->assertSee('DNS');
        $this->post('/diagnostics/'.$d->id.'/cancel')->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->operator)->post('/diagnostics/'.$d->id.'/cancel')->assertRedirect();
        $this->context()->forUser($this->operator);
        app(Engine::class)->tick();
        $this->get('/diagnostics/'.$d->id)->assertOk()->assertSee('INCONCLUSIVO');
        $this->flushSession();
        $this->actingAs($this->platform)->get('/diagnostics')->assertForbidden();
        $this->post('/tenant-context', ['tenant_id' => $this->a->id])->assertRedirect();
        $this->get('/diagnostics/'.$d->id)->assertOk();
    }

    public function test_dns_record_type_and_https_address_are_bound_to_the_claimed_task(): void
    {
        $d = $this->diagnostic();
        $engine = app(Engine::class);
        $engine->tick();
        for ($i = 0; $i < 3; $i++) {
            $task = $this->claim();
            $envelope = $this->envelope($task);
            $this->api('start', $envelope)->assertOk();
            if ($task['capability'] === 'dns_lookup:v1') {
                $wrong = ['ok' => true, 'data' => ['record_type' => $task['input']['record_type'] === 'A' ? 'AAAA' : 'A', 'answers' => [], 'duration_ms' => 1]];
                $this->api('result', $envelope + ['result' => $wrong])->assertUnprocessable();
            }
            $this->api('result', $envelope + ['result' => $this->success($task)])->assertOk();
            $engine->tick();
        }
        $task = $this->claim();
        $this->assertSame('http_probe:v1', $task['capability']);
        $envelope = $this->envelope($task);
        $this->api('start', $envelope)->assertOk();
        $wrong = $this->success($task);
        $wrong['data']['resolved_ip'] = '192.0.2.1';
        $this->api('result', $envelope + ['result' => $wrong])->assertUnprocessable();
        $this->api('result', $envelope + ['result' => $this->success($task)])->assertOk();
    }

    public function test_global_deadline_cannot_leave_a_normal_conclusion_with_partial_execution(): void
    {
        $d = $this->diagnostic();
        $engine = app(Engine::class);
        $engine->tick();
        for ($i = 0; $i < 4; $i++) {
            $task = $this->claim();
            $envelope = $this->envelope($task);
            $this->api('start', $envelope)->assertOk();
            $this->api('result', $envelope + ['result' => $this->success($task)])->assertOk();
            $engine->tick();
        }
        Diagnostic::whereKey($d->id)->update(['created_at' => now()->subMinutes(6)]);
        $engine->tick();
        $this->assertSame('FAILED', $d->fresh()->status);
        $this->assertSame('INCONCLUSIVO', DiagnosticConclusion::sole()->classification);
        $this->assertStringContainsString('prazo', DiagnosticConclusion::sole()->summary);
    }

    public function test_profile_or_agent_unavailable_before_plan_settles_without_tasks(): void
    {
        $d = $this->diagnostic();
        Profile::whereKey($d->profile_id)->update(['enabled' => false]);
        app(Engine::class)->tick();
        $this->assertSame('FAILED', $d->fresh()->status);
        $this->assertSame('profile_unavailable', $d->fresh()->error_code);
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(0, DiagnosticPlan::count());
        Profile::whereKey($d->profile_id)->update(['enabled' => true]);
        $d = $this->diagnostic();
        $this->agent->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
        $this->agent->delete();
        app(Engine::class)->tick();
        $this->assertSame('FAILED', $d->fresh()->status);
        $this->assertSame('agent_unavailable', $d->fresh()->error_code);
        $this->assertSame(0, AgentTask::count());
    }

    public function test_disappeared_capability_and_policy_denial_do_not_wait_for_deadline_or_trigger_route(): void
    {
        $d = $this->diagnostic();
        $engine = app(Engine::class);
        $engine->tick();
        $this->agent->forceFill(['capabilities' => []])->save();
        $this->assertNull($this->claim());
        $engine->tick();
        $engine->tick();
        $this->assertSame('COMPLETED', $d->fresh()->status);
        $this->assertSame('INCONCLUSIVO', DiagnosticConclusion::sole()->classification);
        $this->assertSame(2, DiagnosticStep::where('error_code', 'capability_unavailable')->count());
    }

    public function test_planned_maintenance_does_not_stall_diagnostics_but_approved_or_uncertain_does(): void
    {
        $d = $this->diagnostic();
        $engine = app(Engine::class);
        $engine->tick();
        $operation = new AgentOperation;
        $operation->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'agent_id' => $this->agent->id, 'requested_by' => $this->admin->id, 'kind' => 'update', 'status' => 'PLANNED', 'from_version' => '0.5.2', 'release' => [], 'reason' => 'maintenance'])->save();
        $task = $this->claim();
        $this->assertNotNull($task);
        $envelope = $this->envelope($task);
        $this->api('start', $envelope)->assertOk();
        $this->api('result', $envelope + ['result' => $this->success($task)])->assertOk();
        foreach (['APPROVED', 'UNCERTAIN'] as $status) {
            $operation->forceFill(['status' => $status])->save();
            $this->assertNull($this->claim());
        }
    }

    public function test_legacy_agent_info_schema_is_upgraded_without_reenabling_skill(): void
    {
        $skill = Skill::where('key', 'agent_info')->sole();
        $schema = $skill->output_schema;
        $schema['properties']['data']['properties']['version'] = ['const' => '0.5.2'];
        $skill->forceFill(['enabled' => false, 'output_schema' => $schema])->save();
        $before = $skill->version;
        app(Catalog::class)->ensure();
        app(Catalog::class)->ensure();
        $skill = $skill->fresh();
        $this->assertFalse($skill->enabled);
        $this->assertSame($before + 1, $skill->version);
        $this->assertContains('0.5.9', $skill->output_schema['properties']['data']['properties']['version']['enum']);
        $this->assertSame(1, AuditEvent::where('event_type', 'skill.schema_updated')->count());
    }

    public function test_revocation_during_execution_closes_work_and_keeps_existing_evidence(): void
    {
        $d = $this->diagnostic();
        $engine = app(Engine::class);
        $engine->tick();
        $task = $this->claim();
        $envelope = $this->envelope($task);
        $this->api('start', $envelope)->assertOk();
        $this->api('result', $envelope + ['result' => $this->success($task)])->assertOk();
        $this->agent->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
        $engine->tick();
        $this->assertSame('FAILED', $d->fresh()->status);
        $this->assertSame('agent_unavailable', $d->fresh()->error_code);
        $this->assertSame('INCONCLUSIVO', DiagnosticConclusion::sole()->classification);
        $this->assertNotEmpty(DiagnosticConclusion::sole()->evidence);
        $this->assertSame(0, AgentTask::whereIn('status', ['QUEUED', 'CLAIMED', 'RUNNING'])->count());
    }
}
