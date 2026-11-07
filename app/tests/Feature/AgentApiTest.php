<?php

namespace Tests\Feature;

use App\Agent\Protocol;
use App\Models\Agent;
use App\Models\AgentEnrollment;
use App\Models\AuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;

class AgentApiTest extends IdentityTestCase
{
    private function code(): string
    {
        $this->context()->forUser($this->admin);
        $id = (string) Str::uuid();
        $code = $id.'.'.bin2hex(random_bytes(32));
        $e = new AgentEnrollment;
        $e->forceFill(['id' => $id, 'tenant_id' => $this->a->id, 'created_by' => $this->admin->id, 'name' => 'Lab agent', 'secret_hash' => hash('sha256', $code), 'expires_at' => now()->addMinutes(15)])->save();
        $this->context()->apply();

        return $code;
    }

    private function enrolled(): array
    {
        $r = $this->postJson('/api/v1/agent/enroll', ['code' => $this->code(), 'hostname' => 'lab-agent', 'version' => '0.5.2', 'capabilities' => ['agent_info']])->assertStatus(202)->json();

        return $this->approved($r);
    }

    private function approved(array $r): array
    {
        $this->actingAs($this->admin)->post('/agents/'.$r['agent_id'].'/approve', ['identity_reviewed' => 1])->assertRedirect();
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/agent/enrollment-status', ['receipt' => $r['receipt']])->assertOk()->json();
    }

    public function test_enrollment_is_single_use_and_stores_only_hash(): void
    {
        $code = $this->code();
        $payload = ['code' => $code, 'hostname' => 'lab', 'version' => '0.5.2', 'capabilities' => ['agent_info']];
        $pending = $this->postJson('/api/v1/agent/enroll', $payload)->assertStatus(202);
        $this->assertNull($pending->json('token'));
        $identity = $this->approved($pending->json());
        $this->postJson('/api/v1/agent/enroll', $payload)->assertConflict();
        $this->context()->apply(tenantId: $this->a->id);
        $agent = Agent::sole();
        $this->assertSame(hash('sha256', $identity['token']), $agent->token_hash);
        $this->assertStringNotContainsString($identity['token'], $agent->toJson());
        $this->assertNotNull(AgentEnrollment::sole()->consumed_at);
    }

    public function test_expired_wrong_and_disabled_tenant_enrollment_are_rejected(): void
    {
        $code = $this->code();
        $this->context()->apply(tenantId: $this->a->id);
        AgentEnrollment::query()->update(['expires_at' => now()->subSecond()]);
        $body = ['code' => $code, 'hostname' => 'lab', 'version' => '0.5.2'];
        $this->postJson('/api/v1/agent/enroll', $body)->assertStatus(410);
        $body['code'] = Str::uuid().'.'.str_repeat('a', 64);
        $this->postJson('/api/v1/agent/enroll', $body)->assertUnauthorized();
        $body['code'] = $this->code();
        $this->context()->apply(platform: true);
        $this->a->update(['status' => 'disabled']);
        $this->postJson('/api/v1/agent/enroll', $body)->assertForbidden();
    }

    public function test_all_eight_contracts_and_capability_freeze(): void
    {
        $identity = $this->enrolled();
        $headers = ['Authorization' => 'Bearer '.$identity['token']];
        $this->postJson('/api/v1/agent/heartbeat', ['version' => '0.5.2'], $headers)->assertOk()->assertJsonPath('policy.device_operations_frozen', true);
        $this->postJson('/api/v1/agent/capabilities', ['capabilities' => Protocol::CAPABILITIES], $headers)->assertOk();
        $this->postJson('/api/v1/agent/capabilities', ['capabilities' => ['device_future:v1']], $headers)->assertUnprocessable();
        $this->postJson('/api/v1/agent/claim', [], $headers)->assertOk()->assertJsonPath('task', null)->assertJsonPath('policy.tasks_enabled', false);
        $execution = ['task_id' => (string) Str::uuid(), 'execution_id' => (string) Str::uuid(), 'lease_token' => str_repeat('a', 32)];
        foreach (['start', 'renew', 'result'] as $endpoint) {
            $this->postJson('/api/v1/agent/'.$endpoint, $execution, $headers)->assertConflict()->assertJsonPath('error', 'no_active_task');
        }
        $this->postJson('/api/v1/agent/reconcile', ['executions' => [['task_id' => $execution['task_id'], 'execution_id' => $execution['execution_id'], 'state' => 'uncertain']]], $headers)->assertOk()->assertJsonPath('executions.0.disposition', 'hold');
        $this->context()->apply(tenantId: $this->a->id);
        $a = Agent::sole();
        $this->assertNotNull($a->last_heartbeat_at);
        $this->assertNotNull($a->last_poll_at);
    }

    public function test_agent_auth_rejects_invalid_token_revocation_and_tenant_injection(): void
    {
        $i = $this->enrolled();
        $h = ['Authorization' => 'Bearer '.$i['token']];
        $this->postJson('/api/v1/agent/heartbeat', ['version' => '0.5.2'])->assertUnauthorized();
        $this->postJson('/api/v1/agent/heartbeat', ['version' => '0.5.2'], ['Authorization' => 'Bearer '.str_repeat('-', 36).'.'.str_repeat('a', 64)])->assertUnauthorized();
        $this->postJson('/api/v1/agent/heartbeat', ['version' => '0.5.2', 'tenant_id' => $this->b->id], $h)->assertUnprocessable();
        $this->context()->apply(tenantId: $this->a->id);
        Agent::query()->update(['revoked_at' => now(), 'status' => 'revoked']);
        $this->postJson('/api/v1/agent/heartbeat', ['version' => '0.5.2'], $h)->assertForbidden();
    }

    public function test_rate_limits_origin_enrollment_and_authenticated_agent(): void
    {
        RateLimiter::increment('agent-origin:'.hash('sha256', '127.0.0.1'), 60, 1200);
        $this->postJson('/api/v1/agent/enroll', [])->assertStatus(429);
        Cache::flush();
        for ($n = 0; $n < 10; $n++) {
            $this->postJson('/api/v1/agent/enroll', [])->assertUnprocessable();
        }
        $this->postJson('/api/v1/agent/enroll', [])->assertStatus(429);
        Cache::flush();
        $i = $this->enrolled();
        for ($n = 0; $n < 120; $n++) {
            $this->postJson('/api/v1/agent/heartbeat', ['version' => '0.5.2'], ['Authorization' => 'Bearer '.$i['token']])->assertOk();
        }
        $this->postJson('/api/v1/agent/heartbeat', ['version' => '0.5.2'], ['Authorization' => 'Bearer '.$i['token']])->assertStatus(429);
    }

    public function test_ui_policy_and_agent_rls_never_cross_tenants(): void
    {
        $i = $this->enrolled();
        $this->actingAs($this->admin)->get('/agents')->assertOk()->assertSee('Lab agent');
        $this->get('/agents/not-a-uuid')->assertNotFound();
        $this->post('/agents/enrollments', ['name' => 'Second'])->assertOk()->assertSessionMissing('code');
        $this->flushSession();
        $this->actingAs($this->other)->get('/agents/'.$i['agent_id'])->assertNotFound();
        $this->context()->forUser($this->other);
        $this->assertSame(0, DB::table('agents')->count());
        $this->assertSame(0, DB::table('agent_enrollments')->count());
        $this->flushSession();
        $this->actingAs($this->viewer)->post('/agents/enrollments', ['name' => 'Denied'])->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->admin)->post('/agents/'.$i['agent_id'].'/revoke')->assertRedirect();
        $this->context()->apply(tenantId: $this->a->id);
        $this->assertSame(1, AuditEvent::where('event_type', 'agent.revoked')->count());
    }

    public function test_runtime_is_not_owner_and_cannot_change_schema(): void
    {
        $this->assertSame(0, (int) DB::selectOne("SELECT count(*) n FROM pg_tables WHERE schemaname='public' AND tableowner=current_user")->n);
        try {
            DB::transaction(fn () => DB::statement('CREATE TABLE forbidden_runtime_table(id int)'));
            $this->fail('DDL deveria ser negado.');
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->getCode());
        }
    }

    public function test_mtr_requires_authenticated_announcement_and_compatible_runtime(): void
    {
        $identity = $this->enrolled();
        $this->context()->apply(tenantId: $this->a->id);
        $a = Agent::findOrFail($identity['agent_id']);
        $this->assertFalse(Protocol::mtrAvailable($a));
        $headers = ['Authorization' => 'Bearer '.$identity['token']];
        $this->postJson('/api/v1/agent/heartbeat', ['version' => '0.6.2'], $headers)->assertOk();
        $this->context()->apply(tenantId: $this->a->id);
        $this->assertFalse(Protocol::mtrAvailable($a->fresh()));
        $this->postJson('/api/v1/agent/capabilities', ['task_protocol' => 1, 'capabilities' => ['agent_info', 'mtr_path_analysis:v1']], $headers)->assertOk();
        $this->context()->apply(tenantId: $this->a->id);
        $this->assertTrue(Protocol::mtrAvailable($a->fresh()));
        $this->postJson('/api/v1/agent/heartbeat', ['version' => '0.6.1'], $headers)->assertOk();
        $this->context()->apply(tenantId: $this->a->id);
        $this->assertFalse(Protocol::mtrAvailable($a->fresh()));
        $this->assertNotContains('mtr_path_analysis:v1', Protocol::executionCapabilities($a->fresh()));
        $this->postJson('/api/v1/agent/capabilities', ['task_protocol' => 1, 'capabilities' => ['agent_info']], $headers)->assertOk();
        $this->context()->apply(tenantId: $this->a->id);
        $this->assertNull($a->fresh()->metadata['mtr_capability_confirmed_at']);
    }
}
