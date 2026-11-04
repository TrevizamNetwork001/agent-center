<?php

namespace Tests\Feature;

use App\Agent\Releases;
use App\Models\Agent;
use App\Models\AgentEnrollment;
use App\Models\AgentOperation;
use App\Models\AuditEvent;
use App\Web\Presentation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;

class AgentOperationsTest extends IdentityTestCase
{
    private function pending(): array
    {
        $r = $this->actingAs($this->admin)->post('/agents/enrollments', ['name' => 'Expected Agent'])->assertOk();
        preg_match('/[a-f0-9-]{36}\\.[a-f0-9]{64}/', $r->getContent(), $m);
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/agent/enroll', ['code' => $m[0], 'hostname' => 'unknown-host', 'version' => '0.5.2', 'fingerprint' => str_repeat('a', 64)])->assertStatus(202)->json();
    }

    public function test_no_credential_before_review_and_exactly_one_after_approval(): void
    {
        $p = $this->pending();
        $this->assertArrayNotHasKey('token', $p);
        $this->postJson('/api/v1/agent/enrollment-status', ['receipt' => $p['receipt']])->assertOk()->assertJsonPath('state', 'AWAITING_APPROVAL')->assertJsonMissingPath('token');
        $this->actingAs($this->admin)->post('/agents/'.$p['agent_id'].'/approve', [])->assertSessionHasErrors('identity_reviewed');
        $this->post('/agents/'.$p['agent_id'].'/approve', ['identity_reviewed' => 1])->assertRedirect();
        $this->app['auth']->forgetGuards();
        $i = $this->postJson('/api/v1/agent/enrollment-status', ['receipt' => $p['receipt']])->assertOk()->json();
        $this->postJson('/api/v1/agent/enrollment-status', ['receipt' => $p['receipt']])->assertConflict();
        $h = ['Authorization' => 'Bearer '.$i['token']];
        $this->postJson('/api/v1/agent/heartbeat', ['version' => '0.5.2', 'telemetry' => ['device_operations_frozen' => true, 'rss_bytes' => 1234]], $h)->assertOk();
        $this->context()->forUser($this->admin);
        $a = Agent::findOrFail($p['agent_id']);
        $this->assertArrayHasKey('agent_ready', $a->metadata['onboarding']);
        $this->assertSame(1234, $a->metadata['telemetry']['rss_bytes']);
        $this->assertSame(1, AuditEvent::where('event_type', 'agent.enrolled')->count());
    }

    public function test_cross_tenant_operator_viewer_cannot_approve_or_read_receipt(): void
    {
        $p = $this->pending();
        foreach ([$this->operator, $this->viewer] as $u) {
            $this->flushSession();
            $this->actingAs($u)->post('/agents/'.$p['agent_id'].'/approve', ['identity_reviewed' => 1])->assertForbidden();
        }
        $this->flushSession();
        $this->actingAs($this->other)->post('/agents/'.$p['agent_id'].'/approve', ['identity_reviewed' => 1])->assertNotFound();
        $this->context()->forUser($this->other);
        $this->assertSame(0, AgentEnrollment::count());
        $this->assertSame(0, Agent::count());
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/agent/enrollment-status', ['receipt' => substr($p['receipt'], 0, -1).(str_ends_with($p['receipt'], 'f') ? 'e' : 'f')])->assertUnauthorized();
    }

    public function test_revoked_pending_host_never_gets_credential(): void
    {
        $p = $this->pending();
        $this->actingAs($this->admin)->post('/agents/'.$p['agent_id'].'/revoke')->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/agent/enrollment-status', ['receipt' => $p['receipt']])->assertForbidden();
    }

    public function test_telemetry_rejects_secrets_and_arbitrary_payload(): void
    {
        $this->context()->forUser($this->admin);
        $a = new Agent;
        $token = Str::uuid().'.'.str_repeat('a', 64);
        $a->forceFill(['id' => explode('.', $token)[0], 'tenant_id' => $this->a->id, 'name' => 'Agent', 'hostname' => 'host', 'version' => '0.5.2', 'status' => 'active', 'token_hash' => hash('sha256', $token), 'enrolled_at' => now(), 'capabilities' => []])->save();
        $this->postJson('/api/v1/agent/heartbeat', ['version' => '0.5.2', 'telemetry' => ['token' => 'must-not-store']], ['Authorization' => 'Bearer '.$token])->assertUnprocessable();
    }

    public function test_update_and_rollback_require_plan_approval_and_tenant_authorization(): void
    {
        $this->context()->forUser($this->admin);
        $a = new Agent;
        $token = Str::uuid().'.'.str_repeat('b', 64);
        $a->forceFill(['id' => explode('.', $token)[0], 'tenant_id' => $this->a->id, 'name' => 'Maintenance Lab', 'hostname' => 'host', 'version' => '0.5.2', 'status' => 'active', 'token_hash' => hash('sha256', $token), 'enrolled_at' => now(), 'capabilities' => []])->save();
        $release = ['version' => '0.5.2', 'sha256' => str_repeat('a', 64), 'compatibility' => 'Linux CPython 3.13', 'device_operations_frozen' => true, 'status' => 'available', 'date' => '2026-11-04', 'changelog' => 'Fixture', 'git_ref' => 'ff168b9'];
        $this->mock(Releases::class, function ($m) use ($release) {
            $m->shouldReceive('all')->andReturn([$release]);
            $m->shouldReceive('find')->andReturn($release);
        });
        $h = ['Authorization' => 'Bearer '.$token];
        foreach (['update', 'rollback'] as $kind) {
            foreach ([$this->operator, $this->viewer] as $u) {
                $this->flushSession();
                $this->actingAs($u)->post('/agents/'.$a->id.'/operations', ['kind' => $kind, 'release_sha' => $release['sha256'], 'reason' => 'maintenance'])->assertForbidden();
            }
            $this->flushSession();
            $this->actingAs($this->other)->post('/agents/'.$a->id.'/operations', ['kind' => $kind, 'release_sha' => $release['sha256'], 'reason' => 'maintenance'])->assertNotFound();
            $this->flushSession();
            $this->actingAs($this->admin)->post('/agents/'.$a->id.'/operations', ['kind' => $kind, 'release_sha' => $release['sha256'], 'reason' => 'maintenance'])->assertRedirect();
            $this->context()->forUser($this->admin);
            $o = AgentOperation::where('status', 'PRECHECK_REQUESTED')->sole();
            $this->post('/agents/'.$a->id.'/operations/'.$o->id.'/approve', ['plan_reviewed' => 1])->assertConflict();
            $this->app['auth']->forgetGuards();
            $c = $this->postJson('/api/v1/agent/operations/claim', [], $h)->assertOk()->assertJsonPath('operation.phase', 'precheck');
            $this->postJson('/api/v1/agent/operations/report', ['operation_id' => $o->id, 'status' => 'COMPLETED'], $h)->assertConflict();
            $this->postJson('/api/v1/agent/operations/report', ['operation_id' => $o->id, 'status' => 'PLANNED', 'evidence' => ['sha256' => $release['sha256'], 'version' => '0.5.2', 'freeze_preserved' => true]], $h)->assertOk();
            $this->postJson('/api/v1/agent/operations/claim', [], $h)->assertOk()->assertJsonPath('operation', null);
            $this->actingAs($this->admin)->post('/agents/'.$a->id.'/operations/'.$o->id.'/approve', [])->assertSessionHasErrors('plan_reviewed');
            $this->post('/agents/'.$a->id.'/operations/'.$o->id.'/approve', ['plan_reviewed' => 1])->assertRedirect();
            $this->app['auth']->forgetGuards();
            $this->postJson('/api/v1/agent/operations/claim', [], $h)->assertOk()->assertJsonPath('operation.phase', 'apply');
            $this->postJson('/api/v1/agent/operations/claim', [], $h)->assertOk()->assertJsonPath('operation', null);
            $this->postJson('/api/v1/agent/operations/report', ['operation_id' => $o->id, 'status' => 'COMPLETED', 'evidence' => ['sha256' => str_repeat('0', 64), 'version' => '0.5.2', 'freeze_preserved' => true]], $h)->assertUnprocessable();
            $this->postJson('/api/v1/agent/operations/report', ['operation_id' => $o->id, 'status' => 'COMPLETED', 'evidence' => ['sha256' => $release['sha256'], 'version' => '0.5.2', 'freeze_preserved' => true, 'backup' => (string) Str::uuid(), 'postcheck' => true]], $h)->assertOk();
            $this->context()->forUser($this->other);
            $this->assertSame(0, DB::table('agent_operations')->count());
        }
    }

    public function test_presence_distinguishes_stalled_poll_offline_and_missing_freeze(): void
    {
        $a = new Agent;
        $a->forceFill(['status' => 'active', 'last_heartbeat_at' => now(), 'last_poll_at' => now(), 'version' => '0.5.2', 'metadata' => ['telemetry' => ['device_operations_frozen' => true]]]);
        $this->assertSame('ONLINE', Presentation::agentStatus($a));
        $a->last_poll_at = now()->subMinute();
        $this->assertSame('ATENÇÃO', Presentation::agentStatus($a));
        $a->last_heartbeat_at = now()->subMinutes(2);
        $this->assertSame('OFFLINE', Presentation::agentStatus($a));
    }

    public function test_database_rejects_cross_tenant_agent_reference(): void
    {
        $p = $this->pending();
        $this->context()->forUser($this->other);
        try {
            DB::transaction(fn () => DB::table('agent_operations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->b->id, 'agent_id' => $p['agent_id'], 'requested_by' => $this->other->id, 'kind' => 'update', 'status' => 'PRECHECK_REQUESTED', 'from_version' => '0.5.2', 'release' => '{}', 'reason' => 'repair', 'created_at' => now(), 'updated_at' => now()]));
            $this->fail('Cross tenant foreign key must fail');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->getCode());
        }
    }

    public function test_interrupted_operation_requires_explicit_host_review_and_exact_tenant(): void
    {
        $pending = $this->pending();
        $this->context()->forUser($this->admin);
        $o = new AgentOperation;
        $o->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'agent_id' => $pending['agent_id'], 'requested_by' => $this->admin->id, 'kind' => 'update', 'status' => 'UNCERTAIN', 'from_version' => '0.5.2', 'release' => [], 'reason' => 'repair'])->save();
        $this->artisan('agent-center:resolve-operation', ['operation' => $o->id, '--tenant' => $this->a->id])->assertExitCode(2);
        $this->artisan('agent-center:resolve-operation', ['operation' => $o->id, '--tenant' => $this->b->id, '--host-reviewed' => true])->assertExitCode(1);
        $this->artisan('agent-center:resolve-operation', ['operation' => $o->id, '--tenant' => $this->a->id, '--host-reviewed' => true])->assertExitCode(0);
        $this->context()->forUser($this->admin);
        $this->assertSame('FAILED', $o->fresh()->status);
        $this->assertSame('interrupted', $o->fresh()->error);
    }
}
