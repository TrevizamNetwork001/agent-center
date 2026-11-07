<?php

namespace Tests\Feature;

use App\Agent\Protocol;
use App\Diagnostics\Catalog;
use App\Diagnostics\Normalizer;
use App\Diagnostics\Planner;
use App\Models\Agent;
use App\Models\Profile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\IdentityTestCase;

class AgentManagedSettingsTest extends IdentityTestCase
{
    private function fixture(string $version = '0.6.0'): array
    {
        $this->context()->forUser($this->admin);
        $a = new Agent;
        $id = (string) Str::uuid();
        $token = $id.'.'.str_repeat('a', 64);
        $a->forceFill(['id' => $id, 'tenant_id' => $this->a->id, 'name' => 'Test POP', 'hostname' => 'pop', 'version' => $version, 'status' => 'active', 'token_hash' => hash('sha256', $token), 'capabilities' => Protocol::CAPABILITIES, 'metadata' => ['task_protocol' => 1], 'last_seen_ip' => '127.0.0.1'])->save();
        $this->context()->apply();

        return [$a, ['Authorization' => 'Bearer '.$token]];
    }

    public function test_observed_origin_is_seeded_and_new_source_rejected_even_with_forged_forwarded_header(): void
    {
        [$a,$h] = $this->fixture();
        $this->postJson('/api/v1/agent/heartbeat', ['version' => '0.6.0'], $h)->assertOk()->assertJsonPath('policy.agent_settings.source_ips', ['127.0.0.1']);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/v1/agent/heartbeat', ['version' => '0.6.0'], $h + ['X-Forwarded-For' => '127.0.0.1'])->assertForbidden()->assertJsonPath('error', 'origin_not_allowed');
        $this->context()->forUser($this->admin);
        $this->assertSame('203.0.113.9', $a->fresh()->metadata['pending_source_ip']);
        $this->assertSame('127.0.0.1', $a->fresh()->last_seen_ip);
    }

    public function test_admin_can_add_ipv6_and_agent_acknowledges_revision(): void
    {
        [$a,$h] = $this->fixture();
        $body = ['revision' => 1, 'source_ips' => "127.0.0.1\n2001:db8::1", 'auto_public_targets' => 1, 'allowed_networks' => '192.168.10.0/24', 'allowed_dns_names' => 'router.internal'];
        $this->actingAs($this->admin)->put('/agents/'.$a->id.'/settings', $body)->assertRedirect();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/agent/heartbeat', ['version' => '0.6.0', 'telemetry' => ['settings_revision' => 2, 'local_addresses' => ['192.168.10.5'], 'dns_resolvers' => ['127.0.0.53']]], $h)->assertOk()->assertJsonPath('policy.agent_settings.revision', 2)->assertJsonPath('policy.agent_settings.allowed_networks', ['192.168.10.0/24']);
        $this->actingAs($this->admin)->get('/agents/'.$a->id)->assertOk()->assertSee('Recebida pelo Agent')->assertSee('192.168.10.5');
        $this->put('/agents/'.$a->id.'/settings', $body)->assertConflict();
    }

    public function test_acl_is_editable_by_admin_only_and_tenant_isolated(): void
    {
        [$a] = $this->fixture();
        $data = ['revision' => 1, 'source_ips' => '127.0.0.1', 'auto_public_targets' => 1];
        foreach ([$this->viewer, $this->operator] as $u) {
            $this->flushSession();
            $this->actingAs($u)->put('/agents/'.$a->id.'/settings', $data)->assertForbidden();
        }
        $this->flushSession();
        $this->actingAs($this->other)->put('/agents/'.$a->id.'/settings', $data)->assertNotFound();
    }

    public function test_acl_refuses_cidr_and_bad_settings_without_partial_write(): void
    {
        [$a] = $this->fixture();
        $this->actingAs($this->admin);
        foreach (['0.0.0.0/0', 'not-an-ip', '127.0.0.1/32'] as $ip) {
            $this->put('/agents/'.$a->id.'/settings', ['revision' => 1, 'source_ips' => $ip, 'auto_public_targets' => 1])->assertSessionHasErrors('source_ips');
        }
        $this->put('/agents/'.$a->id.'/settings', ['revision' => 1, 'source_ips' => '127.0.0.1', 'auto_public_targets' => 1, 'allowed_networks' => '192.168.1.0/99'])->assertSessionHasErrors('allowed_networks');
        $this->context()->forUser($this->admin);
        $this->assertArrayNotHasKey('managed_settings', $a->fresh()->metadata);
    }

    public function test_old_runtime_keeps_compatibility_until_admin_enforces_acl(): void
    {
        [$a,$h] = $this->fixture('0.5.9');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/v1/agent/heartbeat', ['version' => '0.5.9'], $h)->assertOk();
    }

    public function test_invalid_telemetry_never_overwrites_configuration(): void
    {
        [$a,$h] = $this->fixture();
        $this->postJson('/api/v1/agent/heartbeat', ['version' => '0.6.0', 'telemetry' => ['dns_resolvers' => ['bad']]], $h)->assertUnprocessable();
        $this->postJson('/api/v1/agent/heartbeat', ['version' => '0.6.0', 'telemetry' => ['settings_revision' => -1]], $h)->assertUnprocessable();
    }

    public function test_dns_transport_is_planned_only_from_assigned_agent_inventory(): void
    {
        $this->context()->forUser($this->admin);
        app(Catalog::class)->ensure();
        $p = Profile::where('key', 'PROBLEMA_DNS')->firstOrFail();
        $plan = app(Planner::class)->plan($p, 'google.com', Protocol::CAPABILITIES, ['agent_version' => '0.6.0', 'dns_resolvers' => ['127.0.0.53']]);
        $steps = collect($plan['steps'])->keyBy('key');
        $this->assertCount(4, $steps);
        $this->assertSame('udp', $steps['dns_udp']['input']['transport']);
        $this->assertSame('127.0.0.53', $steps['dns_tcp']['input']['resolver']);
        $old = app(Planner::class)->plan($p, 'google.com', Protocol::CAPABILITIES, ['agent_version' => '0.5.9', 'dns_resolvers' => ['127.0.0.53']]);
        $this->assertCount(2, $old['steps']);
    }

    public function test_dns_wire_result_has_typed_response_and_does_not_claim_udp_port_scan(): void
    {
        $r = ['ok' => true, 'data' => ['answers' => ['8.8.8.8'], 'record_type' => 'A', 'duration_ms' => 1, 'transport' => 'udp', 'resolver' => '127.0.0.53', 'rcode' => 0, 'truncated' => false]];
        $this->assertSame($r, app(Normalizer::class)->normalize('dns_lookup:v1', $r));
        $r['data']['rcode'] = 16;
        $this->expectException(ValidationException::class);
        app(Normalizer::class)->normalize('dns_lookup:v1', $r);
    }

    public function test_add_agent_flow_starts_collapsed_and_explains_provider_execution(): void
    {
        $this->actingAs($this->admin)->get('/agents')->assertOk()->assertSee('Adicionar Agent no provedor')->assertSee('Máquinas Linux')->assertSee('<details class="card agent-enrollment" id="enrollment">',false)->assertDontSee('Novo enrollment');
    }
}
