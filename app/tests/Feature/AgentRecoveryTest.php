<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentOperation;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;

class AgentRecoveryTest extends IdentityTestCase
{
    private function held(): array
    {
        $this->context()->forUser($this->admin);
        $id = (string) Str::uuid();
        $token = $id.'.'.bin2hex(random_bytes(32));
        $agent = new Agent;
        $agent->forceFill(['id' => $id, 'tenant_id' => $this->a->id, 'name' => 'Recovery', 'hostname' => 'lab', 'version' => '0.6.2', 'status' => 'active', 'capabilities' => [], 'token_hash' => hash('sha256', $token), 'last_seen_ip' => '127.0.0.1'])->save();
        $operation = new AgentOperation;
        $operation->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'agent_id' => $id, 'requested_by' => $this->admin->id, 'kind' => 'update', 'status' => 'UNCERTAIN', 'from_version' => '0.6.2', 'reason' => 'maintenance', 'release' => ['version' => '0.6.2', 'sha256' => str_repeat('a', 64), 'lifecycle' => ['manifest' => 'signed']], 'error' => 'rollback_failed', 'evidence' => ['rollback' => false], 'finished_at' => now()])->save();

        return [$agent, $operation, ['Authorization' => 'Bearer '.$token]];
    }

    private function report($operation): array
    {
        return ['operation_id' => $operation->id, 'status' => 'FAILED', 'error' => 'interrupted', 'evidence' => ['recovery_verified' => true, 'backup' => $operation->id, 'version' => '0.6.2', 'sha256' => str_repeat('a', 64), 'postcheck' => true, 'freeze_preserved' => true]];
    }

    public function test_recovery_requires_admin_authorization_and_agent_verification_and_keeps_original(): void
    {
        [$agent, $operation, $headers] = $this->held();
        $claim = ['signed_release_protocol' => 1, 'component_protocol' => 1, 'recovery_protocol' => 1];
        $this->postJson('/api/v1/agent/operations/claim', $claim, $headers)->assertOk()->assertJsonPath('operation', null);
        $this->postJson('/api/v1/agent/operations/report', $this->report($operation), $headers)->assertStatus(409);
        $this->actingAs($this->operator)->post('/agents/'.$agent->id.'/operations/'.$operation->id.'/reconcile', ['recovery_reviewed' => '1'])->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->other)->post('/agents/'.$agent->id.'/operations/'.$operation->id.'/reconcile', ['recovery_reviewed' => '1'])->assertNotFound();
        $this->flushSession();
        $this->actingAs($this->admin)->post('/agents/'.$agent->id.'/operations/'.$operation->id.'/reconcile', ['recovery_reviewed' => '1'])->assertRedirect();
        $legacy = $claim;
        unset($legacy['recovery_protocol']);
        $this->postJson('/api/v1/agent/operations/claim', $legacy, $headers)->assertOk()->assertJsonPath('operation', null);
        $this->postJson('/api/v1/agent/operations/claim', $claim, $headers)->assertOk()->assertJsonPath('operation.phase', 'reconcile');
        $invalid = $this->report($operation);
        $invalid['evidence']['postcheck'] = false;
        $this->postJson('/api/v1/agent/operations/report', $invalid, $headers)->assertStatus(422);
        $report = $this->report($operation);
        $this->postJson('/api/v1/agent/operations/report', $report, $headers)->assertOk();
        $this->postJson('/api/v1/agent/operations/report', $report, $headers)->assertOk();
        $this->context()->forUser($this->admin);
        $operation->refresh();
        $this->assertSame('FAILED', $operation->status);
        $this->assertSame('rollback_failed', $operation->error);
        $this->assertSame('UNCERTAIN', $operation->evidence['original_failure']['status']);
        $this->assertFalse($operation->evidence['original_failure']['evidence']['rollback']);
    }

    public function test_expired_authorization_does_not_release_hold(): void
    {
        [$agent, $operation, $headers] = $this->held();
        $this->flushSession();
        $this->actingAs($this->admin)->post('/agents/'.$agent->id.'/operations/'.$operation->id.'/reconcile', ['recovery_reviewed' => '1'])->assertRedirect();
        $this->travel(16)->minutes();
        $this->postJson('/api/v1/agent/operations/claim', ['signed_release_protocol' => 1, 'component_protocol' => 1, 'recovery_protocol' => 1], $headers)->assertOk()->assertJsonPath('operation', null);
        $this->postJson('/api/v1/agent/operations/report', $this->report($operation), $headers)->assertStatus(409);
    }
}
