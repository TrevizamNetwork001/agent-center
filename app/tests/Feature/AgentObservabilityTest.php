<?php

namespace Tests\Feature;

use App\Agent\ManagedSettings;
use App\Agent\Observability;
use App\Agent\Protocol;
use App\Models\Agent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;

class AgentObservabilityTest extends IdentityTestCase
{
    private function fixture(): array
    {
        $this->context()->forUser($this->admin);
        $id = (string) Str::uuid();
        $token = $id.'.'.str_repeat('a', 64);
        $agent = new Agent;
        $agent->forceFill(['id' => $id, 'tenant_id' => $this->a->id, 'name' => 'Observability lab', 'hostname' => 'lab', 'version' => '0.6.2', 'status' => 'active', 'token_hash' => hash('sha256', $token), 'last_seen_ip' => '127.0.0.1', 'last_heartbeat_at' => now(), 'capabilities' => Protocol::CAPABILITIES, 'metadata' => []])->save();
        $report = ['schema_version' => 1, 'collected_at' => now()->format('Y-m-d\TH:i:s.uP'), 'policy_revision' => 1, 'policy_digest' => Observability::managedDigest($agent), 'capabilities' => [], 'helper' => null, 'base' => ['allowed_dns_names' => ['private.example.com'], 'allowed_networks' => []]];
        $agent->forceFill(['metadata' => ['observability' => $report + ['received_at' => now()->toIso8601String()]]])->save();
        $this->context()->apply();

        return [$agent, ['Authorization' => 'Bearer '.$token], $report];
    }

    public function test_read_only_query_uses_existing_heartbeat_and_correlates_result(): void
    {
        [$agent, $headers, $report] = $this->fixture();
        $this->actingAs($this->admin)->post('/agents/'.$agent->id.'/permission-check', ['capability' => 'mtr_path_analysis:v1', 'destination' => 'google.com', 'protocol' => 'tcp'])->assertRedirect();
        $this->app['auth']->forgetGuards();
        $pending = $this->postJson('/api/v1/agent/heartbeat', ['version' => '0.6.2', 'observability' => $report], $headers)->assertOk()->json('permission_request');
        $this->assertSame('google.com', $pending['destination']);
        $result = ['id' => $pending['id'], 'state' => 'DENIED', 'reason' => 'ADMIN_CEILING_DENIED', 'layer' => 'ceiling', 'checked_at' => now()->toIso8601String(), 'revision' => 1, 'digest' => Observability::managedDigest($agent)];
        $this->postJson('/api/v1/agent/heartbeat', ['version' => '0.6.2', 'permission_result' => $result], $headers)->assertOk()->assertJsonPath('permission_request', null);
        $this->context()->forUser($this->admin);
        $this->assertSame('DENIED', Observability::view($agent->fresh())['result']['state']);
        $this->assertDatabaseCount('agent_tasks', 0);
        $this->assertDatabaseCount('agent_operations', 0);
        $this->assertEquals(ManagedSettings::defaults($agent), ManagedSettings::current($agent->fresh()));
    }

    public function test_rbac_tenant_isolation_and_policy_visibility(): void
    {
        [$agent] = $this->fixture();
        $input = ['capability' => 'mtr_path_analysis:v1', 'destination' => 'google.com', 'protocol' => 'icmp'];
        foreach ([$this->viewer, $this->operator] as $user) {
            $this->flushSession();
            $this->actingAs($user)->post('/agents/'.$agent->id.'/permission-check', $input)->assertForbidden();
            $this->get('/agents/'.$agent->id)->assertOk()->assertDontSee('private.example.com');
        }
        $this->flushSession();
        $this->actingAs($this->other)->get('/agents/'.$agent->id)->assertNotFound();
        $this->post('/agents/'.$agent->id.'/permission-check', $input)->assertNotFound();
    }

    public function test_offline_stale_and_revision_changes_never_preserve_allowed(): void
    {
        [$agent] = $this->fixture();
        $this->context()->forUser($this->admin);
        $meta = $agent->metadata;
        $meta['permission_result'] = ['id' => (string) Str::uuid(), 'state' => 'ALLOWED', 'revision' => 1, 'received_at' => now()->toIso8601String()];
        $agent->forceFill(['metadata' => $meta, 'last_heartbeat_at' => now()->subMinutes(3)])->save();
        $this->assertSame('UNKNOWN', Observability::view($agent)['result']['state']);
        $this->assertFalse((bool) Observability::view($agent->fresh())['fresh']);
        $this->actingAs($this->admin)->post('/agents/'.$agent->id.'/permission-check', ['capability' => 'mtr_path_analysis:v1', 'destination' => 'google.com', 'protocol' => 'tcp'])->assertStatus(409);
    }

    public function test_contract_rejects_secrets_arbitrary_fields_versions_and_unknown_codes(): void
    {
        [$agent, $headers, $report] = $this->fixture();
        foreach ([$report + ['token' => 'must-not-persist'], [...$report, 'schema_version' => 2], [...$report, 'last_error' => 'raw traceback with secret']] as $bad) {
            $this->postJson('/api/v1/agent/heartbeat', ['version' => '0.6.2', 'observability' => $bad], $headers)->assertUnprocessable();
        }
        $this->context()->forUser($this->admin);
        $this->assertArrayNotHasKey('token', $agent->fresh()->metadata['observability']);
    }

    public function test_read_only_screen_marks_absent_values_and_has_compact_sections(): void
    {
        [$agent] = $this->fixture();
        $response = $this->actingAs($this->admin)->get('/agents/'.$agent->id)->assertOk()->assertSee('Diagnóstico operacional')->assertSee('INDISPONÍVEL')->assertSee('Teto administrativo')->assertSee('Verificar permissão');
        if ($directory = getenv('OBSERVABILITY_EVIDENCE_DIR')) {
            file_put_contents($directory.'/observability.html', $response->getContent());
            chmod($directory.'/observability.html', 0600);
        }
    }

    public function test_unmatched_or_expired_results_are_ignored(): void
    {
        [$agent, $headers] = $this->fixture();
        $result = ['id' => (string) Str::uuid(), 'state' => 'ALLOWED', 'checked_at' => now()->toIso8601String(), 'revision' => 1, 'digest' => Observability::managedDigest($agent)];
        $this->postJson('/api/v1/agent/heartbeat', ['version' => '0.6.2', 'permission_result' => $result], $headers)->assertOk();
        $this->context()->forUser($this->admin);
        $this->assertArrayNotHasKey('permission_result', $agent->fresh()->metadata);
    }

    public function test_rls_is_forced_and_blocks_raw_cross_tenant_read(): void
    {
        [$agent] = $this->fixture();
        $row = DB::selectOne("SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = 'agents'::regclass");
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
        $this->context()->forUser($this->other);
        $this->assertSame([], DB::select('SELECT metadata FROM agents WHERE id = ?', [$agent->id]));
    }

    public function test_old_collected_report_and_changed_digest_are_not_current(): void
    {
        [$agent] = $this->fixture();
        $meta = $agent->metadata;
        $meta['observability']['collected_at'] = now()->subMinutes(5)->toIso8601String();
        $agent->forceFill(['metadata' => $meta]);
        $this->assertFalse((bool) Observability::view($agent)['fresh']);
        $meta['observability']['collected_at'] = now()->toIso8601String();
        $meta['observability']['policy_digest'] = str_repeat('0', 64);
        $agent->forceFill(['metadata' => $meta]);
        $this->assertFalse((bool) Observability::view($agent)['policyApplied']);
    }

    public function test_standard_capability_query_canonicalizes_protocol_without_creating_task(): void
    {
        [$agent] = $this->fixture();
        $this->actingAs($this->admin)->post('/agents/'.$agent->id.'/permission-check', ['capability' => 'http_probe:v1', 'destination' => 'google.com', 'protocol' => 'icmp'])->assertRedirect();
        $this->context()->forUser($this->admin);
        $this->assertSame('https', Observability::pending($agent->fresh())['protocol']);
        $this->assertDatabaseCount('agent_tasks', 0);
        $this->get('/agents/'.$agent->id)->assertOk()->assertSee('dns_lookup:v1')->assertSee('http_probe:v1');
    }
}
