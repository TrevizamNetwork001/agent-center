<?php

namespace Tests\Feature;

use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Diagnostics\IntentParser;
use App\Http\Requests\NocDeviceRequest;
use App\Llm\SecretVault;
use App\Models\AgentTask;
use App\Models\ConversationMessage;
use App\Models\ConversationSetting;
use App\Models\DeviceLabAccess;
use App\Models\NocDevice;
use App\Models\NocSimulation;
use App\Noc\Inventory;
use App\Noc\ReadOnlyLab\Access;
use App\Noc\ReadOnlyLab\Failure;
use App\Noc\ReadOnlyLab\Operation;
use App\Noc\ReadOnlyLab\Platform;
use App\Noc\ReadOnlyLab\Ssh;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use phpseclib3\Net\SSH2;
use Tests\IdentityTestCase;

/** Real SSH protocol with simulated endpoints, never vendor hardware. */
class DeviceReadOnlyLabTest extends IdentityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DEVICE_PROTOCOL_LAB') !== '1') {
            $this->markTestSkipped('Run scripts/lab/device-readonly/run.sh for isolated SSH protocol tests.');
        }
        $this->context()->forUser($this->admin);
        config(['device_analysis.lab_enabled' => true, 'device_analysis.lab_tenants' => [$this->a->id, $this->b->id],
            'device_readonly_lab.enabled' => true, 'device_readonly_lab.tenants' => [$this->a->id, $this->b->id], 'llm.key_file' => '/lab/vault']);
        file_put_contents('/lab/scenario', 'normal');
        Http::preventStrayRequests();
    }

    private function device(bool $ros = false): NocDevice
    {
        return app(Inventory::class)->save($this->admin, ['name' => $ros ? 'CCR' : 'NE8000', 'hostname' => $ros ? 'ccr.lab.example' : 'ne.lab.example',
            'management_ip' => $ros ? '192.0.2.11' : '192.0.2.10', 'manufacturer' => $ros ? 'mikrotik' : 'huawei', 'platform' => $ros ? 'routeros7' : 'huawei-vrp',
            'model' => $ros ? 'CCR2004-16G-2S+' : 'NE8000 M4', 'os_version' => $ros ? '7.16.2' : 'V800R023C00SPC500', 'category' => 'router',
            'network_role' => 'Borda', 'pop' => 'LAB', 'environment' => 'lab', 'admin_status' => 'active']);
    }

    private function approve(NocDevice $d): DeviceLabAccess
    {
        return app(Access::class)->approve($this->admin, $d, file_get_contents('/lab/host.pub'), file_get_contents('/lab/client'));
    }

    private function runQuery(NocDevice $d, Operation $op = Operation::Bgp, ?string $key = null): NocSimulation
    {
        return app(Inventory::class)->simulate($this->admin, $d, 'ssh-'.$op->value, $key ?? (string) Str::uuid(), $op === Operation::Route ? ['destination' => '8.8.8.8'] : [], $d->revision);
    }

    public function test_both_platforms_end_to_end_over_ssh_with_typed_evidence_and_no_agent_task(): void
    {
        foreach ([false, true] as $ros) {
            $d = $this->device($ros);
            $this->approve($d);
            foreach (Operation::cases() as $op) {
                $s = $this->runQuery($d, $op);
                $this->assertSame('SSH_LOOPBACK_LAB', $s->result['transport']);
                $this->assertSame('SIMULADO', $s->result['display_mode']);
                $this->assertFalse($s->result['real_evidence']);
                $this->assertFalse($s->result['operational_enabled']);
                $this->assertNull($s->result['root_cause']);
                if ($op === Operation::Bgp) {
                    $this->assertCount(3, $s->result['evidence']['data']['items']);
                    $this->assertSame('IPV4_UNICAST', $s->result['evidence']['data']['items'][0]['address_family']);
                    $this->assertSame('ATTENTION', $s->result['classification']);
                }
                if ($op === Operation::Interfaces) {
                    $this->assertSame(7, $s->result['evidence']['data']['items'][0]['rx_errors']);
                    $this->assertSame('NORMAL', $s->result['classification']);
                }
                if ($op === Operation::Resources) {
                    $this->assertSame('INCONCLUSIVE', $s->result['classification']);
                }
            }
            $key = (string) Str::uuid();
            $first = $this->runQuery($d, key: $key);
            file_put_contents('/lab/scenario', 'unavailable');
            $this->assertSame($first->id, $this->runQuery($d, key: $key)->id);
            file_put_contents('/lab/scenario', 'normal');
            $html = $this->actingAs($this->admin)->get('/noc-inventory/'.$d->id)->assertOk()->getContent();
            // Offline visual evidence contains fixture identities only, with tokens removed.
            $html = preg_replace('/name="(?:_token|request_key)" value="[^"]+"/', 'value="REMOVED"', $html);
            file_put_contents('/lab/'.($ros ? 'routeros7' : 'ne8000').'.html', $html);
            $this->context()->forUser($this->admin);
        }
        $this->assertSame(0, AgentTask::count());
        Http::assertNothingSent();
    }

    public function test_protocol_failure_matrix_and_session_cleanup(): void
    {
        foreach ([false, true] as $ros) {
            $d = $this->device($ros);
            $this->approve($d);
            foreach (['auth' => 'AUTHENTICATION_FAILED', 'invalid' => 'UNRECOGNIZED_OR_TRUNCATED_OUTPUT', 'truncate' => 'UNRECOGNIZED_OR_TRUNCATED_OUTPUT',
                'oversize' => 'OUTPUT_LIMIT', 'identity' => 'UNRECOGNIZED_OR_TRUNCATED_OUTPUT', 'timeout' => 'TIMEOUT', 'unavailable' => 'SSH_UNAVAILABLE_OR_NEGOTIATION_FAILED', 'negotiation' => 'SSH_UNAVAILABLE_OR_NEGOTIATION_FAILED'] as $scenario => $reason) {
                file_put_contents('/lab/scenario', $scenario);
                $before = NocSimulation::count();
                $start = microtime(true);
                try {
                    $this->runQuery($d);
                    $this->fail('Failure accepted: '.$scenario);
                } catch (Failure $e) {
                    if ($reason !== null) {
                        $this->assertSame($reason, $e->reason, $scenario);
                    }
                } catch (ValidationException $e) {
                    $this->assertSame('identity', $scenario);
                }
                $this->assertLessThan(7, microtime(true) - $start);
                $this->assertSame($before, NocSimulation::count());
            }
        }
        file_put_contents('/lab/scenario', 'normal');
        $deadline = microtime(true) + 3;
        do {
            usleep(50000);
            $stats = json_decode(file_get_contents('/lab/stats.json'), true);
        } while (($stats['active'] ?? 1) !== 0 && microtime(true) < $deadline);
        $this->assertSame(0, $stats['active']);
        $this->assertGreaterThanOrEqual(16, DB::table('audit_events')->where('event_type', 'noc.lab_query_failed')->count());
    }

    public function test_host_identity_publickey_auth_allowlist_shell_and_forwarding(): void
    {
        $d = $this->device();
        $key = RSA::createKey(2048);
        $this->approve($d);
        try {
            app(Ssh::class)->collect(Platform::Huawei, Operation::Bgp, [], $key->getPublicKey()->toString('OpenSSH'), file_get_contents('/lab/client'));
            $this->fail('Unapproved host accepted');
        } catch (Failure $e) {
            $this->assertSame('HOST_IDENTITY_REJECTED', $e->reason);
        }
        try {
            app(Ssh::class)->collect(Platform::Huawei, Operation::Bgp, [], file_get_contents('/lab/host.pub'), $key->toString('PKCS8'));
            $this->fail('Wrong client accepted');
        } catch (Failure $e) {
            $this->assertSame('AUTHENTICATION_FAILED', $e->reason);
        }
        $ssh = new SSH2('127.0.0.1', 2222, 1);
        $this->assertTrue($ssh->login('lab-readonly', PublicKeyLoader::loadPrivateKey(file_get_contents('/lab/client'))));
        foreach (['system-view', '/system reboot', 'display bgp peer; reboot', 'configure terminal'] as $command) {
            $this->assertSame('', $ssh->exec($command));
            $this->assertSame(126, $ssh->getExitStatus());
        }
        $ssh->disconnect();
        $this->assertNull(Operation::tryFrom('system-view'));
    }

    public function test_rbac_tenant_force_rls_rotation_revocation_and_inventory_revision(): void
    {
        $d = $this->device();
        $a = $this->approve($d);
        $this->context()->forUser($this->platform, $this->a->id);
        $platformResult = app(Inventory::class)->simulate($this->platform, $d, 'ssh-'.Operation::Bgp->value, (string) Str::uuid(), [], $d->revision);
        $this->assertSame($this->a->id, $platformResult->tenant_id);
        $this->context()->forUser($this->admin);
        $this->assertStringNotContainsString('secret_', json_encode($a));
        $this->assertSame(2, $this->approve($d)->revision);
        $this->context()->forUser($this->other);
        $this->assertSame(0, DB::table('device_lab_accesses')->count());
        $this->assertNull(DeviceLabAccess::withoutGlobalScopes()->find($a->id));
        $this->actingAs($this->other)->postJson('/noc-inventory/'.$d->id.'/readonly-lab', ['operation' => Operation::Bgp->value, 'request_key' => (string) Str::uuid(), 'device_revision' => 1])->assertNotFound();
        $this->flushSession();
        $this->actingAs($this->viewer)->postJson('/noc-inventory/'.$d->id.'/readonly-lab', ['operation' => Operation::Bgp->value, 'request_key' => (string) Str::uuid(), 'device_revision' => 1])->assertForbidden();
        $this->context()->forUser($this->admin);
        app(Access::class)->revoke($this->admin, $d);
        try {
            $this->runQuery($d);
            $this->fail('Revoked access accepted');
        } catch (Failure $e) {
            $this->assertSame('LAB_ACCESS_NOT_APPROVED', $e->reason);
        }
        $this->approve($d);
        $d = app(Inventory::class)->save($this->admin, ['pop' => 'LAB2'], $d, $d->revision);
        try {
            $this->runQuery($d);
            $this->fail('Stale inventory accepted');
        } catch (Failure $e) {
            $this->assertSame('LAB_ACCESS_NOT_APPROVED', $e->reason);
        }
        $this->approve($d);
        config(['app.env' => 'production']);
        $this->assertFalse(app(Access::class)->enabled());
        $this->assertSame(0, AgentTask::count());
    }

    public function test_two_tenants_have_independent_credentials_and_cross_tenant_vault_bindings_fail(): void
    {
        $d = $this->device();
        $first = $this->approve($d);
        $this->context()->forUser($this->other);
        $other = app(Inventory::class)->save($this->other, $d->only(NocDeviceRequest::FIELDS));
        $second = app(Access::class)->approve($this->other, $other, file_get_contents('/lab/host.pub'), file_get_contents('/lab/client'));
        $this->assertNotSame($first->id, $second->id);
        $result = app(Inventory::class)->simulate($this->other, $other, 'ssh-'.Operation::Bgp->value, (string) Str::uuid(), [], $other->revision);
        $this->assertSame($this->b->id, $result->tenant_id);
        $wrong = clone $first;
        $wrong->tenant_id = $this->b->id;
        $this->assertNull(app(SecretVault::class)->decrypt($wrong));
        $this->context()->forUser($this->admin);
        $first->forceFill(['expires_at' => now()->subSecond()])->save();
        try {
            $this->runQuery($d);
            $this->fail('Expired approval accepted');
        } catch (Failure $e) {
            $this->assertSame('LAB_ACCESS_NOT_APPROVED', $e->reason);
        }
        $this->assertSame(1, DeviceLabAccess::count());
    }

    public function test_readonly_post_keeps_native_csrf_protection(): void
    {
        $d = $this->device();
        $this->approve($d);
        $this->actingAs($this->admin);
        $this->app->singleton(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->post('/noc-inventory/'.$d->id.'/readonly-lab', ['operation' => Operation::Bgp->value, 'request_key' => (string) Str::uuid(), 'device_revision' => 1])->assertStatus(419);
        $this->context()->forUser($this->admin);
        $this->assertSame(0, NocSimulation::count());
    }

    public function test_web_and_conversation_explicit_lab_only_and_real_intents_stay_frozen(): void
    {
        $d = $this->device();
        $this->approve($d);
        $body = ['operation' => Operation::Bgp->value, 'request_key' => (string) Str::uuid(), 'device_revision' => 1];
        $this->actingAs($this->admin)->post('/noc-inventory/'.$d->id.'/readonly-lab', $body)->assertRedirect();
        $this->post('/noc-inventory/'.$d->id.'/readonly-lab', $body)->assertRedirect();
        $this->get('/noc-inventory/'.$d->id)->assertOk()->assertSee('SIMULADO')->assertSee('SSH de laboratório');
        $this->postJson('/noc-inventory/'.$d->id.'/readonly-lab', [...$body, 'parameters' => ['command' => 'system-view']])->assertUnprocessable();
        $this->context()->forUser($this->admin);
        (new ConversationSetting)->forceFill(['tenant_id' => $this->a->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $this->admin->id])->save();
        $identity = Identity::web($this->admin);
        $engine = app(Engine::class);
        $c = $engine->create($identity, (string) Str::uuid());
        $c = $engine->send($identity, $c, (string) Str::uuid(), 'Verifique o BGP do NE8000.');
        $this->assertSame(1, NocSimulation::count());
        $key = (string) Str::uuid();
        $c = $engine->send($identity, $c, $key, 'Simule ssh-bgp no NE8000');
        $engine->send($identity, $c, $key, 'Simule ssh-bgp no NE8000');
        $m = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first();
        $this->assertStringContainsString('SIMULADO', $m->body);
        $this->assertTrue($m->metadata['simulation']);
        $this->assertSame(2, NocSimulation::count());
        $this->assertSame('ROUTE_PATH_ANALYSIS', app(IntentParser::class)->parse('Analise o caminho até 8.8.8.8')['intent']);
        $this->assertSame(0, AgentTask::count());
        Http::assertNothingSent();
    }
}
