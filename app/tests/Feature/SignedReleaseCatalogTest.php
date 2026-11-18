<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentOperation;
use App\Models\AgentRelease;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;

class SignedReleaseCatalogTest extends IdentityTestCase
{
    private function publication(string $version = '0.6.2', ?array $components = null): array
    {
        $pair = sodium_crypto_sign_keypair();
        config(['agent_releases.public_key' => base64_encode(sodium_crypto_sign_publickey($pair))]);
        $bytes = 'signed artifact fixture';
        $document = ['schema_version' => 1, 'sequence' => 1, 'version' => $version, 'platform' => 'debian13', 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
        if ($components !== null) {
            $document['components'] = $components;
        }
        $manifest = json_encode($document, JSON_THROW_ON_ERROR);

        return ['manifest' => $manifest, 'signature' => base64_encode(sodium_crypto_sign_detached($manifest, sodium_crypto_sign_secretkey($pair))), 'artifact' => UploadedFile::fake()->createWithContent('agent.pyz', $bytes)];
    }

    public function test_admin_publishes_verified_immutable_tenant_release(): void
    {
        $this->actingAs($this->admin)->post('/agents/releases', $this->publication())->assertRedirect('/agents/releases');
        $this->context()->forUser($this->admin);
        $release = AgentRelease::sole();
        $this->assertSame('0.6.2', $release->descriptor()['version']);
        $this->assertArrayNotHasKey('artifact_base64', $release->toArray());
        $this->get('/agents/releases')->assertOk()->assertSee($release->sha256);
        $this->context()->forUser($this->other);
        $this->assertSame(0, AgentRelease::count());
    }

    public function test_064_refuses_legacy_companion_at_request_and_claim(): void
    {
        $this->actingAs($this->admin)->post('/agents/releases', $this->publication('0.6.4'))->assertRedirect();
        $this->context()->forUser($this->admin);
        $release = AgentRelease::sole();
        $id = (string) Str::uuid();
        $token = $id.'.'.bin2hex(random_bytes(32));
        $agent = new Agent;
        $agent->forceFill(['id' => $id, 'tenant_id' => $this->a->id, 'capabilities' => [], 'name' => 'Transition', 'hostname' => 'lab', 'version' => '0.6.2', 'status' => 'active', 'last_seen_ip' => '127.0.0.1', 'token_hash' => hash('sha256', $token), 'metadata' => ['updater' => ['version' => '0.6.2', 'signed_release_protocol' => 1]]])->save();
        $request = ['kind' => 'update', 'reason' => 'maintenance', 'release_sha' => $release->sha256, 'install_after_check' => '1'];
        $this->post('/agents/'.$id.'/operations', $request)->assertStatus(409);
        $this->context()->forUser($this->admin);
        $agent->forceFill(['metadata' => ['updater' => ['version' => '0.6.4', 'signed_release_protocol' => 1]]])->save();
        $this->post('/agents/'.$id.'/operations', $request)->assertRedirect();
        $headers = ['Authorization' => 'Bearer '.$token];
        $claim = ['updater_version' => '0.6.2', 'signed_release_protocol' => 1];
        $this->postJson('/api/v1/agent/operations/claim', $claim, $headers)->assertOk()->assertJsonPath('operation', null);
        $claim['updater_version'] = '0.6.4';
        $this->postJson('/api/v1/agent/operations/claim', $claim, $headers)->assertOk()->assertJsonPath('operation.phase', 'precheck');
    }

    public function test_component_protocol_and_simulation_are_gated(): void
    {
        $components = [['id' => 'mtr', 'version' => '0.6.2', 'profile' => 'network-raw-helper', 'dependencies' => ['python3.13', 'mtr0.95'], 'helper_sha256' => str_repeat('a', 64)]];
        $this->actingAs($this->admin)->post('/agents/releases', $this->publication('0.6.2', $components))->assertRedirect();
        $this->context()->forUser($this->admin);
        $release = AgentRelease::sole();
        $id = (string) Str::uuid();
        $token = $id.'.'.bin2hex(random_bytes(32));
        $agent = new Agent;
        $agent->forceFill(['id' => $id, 'tenant_id' => $this->a->id, 'capabilities' => [], 'name' => 'Components', 'hostname' => 'lab', 'version' => '0.6.1', 'status' => 'active', 'token_hash' => hash('sha256', $token), 'last_seen_ip' => '127.0.0.1', 'metadata' => ['updater' => ['signed_release_protocol' => 1]]])->save();
        $request = ['kind' => 'update', 'reason' => 'maintenance', 'release_sha' => $release->sha256, 'install_after_check' => '1'];
        $this->post('/agents/'.$id.'/operations', $request)->assertStatus(409);
        $headers = ['Authorization' => 'Bearer '.$token];
        $claim = ['updater_version' => '0.6.2', 'release_download_protocol' => 1, 'signed_release_protocol' => 1, 'component_protocol' => 1];
        $this->postJson('/api/v1/agent/operations/claim', $claim, $headers)->assertOk();
        $this->post('/agents/'.$id.'/operations', $request)->assertRedirect();
        $operation = $this->postJson('/api/v1/agent/operations/claim', $claim, $headers)->assertOk()->json('operation');
        $evidence = ['sha256' => $release->sha256, 'version' => '0.6.2', 'freeze_preserved' => true];
        $this->postJson('/api/v1/agent/operations/report', ['operation_id' => $operation['id'], 'status' => 'PLANNED', 'evidence' => $evidence], $headers)->assertOk();
        $this->postJson('/api/v1/agent/operations/claim', $claim, $headers)->assertOk()->assertJsonPath('operation.phase', 'apply');
        $observed = [['id' => 'mtr', 'version' => '0.6.2', 'profile' => 'network-raw-helper', 'status' => 'installed', 'operational_verified' => false]];
        $report = ['operation_id' => $operation['id'], 'status' => 'COMPLETED', 'evidence' => [...$evidence, 'backup' => (string) Str::uuid(), 'postcheck' => true, 'components' => $observed]];
        config(['agent_releases.allow_component_simulation' => true]);
        app()->detectEnvironment(fn () => 'production');
        try {
            $this->postJson('/api/v1/agent/operations/report', $report, $headers)->assertStatus(422);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
        $this->postJson('/api/v1/agent/operations/report', $report, $headers)->assertOk();
        $this->context()->forUser($this->admin);
        $this->assertSame('COMPLETED', AgentOperation::whereKey($operation['id'])->sole()->status);
        $this->assertFalse($agent->fresh()->metadata['lifecycle_components'][0]['operational_verified']);
    }

    public function test_unknown_component_privilege_is_rejected_even_when_signed(): void
    {
        $components = [['id' => 'mtr', 'version' => '0.6.2', 'profile' => 'unrestricted-root', 'dependencies' => ['python3.13', 'mtr0.95'], 'helper_sha256' => str_repeat('a', 64)]];
        $this->actingAs($this->admin)->post('/agents/releases', $this->publication('0.6.2', $components))->assertStatus(422);
        $this->context()->forUser($this->admin);
        $this->assertSame(0, AgentRelease::count());
    }

    public function test_signed_but_unsupported_version_is_rejected(): void
    {
        $this->actingAs($this->admin)->post('/agents/releases', $this->publication('99.0.0'))->assertStatus(422);
        $this->context()->forUser($this->admin);
        $this->assertSame(0, AgentRelease::count());
    }

    public function test_operator_cannot_publish_and_bad_signature_is_rejected(): void
    {
        $this->actingAs($this->operator)->post('/agents/releases', $this->publication())->assertForbidden();
        $this->flushSession();
        $data = $this->publication();
        $data['manifest'] = str_replace('0.6.2', '0.6.3', $data['manifest']);
        $this->actingAs($this->admin)->post('/agents/releases', $data)->assertStatus(422);
        $this->context()->forUser($this->admin);
        $this->assertSame(0, AgentRelease::count());
    }
}
