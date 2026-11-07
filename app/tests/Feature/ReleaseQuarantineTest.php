<?php

namespace Tests\Feature;

use App\Agent\ReleaseQuarantine;
use App\Agent\Releases;
use App\Models\Agent;
use App\Models\AgentOperation;
use App\Models\AgentRelease;
use App\Models\AgentReleaseQuarantine;
use App\Models\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\IdentityTestCase;

class ReleaseQuarantineTest extends IdentityTestCase
{
    private function release(int $sequence): AgentRelease
    {
        $this->context()->forUser($this->admin);
        $r = new AgentRelease;
        $bytes = 'quarantine-fixture-'.$sequence;
        $r->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'published_by' => $this->admin->id,
            'version' => '0.6.2', 'platform' => 'debian13', 'sequence' => $sequence, 'sha256' => hash('sha256', $bytes),
            'manifest' => json_encode(['components' => []]), 'signature' => 'fixture', 'artifact_base64' => base64_encode($bytes), 'published_at' => now()])->save();

        return $r;
    }

    private function agent(): array
    {
        $id = (string) Str::uuid();
        $token = $id.'.'.bin2hex(random_bytes(32));
        $a = new Agent;
        $a->forceFill(['id' => $id, 'tenant_id' => $this->a->id, 'name' => 'Quarantine test', 'hostname' => 'lab',
            'version' => '0.6.2', 'status' => 'active', 'capabilities' => [], 'last_seen_ip' => '127.0.0.1',
            'token_hash' => hash('sha256', $token), 'metadata' => ['updater' => ['signed_release_protocol' => 1, 'component_protocol' => 1]]])->save();

        return [$a, ['Authorization' => 'Bearer '.$token]];
    }

    private function operation(Agent $a, AgentRelease $r, string $status): AgentOperation
    {
        $o = new AgentOperation;
        $o->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'agent_id' => $a->id,
            'requested_by' => $this->admin->id, 'kind' => 'update', 'reason' => 'maintenance', 'status' => $status,
            'from_version' => '0.6.2', 'release' => $r->descriptor(), 'approved_by' => $this->admin->id, 'approved_at' => now()])->save();

        return $o;
    }

    public function test_quarantine_preserves_release_and_history_and_is_idempotent(): void
    {
        $valid = $this->release(2);
        $bad = $this->release(3);
        [$a] = $this->agent();
        $old = $this->operation($a, $bad, 'FAILED');
        $before = $bad->fresh()->getRawOriginal();
        $q = app(ReleaseQuarantine::class)->apply($bad, $this->admin);
        $this->assertSame($q->id, app(ReleaseQuarantine::class)->apply($bad, $this->admin)->id);
        $this->assertSame($before, $bad->fresh()->getRawOriginal());
        $this->assertSame('FAILED', $old->fresh()->status);
        $this->assertSame(1, AuditEvent::where('event_type', 'agent.release_quarantined')->count());
        $this->assertContains($valid->sha256, array_column(app(Releases::class)->all(), 'sha256'));
        $this->assertNotContains($bad->sha256, array_column(app(Releases::class)->all(), 'sha256'));
        $this->actingAs($this->admin)->get('/agents/releases')->assertOk()->assertSee('QUARANTINED')->assertSee($bad->sha256);
    }

    public function test_cached_operations_cannot_approve_claim_download_or_report_success(): void
    {
        $bad = $this->release(3);
        [$a, $headers] = $this->agent();
        app(ReleaseQuarantine::class)->apply($bad, $this->admin);
        $this->actingAs($this->admin)->post('/agents/'.$a->id.'/operations', ['kind' => 'update', 'reason' => 'maintenance', 'release_sha' => $bad->sha256, 'install_after_check' => '1'])->assertStatus(422);
        $this->context()->forUser($this->admin);
        $this->assertSame(0, AgentOperation::count());
        // A stale/preexisting operation must fail even without using Releases::find.
        $o = $this->operation($a, $bad, 'PLANNED');
        $this->post('/agents/'.$a->id.'/operations/'.$o->id.'/approve', ['plan_reviewed' => '1'])->assertStatus(409);
        $this->context()->forUser($this->admin);
        $o->forceFill(['status' => 'APPROVED'])->save();
        $this->postJson('/api/v1/agent/operations/claim', ['updater_version' => '0.6.2', 'signed_release_protocol' => 1, 'component_protocol' => 1], $headers)->assertOk()->assertJsonPath('operation', null);
        $this->getJson('/api/v1/agent/operations/'.$o->id.'/artifact', $headers)->assertStatus(409);
        $this->context()->forUser($this->admin);
        $o->forceFill(['status' => 'RUNNING'])->save();
        $this->postJson('/api/v1/agent/operations/report', ['operation_id' => $o->id, 'status' => 'COMPLETED', 'evidence' => ['sha256' => $bad->sha256, 'version' => '0.6.2', 'backup' => (string) Str::uuid(), 'postcheck' => true, 'freeze_preserved' => true]], $headers)->assertStatus(409);
    }

    public function test_operator_and_other_tenant_cannot_quarantine_and_rls_hides_records(): void
    {
        $bad = $this->release(3);
        try {
            app(ReleaseQuarantine::class)->apply($bad, $this->operator);
            $this->fail('Operator allowed');
        } catch (AuthorizationException) {
            $this->assertSame(0, AgentReleaseQuarantine::count());
        }
        app(ReleaseQuarantine::class)->apply($bad, $this->admin);
        $this->context()->forUser($this->other);
        $this->assertSame(0, AgentReleaseQuarantine::count());
        $this->assertSame(0, DB::table('agent_release_quarantines')->count());
        try {
            app(ReleaseQuarantine::class)->apply($bad, $this->other);
            $this->fail('Cross-tenant quarantine allowed');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_quarantine_refuses_active_operations(): void
    {
        $bad = $this->release(3);
        [$a] = $this->agent();
        $this->operation($a, $bad, 'APPROVED');
        $this->expectException(HttpException::class);
        app(ReleaseQuarantine::class)->apply($bad, $this->admin);
    }

    public function test_valid_release_artifact_and_claim_remain_available(): void
    {
        $valid = $this->release(2);
        $bad = $this->release(3);
        [$a, $headers] = $this->agent();
        app(ReleaseQuarantine::class)->apply($bad, $this->admin);
        $o = $this->operation($a, $valid, 'APPROVED');
        $this->getJson('/api/v1/agent/operations/'.$o->id.'/artifact', $headers)->assertOk()->assertContent('quarantine-fixture-2');
        $this->postJson('/api/v1/agent/operations/claim', ['updater_version' => '0.6.2', 'signed_release_protocol' => 1, 'component_protocol' => 1], $headers)->assertOk()->assertJsonPath('operation.id', $o->id)->assertJsonPath('operation.phase', 'apply');
    }

    public function test_quarantine_is_immutable_in_postgresql(): void
    {
        $bad = $this->release(3);
        $q = app(ReleaseQuarantine::class)->apply($bad, $this->admin);
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('agent_release_quarantines')->where('id', $q->id)->delete());
    }
}
