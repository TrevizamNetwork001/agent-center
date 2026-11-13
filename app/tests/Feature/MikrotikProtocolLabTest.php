<?php

namespace Tests\Feature;

use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\ConversationMessage;
use App\Models\ConversationSetting;
use App\Models\DeviceLabAccess;
use App\Models\NocDevice;
use App\Models\NocSimulation;
use App\Noc\Inventory;
use App\Noc\ReadOnlyLab\Access;
use App\Noc\ReadOnlyLab\Failure;
use App\Noc\ReadOnlyLab\RouterOs7\Approval;
use App\Noc\ReadOnlyLab\RouterOs7\Operation;
use App\Noc\ReadOnlyLab\RouterOs7\Protocol;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use phpseclib3\Crypt\RSA;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\IdentityTestCase;

/** B: SSH wire protocol against a simulator. No CHR or vendor hardware. */
class MikrotikProtocolLabTest extends IdentityTestCase
{
    private NocDevice $device;

    private Agent $labAgent;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DEVICE_PROTOCOL_LAB') !== '1') {
            $this->markTestSkipped('Use scripts/lab/device-readonly/run.sh.');
        }
        $this->context()->forUser($this->admin);
        config(['device_analysis.lab_enabled' => true, 'device_analysis.lab_tenants' => [$this->a->id, $this->b->id],
            'device_readonly_lab.enabled' => true, 'device_readonly_lab.routeros_protocol' => true,
            'device_readonly_lab.tenants' => [$this->a->id, $this->b->id], 'llm.key_file' => '/lab/vault']);
        Http::preventStrayRequests();
        file_put_contents('/lab/scenario', 'ros-normal');
        $this->device = app(Inventory::class)->save($this->admin, ['name' => 'CCR', 'hostname' => 'ccr.lab.example', 'management_ip' => '192.0.2.11',
            'manufacturer' => 'mikrotik', 'platform' => 'routeros7', 'model' => 'CCR2004-16G-2S+', 'os_version' => '7.16.2', 'category' => 'router',
            'network_role' => 'Borda', 'pop' => 'LAB', 'environment' => 'lab', 'admin_status' => 'active']);
        $this->labAgent = (new Agent)->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'name' => 'SIMULATED authorization binding',
            'hostname' => 'agent.lab.example', 'status' => 'active', 'version' => '0.6.5', 'token_hash' => hash('sha256', random_bytes(32)),
            'enrolled_at' => now(), 'capabilities' => [], 'metadata' => ['simulation' => true]]);
        $this->labAgent->save();
        app(Access::class)->approve($this->admin, $this->device, file_get_contents('/lab/host.pub'), file_get_contents('/lab/client'));
    }

    private function approve(Operation $op, array $params = []): void
    {
        $a = app(Approval::class);
        $a->attest($this->admin, $this->device, $this->labAgent, $op, $params, ['ssh', 'read'], $a->fingerprint(file_get_contents('/lab/host.pub')));
    }

    private function labQuery(Operation $op, array $params = [], ?string $key = null): NocSimulation
    {
        return app(Inventory::class)->simulate($this->admin, $this->device, 'ssh-ros7-'.$op->value, $key ?? (string) Str::uuid(), $params, $this->device->revision);
    }

    public function test_six_typed_operations_over_real_ssh_and_longest_prefix_not_exact_host(): void
    {
        foreach (Operation::cases() as $op) {
            $params = $op === Operation::Route ? ['destination' => '8.8.8.8'] : [];
            $this->approve($op, $params);
            $s = $this->labQuery($op, $params);
            $this->assertSame('SIMULADO', $s->result['display_mode']);
            $this->assertSame('SSH_LOOPBACK_LAB', $s->result['transport']);
            $this->assertFalse($s->result['operational_enabled']);
            $this->assertFalse($s->result['real_evidence']);
            $this->assertNull($s->result['root_cause']);
            $data = $s->result['evidence']['data'];
            if ($op === Operation::Bgp) {
                $this->assertSame(64512, $data['items'][0]['remote_asn']);
                $this->assertSame(93784.03, $data['items'][0]['uptime_seconds']);
                $this->assertNull($data['items'][0]['negotiated_families']);
                $this->assertSame('NOT_ESTABLISHED', $data['items'][1]['state']);
            }
            if ($op === Operation::Route) {
                $this->assertSame('8.8.8.0/24', $data['items'][0]['prefix']);
                $this->assertNull($data['items'][0]['effective_next_hop']);
                $this->assertFalse($data['forwarding_verified']);
            }
            if ($op === Operation::Counters) {
                $this->assertSame(7, $data['items'][0]['rx_error']);
                $this->assertNull($data['items'][1]['rx_drop']);
                $this->assertSame('INCONCLUSIVE', $s->result['classification']);
            }
        }
        $this->assertSame(0, AgentTask::count());
        Http::assertNothingSent();
    }

    public function test_routes_other_table_absent_and_duplicate_idempotency(): void
    {
        foreach ([['destination' => '8.8.8.8', 'table' => 'lab_alt'], ['destination' => '203.0.113.9']] as $params) {
            $this->approve(Operation::Route, $params);
            $key = (string) Str::uuid();
            $s = $this->labQuery(Operation::Route, $params, $key);
            $data = $s->result['evidence']['data'];
            $this->assertSame($params['destination'] === '8.8.8.8', $data['found']);
            file_put_contents('/lab/scenario', 'ros-unavailable');
            $this->assertSame($s->id, $this->labQuery(Operation::Route, $params, $key)->id);
            file_put_contents('/lab/scenario', 'ros-normal');
        }
    }

    public function test_total_route_record_budget_fails_before_collecting_more_prefixes(): void
    {
        file_put_contents('/lab/scenario', 'ros-aggregate-rows');
        $params = ['destination' => '8.8.8.8'];
        $this->approve(Operation::Route, $params);
        try {
            $this->labQuery(Operation::Route, $params);
            $this->fail('Aggregate route record budget exceeded');
        } catch (Failure $e) {
            $this->assertSame('ROW_LIMIT', $e->reason);
        }
        $this->assertSame(0, NocSimulation::count());
    }

    public function test_empty_bgp_partial_resources_and_high_measurements_do_not_invent_faults(): void
    {
        foreach (['ros-empty' => Operation::Bgp, 'ros-no-bgp' => Operation::Bgp, 'ros-partial' => Operation::Resources, 'ros-high' => Operation::Resources] as $scenario => $op) {
            file_put_contents('/lab/scenario', $scenario);
            $this->approve($op);
            $s = $this->labQuery($op);
            $this->assertSame('INCONCLUSIVE', $s->result['classification']);
            if ($op === Operation::Bgp) {
                $this->assertSame($scenario === 'ros-no-bgp' ? 'NO_BGP_CONNECTIONS_CONFIGURED' : 'NO_SESSIONS_OBSERVED', $s->result['evidence']['data']['availability_reason']);
                $this->assertSame($scenario !== 'ros-no-bgp', $s->result['evidence']['data']['connections_configured']);
                $this->assertSame($scenario !== 'ros-no-bgp' ? true : null, $s->result['evidence']['data']['configuration_present']);
            }
        }
    }

    public function test_protocol_failures_and_sensitive_output_are_not_persisted(): void
    {
        $this->approve(Operation::Bgp);
        foreach (['unsupported', 'auth', 'negotiation', 'unavailable', 'timeout', 'oversize', 'invalid', 'truncate', 'identity', 'sensitive', 'rows', 'disconnect'] as $scenario) {
            file_put_contents('/lab/scenario', 'ros-'.$scenario);
            $before = NocSimulation::count();
            $start = microtime(true);
            try {
                $this->labQuery(Operation::Bgp);
                $this->fail('Accepted invalid scenario: '.$scenario);
            } catch (Failure $e) {
                $this->assertStringNotContainsString('FIXTURE_REDACT_ME', $e->getMessage());
            }
            $this->assertSame($before, NocSimulation::count());
            $this->assertLessThan(7, microtime(true) - $start);
        }
        $this->assertStringNotContainsString('FIXTURE_REDACT_ME', json_encode(DB::table('audit_events')->get()));
    }

    public function test_approval_requires_minimum_rights_pinned_identity_and_current_policy(): void
    {
        try {
            app(Approval::class)->attest($this->admin, $this->device, $this->labAgent, Operation::Bgp, [], ['read', 'ssh', 'sensitive'], 'unknown');
            $this->fail('Default read-like excess rights accepted');
        } catch (Failure $e) {
            $this->assertSame('EXCESSIVE_OR_UNKNOWN_RIGHTS', $e->reason);
        }
        foreach (['approved_at' => 'not-a-date', 'policy_revision' => 2, 'host_fingerprint' => 'SHA256:unknown', 'expires_at' => now()->subMinute()->toIso8601String(), 'operation' => Operation::Resources->value, 'agent_id' => (string) Str::uuid()] as $field => $value) {
            $this->approve(Operation::Bgp);
            $row = DeviceLabAccess::firstOrFail();
            $row->forceFill(['protocol_approval' => [...$row->protocol_approval, $field => $value]])->save();
            try {
                $this->labQuery(Operation::Bgp);
                $this->fail('Stale authorization accepted: '.$field);
            } catch (Failure) {
                $this->assertSame(0, NocSimulation::count());
            }
        }
        $this->approve(Operation::Bgp);
        app(Access::class)->revoke($this->admin, $this->device);
        $this->expectException(Failure::class);
        $this->labQuery(Operation::Bgp);
    }

    public function test_changed_host_key_and_invalid_client_key_fail_at_ssh_boundary(): void
    {
        $wrong = RSA::createKey(2048);
        foreach ([[$wrong->getPublicKey()->toString('OpenSSH'), file_get_contents('/lab/client'), 'HOST_IDENTITY_REJECTED'],
            [file_get_contents('/lab/host.pub'), $wrong->toString('PKCS8'), 'AUTHENTICATION_FAILED']] as [$host, $client, $reason]) {
            app(Access::class)->approve($this->admin, $this->device, $host, $client);
            app(Approval::class)->attest($this->admin, $this->device, $this->labAgent, Operation::Bgp, [], ['read', 'ssh'], app(Approval::class)->fingerprint($host));
            try {
                $this->labQuery(Operation::Bgp);
                $this->fail('Untrusted identity or credential accepted');
            } catch (Failure $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
        $this->assertSame(0, NocSimulation::count());
    }

    public function test_rejected_parameters_unauthorized_agent_and_rotated_credential_invalidate_grants(): void
    {
        $this->approve(Operation::Route, ['destination' => '8.8.8.8']);
        $this->actingAs($this->admin)->postJson('/noc-inventory/'.$this->device->id.'/readonly-lab', ['operation' => Operation::Route->value, 'protocol' => 'routeros7-v1', 'request_key' => (string) Str::uuid(), 'device_revision' => 1,
            'parameters' => ['destination' => '8.8.8.8', 'command' => '/system reboot']])->assertUnprocessable();
        $this->context()->forUser($this->admin);
        $this->approve(Operation::Bgp);
        $this->labAgent->forceFill(['revoked_at' => now(), 'status' => 'revoked'])->save();
        try {
            $this->labQuery(Operation::Bgp);
            $this->fail('Revoked Agent accepted');
        } catch (Failure $e) {
            $this->assertSame('AGENT_NOT_AUTHORIZED', $e->reason);
        }
        $this->labAgent->forceFill(['revoked_at' => null, 'status' => 'active'])->save();
        app(Access::class)->approve($this->admin, $this->device, file_get_contents('/lab/host.pub'), file_get_contents('/lab/client'));
        $this->expectException(Failure::class);
        $this->labQuery(Operation::Bgp);
    }

    public function test_tenant_rls_rbac_and_production_environment_fail_closed(): void
    {
        $this->approve(Operation::Bgp);
        $this->context()->forUser($this->other);
        $this->assertSame(0, DeviceLabAccess::withoutGlobalScopes()->count());
        $this->assertSame(0, NocDevice::withoutGlobalScopes()->count());
        $this->assertTrue((bool) DB::selectOne("SELECT relforcerowsecurity FROM pg_class WHERE relname='device_lab_accesses'")->relforcerowsecurity);
        $this->actingAs($this->viewer)->postJson('/noc-inventory/'.$this->device->id.'/readonly-lab', ['operation' => Operation::Bgp->value, 'protocol' => 'routeros7-v1', 'request_key' => (string) Str::uuid(), 'device_revision' => 1])->assertForbidden();
        $this->context()->forUser($this->admin);
        config(['app.env' => 'production']);
        $this->expectException(HttpException::class);
        $this->labQuery(Operation::Bgp);
    }

    public function test_existing_web_and_conversation_flow_is_simulated_and_normal_intents_never_connect(): void
    {
        $this->approve(Operation::Bgp);
        $this->actingAs($this->admin)->post('/noc-inventory/'.$this->device->id.'/readonly-lab', ['operation' => Operation::Bgp->value, 'protocol' => 'routeros7-v1', 'request_key' => (string) Str::uuid(), 'device_revision' => 1])->assertRedirect();
        $html = $this->get('/noc-inventory/'.$this->device->id)->assertOk()->assertSee('SIMULADO')->getContent();
        $html = preg_replace('/name="(?:_token|request_key)" value="[^"]+"/', 'value="REMOVED"', $html);
        file_put_contents('/lab/mikrotik-protocol.html', $html);
        $this->context()->forUser($this->admin);
        (new ConversationSetting)->forceFill(['tenant_id' => $this->a->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $this->admin->id])->save();
        $identity = Identity::web($this->admin);
        $engine = app(Engine::class);
        $c = $engine->create($identity, (string) Str::uuid());
        $engine->send($identity, $c, (string) Str::uuid(), 'Como estão os peers BGP do CCR?');
        $this->assertSame(1, NocSimulation::count());
        $c->refresh();
        $engine->send($identity, $c, (string) Str::uuid(), 'Simule routeros-bgp no CCR');
        $this->assertSame(2, NocSimulation::count());
        $last = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->firstOrFail();
        $this->assertStringContainsString('SIMULADO', $last->body);
        $this->assertSame(0, AgentTask::count());
    }
}
