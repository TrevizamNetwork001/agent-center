<?php

namespace Tests\Feature;

use App\Agent\ReleaseQuarantine;
use App\Models\Agent;
use App\Models\AgentOperation;
use App\Models\AgentRelease;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;

class PanelReleaseTransitionTest extends IdentityTestCase
{
    private Agent $agent;

    private array $bridge;

    private array $target;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context()->forUser($this->admin);
        $this->bridge = $this->release(7, 'bootstrap-python');
        $this->target = $this->release(8, 'ssh-runtime', ['runtime_package_protocol' => 2, 'bootstrap_sha256' => $this->bridge['sha256']]);
        $id = (string) Str::uuid();
        $token = $id.'.'.bin2hex(random_bytes(32));
        $this->agent = (new Agent)->forceFill(['id' => $id, 'tenant_id' => $this->a->id, 'name' => 'Panel lifecycle', 'hostname' => 'lab', 'status' => 'active', 'version' => '0.6.5', 'capabilities' => [], 'last_seen_ip' => '127.0.0.1', 'token_hash' => hash('sha256', $token), 'metadata' => ['lifecycle_release_sequence' => 6, 'updater' => ['version' => '0.6.5', 'signed_release_protocol' => 1]]]);
        $this->agent->save();
        $this->headers = ['Authorization' => 'Bearer '.$token];
    }

    private function release(int $sequence, string $bytes, array $extra = []): array
    {
        $pair = sodium_crypto_sign_keypair();
        $manifest = json_encode(['schema_version' => 1, 'sequence' => $sequence, 'version' => '0.6.6', 'platform' => 'debian13', 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes), ...$extra], JSON_THROW_ON_ERROR);
        $release = (new AgentRelease)->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => app(TenantContext::class)->tenantId, 'published_by' => app(TenantContext::class)->userId, 'version' => '0.6.6', 'platform' => 'debian13', 'sequence' => $sequence, 'sha256' => hash('sha256', $bytes), 'manifest' => $manifest, 'signature' => base64_encode(sodium_crypto_sign_detached($manifest, sodium_crypto_sign_secretkey($pair))), 'artifact_base64' => base64_encode($bytes), 'published_at' => now()]);
        $release->save();

        return $release->descriptor();
    }

    private function requestUpdate(): void
    {
        $this->actingAs($this->admin)->post('/agents/'.$this->agent->id.'/operations', ['kind' => 'update', 'reason' => 'maintenance', 'release_sha' => $this->target['sha256'], 'install_after_check' => '1'])->assertRedirect();
        $this->context()->forUser($this->admin);
    }

    private function claim(int $protocol = 1): ?array
    {
        return $this->postJson('/api/v1/agent/operations/claim', ['updater_version' => $protocol === 1 ? '0.6.5' : '0.6.6', 'release_download_protocol' => 1, 'signed_release_protocol' => 1, 'component_protocol' => 1, 'runtime_package_protocol' => $protocol], $this->headers)->assertOk()->json('operation');
    }

    private function report(array $operation, string $status, array $extra = []): array
    {
        $data = ['operation_id' => $operation['id'], 'status' => $status, 'evidence' => ['sha256' => $operation['release']['sha256'], 'version' => '0.6.6', 'freeze_preserved' => true, ...$extra]];
        $this->postJson('/api/v1/agent/operations/report', $data, $this->headers)->assertOk();

        return $data;
    }

    private function completeBridge(): array
    {
        $this->requestUpdate();
        $operation = $this->claim();
        $this->assertSame($this->bridge['sha256'], $operation['release']['sha256']);
        $this->report($operation, 'PLANNED');
        $operation = $this->claim();
        $this->assertSame('apply', $operation['phase']);
        $this->context()->forUser($this->admin);
        $this->agent->forceFill(['version' => '0.6.6'])->save();

        return $this->report($operation, 'COMPLETED', ['backup' => (string) Str::uuid(), 'postcheck' => true]);
    }

    public function test_one_panel_request_chains_signed_releases_and_waits_for_companion_protocol(): void
    {
        $bridgeReport = $this->completeBridge();
        $this->postJson('/api/v1/agent/operations/report', $bridgeReport, $this->headers)->assertOk();
        $this->assertNull($this->claim());
        $operation = $this->claim(2);
        $this->assertSame($this->target['sha256'], $operation['release']['sha256']);
        $this->assertSame('precheck', $operation['phase']);
        $this->report($operation, 'PLANNED');
        $operation = $this->claim(2);
        $this->assertSame('apply', $operation['phase']);
        $final = $this->report($operation, 'COMPLETED', ['backup' => (string) Str::uuid(), 'postcheck' => true]);
        $this->postJson('/api/v1/agent/operations/report', $final, $this->headers)->assertOk();
        $this->context()->forUser($this->admin);
        $this->assertSame(2, AgentOperation::count());
        $this->assertSame(2, AgentOperation::where('status', 'COMPLETED')->count());
        $this->assertSame(8, $this->agent->fresh()->metadata['lifecycle_release_sequence']);
    }

    public function test_revoked_authorizer_does_not_authorize_second_install(): void
    {
        $this->requestUpdate();
        $operation = $this->claim();
        $this->report($operation, 'PLANNED');
        $operation = $this->claim();
        $this->context()->forUser($this->admin);
        $this->admin->forceFill(['status' => 'disabled'])->save();
        $this->agent->forceFill(['version' => '0.6.6'])->save();
        $this->report($operation, 'COMPLETED', ['backup' => (string) Str::uuid(), 'postcheck' => true]);
        $operation = $this->claim(2);
        $this->report($operation, 'PLANNED');
        $this->assertNull($this->claim(2));
        $this->context()->apply(tenantId: $this->a->id, userId: $this->admin->id);
        $child = AgentOperation::where('release->sha256', $this->target['sha256'])->sole();
        $this->assertSame('PLANNED', $child->status);
        $this->assertNull($child->approved_by);
    }

    public function test_agent_cannot_forge_server_transition_evidence(): void
    {
        $this->requestUpdate();
        $operation = $this->claim();
        $this->postJson('/api/v1/agent/operations/report', ['operation_id' => $operation['id'], 'status' => 'PLANNED', 'evidence' => ['transition' => ['target' => $this->target]]], $this->headers)->assertStatus(422);
    }

    public function test_cross_tenant_cannot_request_or_read_transition(): void
    {
        $this->actingAs($this->other)->post('/agents/'.$this->agent->id.'/operations', ['kind' => 'update', 'reason' => 'maintenance', 'release_sha' => $this->target['sha256'], 'install_after_check' => '1'])->assertNotFound();
        $this->context()->forUser($this->other);
        $this->assertSame(0, AgentRelease::count());
        $this->assertSame(0, AgentOperation::count());
        $this->assertNull(Agent::withoutGlobalScopes()->find($this->agent->id));
    }

    public function test_missing_or_quarantined_bridge_cannot_start_a_transition(): void
    {
        app(ReleaseQuarantine::class)->apply(AgentRelease::where('sha256', $this->bridge['sha256'])->sole(), $this->admin);
        $request = ['kind' => 'update', 'reason' => 'maintenance', 'release_sha' => $this->target['sha256'], 'install_after_check' => '1'];
        $this->actingAs($this->admin)->post('/agents/'.$this->agent->id.'/operations', $request)->assertStatus(409);
        $this->context()->forUser($this->admin);
        $this->assertSame(0, AgentOperation::count());
        $target = $this->release(9, 'no matching bridge', ['runtime_package_protocol' => 2, 'bootstrap_sha256' => str_repeat('f', 64)]);
        $request['release_sha'] = $target['sha256'];
        $this->post('/agents/'.$this->agent->id.'/operations', $request)->assertStatus(409);
        $this->context()->forUser($this->admin);
        $this->assertSame(0, AgentOperation::count());
    }

    public function test_target_quarantine_during_bridge_prevents_second_install(): void
    {
        $this->requestUpdate();
        $operation = $this->claim();
        $this->report($operation, 'PLANNED');
        $operation = $this->claim();
        $this->context()->forUser($this->admin);
        app(ReleaseQuarantine::class)->apply(AgentRelease::where('sha256', $this->target['sha256'])->sole(), $this->admin);
        $this->agent->forceFill(['version' => '0.6.6'])->save();
        $this->report($operation, 'COMPLETED', ['backup' => (string) Str::uuid(), 'postcheck' => true]);
        $this->assertNull($this->claim(2));
        $this->context()->forUser($this->admin);
        $child = AgentOperation::where('release->sha256', $this->target['sha256'])->sole();
        $this->assertSame('FAILED', $child->status);
        $this->assertSame('invalid_release', $child->error);
        $this->assertNull($child->approved_by);
    }

    public function test_bridge_failure_never_schedules_the_target(): void
    {
        $this->requestUpdate();
        $operation = $this->claim();
        $this->postJson('/api/v1/agent/operations/report', ['operation_id' => $operation['id'], 'status' => 'FAILED', 'error' => 'precheck_failed'], $this->headers)->assertOk();
        $this->assertNull($this->claim(2));
        $this->context()->forUser($this->admin);
        $this->assertSame(1, AgentOperation::count());
    }

    public function test_missing_companion_announcement_expires_instead_of_waiting_forever(): void
    {
        $this->completeBridge();
        $this->context()->forUser($this->admin);
        AgentOperation::where('release->sha256', $this->target['sha256'])->update(['created_at' => now()->subMinutes(6)]);
        $this->assertNull($this->claim());
        $this->context()->forUser($this->admin);
        $child = AgentOperation::where('release->sha256', $this->target['sha256'])->sole();
        $this->assertSame('FAILED', $child->status);
        $this->assertSame('incompatible', $child->error);
    }
}
