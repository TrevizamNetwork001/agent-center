<?php

namespace Tests\Feature;

use App\Agent\MaintenanceSsh;
use App\Models\Agent;
use App\Models\AgentMaintenanceAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;

class AgentMaintenanceTest extends IdentityTestCase
{
    private function agent(string $name = 'teste'): Agent
    {
        $this->context()->forUser($this->admin);
        $a = new Agent;
        $a->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'name' => $name, 'hostname' => 'teste', 'version' => '0.6.1', 'status' => 'active',
            'capabilities' => [], 'metadata' => [], 'token_hash' => hash('sha256', random_bytes(32))])->save();
        config(['agent_maintenance.targets' => [$a->id => ['192.0.2.10']], 'agent_maintenance.host_fingerprints' => [$a->id => ['192.0.2.10:22' => 'SHA256:'.str_repeat('a', 43)]]]);

        return $a;
    }

    private function data(): array
    {
        return ['host' => '192.0.2.10', 'port' => 22, 'username' => 'root', 'password' => ' fixture ssh password ', 'revision' => 0];
    }

    public function test_admin_can_store_encrypted_temporary_password_and_remove_it(): void
    {
        $a = $this->agent();
        $data = $this->data();
        $password = $data['password'];
        $this->actingAs($this->admin)->put('/agents/'.$a->id.'/maintenance', $data)->assertRedirect();
        $this->context()->forUser($this->admin);
        $access = AgentMaintenanceAccess::sole();
        $this->assertSame($password, $access->password);
        $this->assertNull($access->private_key);
        $this->assertStringNotContainsString($password, $access->getRawOriginal('password'));
        $this->assertArrayNotHasKey('password', $access->toArray());
        $this->assertTrue($access->expires_at->between(now()->addMinutes(59), now()->addMinutes(61)));
        $this->get('/agents/'.$a->id.'/maintenance')->assertOk()->assertSee('Testar conexão')->assertDontSee($password, false)->assertSee('name="password"', false)->assertDontSee('name="host_fingerprint"', false)->assertDontSee('name="private_key"', false)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString($password, json_encode(DB::table('audit_events')->get()));
        $this->delete('/agents/'.$a->id.'/maintenance')->assertRedirect();
        $this->context()->forUser($this->admin);
        $this->assertSame(0, AgentMaintenanceAccess::count());
    }

    public function test_permission_and_tenant_isolation(): void
    {
        $a = $this->agent();
        $url = '/agents/'.$a->id.'/maintenance';
        $this->actingAs($this->operator)->get($url)->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->viewer)->put($url, $this->data())->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->other)->get($url)->assertNotFound();
        $this->flushSession();
        $this->actingAs($this->admin)->put($url, $this->data())->assertRedirect();
        $this->context()->forUser($this->other);
        $this->assertSame(0, AgentMaintenanceAccess::count());
        $this->assertSame(0, DB::table('agent_maintenance_accesses')->count());
    }

    public function test_default_disabled_and_invalid_destinations_fail_before_connection(): void
    {
        $a = $this->agent();
        config(['agent_maintenance.targets' => []]);
        $this->actingAs($this->admin)->put('/agents/'.$a->id.'/maintenance', $this->data())->assertForbidden();
        $ssh = new MaintenanceSsh;
        foreach (['127.0.0.1', '169.254.169.254', '::1', '::ffff:127.0.0.1', '192.0.2.11'] as $ip) {
            config(['agent_maintenance.targets' => [$a->id => [$ip]]]);
            if ($ip === '192.0.2.11') {
                config(['agent_maintenance.targets' => [$a->id => ['192.0.2.10']], 'agent_maintenance.host_fingerprints' => [$a->id => ['192.0.2.10:22' => 'SHA256:'.str_repeat('a', 43)]]]);
            }
            $this->assertFalse($ssh->targetAllowed($a, $ip));
        }
    }

    public function test_other_agents_and_revoked_agents_are_blocked(): void
    {
        $a = $this->agent('other-host');
        $this->actingAs($this->admin)->put('/agents/'.$a->id.'/maintenance', $this->data())->assertForbidden();
        $a->forceFill(['name' => 'teste', 'revoked_at' => now()])->save();
        $this->put('/agents/'.$a->id.'/maintenance', $this->data())->assertForbidden();
    }

    public function test_invalid_passwords_are_not_saved_or_flashed(): void
    {
        $a = $this->agent();
        $d = $this->data();
        $d['password'] = str_repeat('secret-fixture', 100);
        $this->actingAs($this->admin)->put('/agents/'.$a->id.'/maintenance', $d)->assertSessionHasErrors('password');
        $this->assertNull(session()->getOldInput('password'));
        $this->context()->forUser($this->admin);
        $this->assertSame(0, AgentMaintenanceAccess::count());
    }

    public function test_stale_revision_is_rejected_and_check_result_is_real_service_result(): void
    {
        $a = $this->agent();
        $url = '/agents/'.$a->id.'/maintenance';
        $this->flushSession();
        $this->actingAs($this->admin)->put($url, $this->data())->assertRedirect();
        $this->put($url, $this->data())->assertStatus(409);
        $this->partialMock(MaintenanceSsh::class, function ($mock) {
            $mock->shouldReceive('check')->once()->andReturn('host_key_mismatch');
        });
        $this->post($url.'/check', ['revision' => 1])->assertRedirect();
        $this->context()->forUser($this->admin);
        $this->assertSame('host_key_mismatch', AgentMaintenanceAccess::sole()->check_status);
        $this->get($url)->assertSee('Identidade do servidor não confere');
    }

    public function test_expired_access_does_not_connect(): void
    {
        $a = $this->agent();
        $this->actingAs($this->admin)->put('/agents/'.$a->id.'/maintenance', $this->data())->assertRedirect();
        $this->context()->forUser($this->admin);
        $access = AgentMaintenanceAccess::sole();
        $access->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->assertSame('expired', (new MaintenanceSsh)->check($access));
    }

    public function test_existing_server_identity_is_preserved_when_password_changes(): void
    {
        $a = $this->agent();
        $url = '/agents/'.$a->id.'/maintenance';
        $this->actingAs($this->admin)->put($url, $this->data())->assertRedirect();
        config(['agent_maintenance.host_fingerprints' => []]);
        $data = array_replace($this->data(), ['password' => 'replacement-fixture', 'revision' => 1]);
        $this->put($url, $data)->assertRedirect();
        $this->context()->forUser($this->admin);
        $access = AgentMaintenanceAccess::sole();
        $this->assertSame('SHA256:'.str_repeat('a', 43), $access->host_fingerprint);
        $this->assertSame('replacement-fixture', $access->password);
    }

    public function test_unconfigured_server_identity_does_not_store_password_or_flash_it(): void
    {
        $a = $this->agent();
        config(['agent_maintenance.host_fingerprints' => []]);
        $this->actingAs($this->admin)->put('/agents/'.$a->id.'/maintenance', $this->data())->assertSessionHasErrors('host');
        $this->assertNull(session()->getOldInput('password'));
        $this->context()->forUser($this->admin);
        $this->assertSame(0, AgentMaintenanceAccess::count());
    }
}
