<?php

namespace Tests\Feature;

use App\Agent\Protocol;
use App\Conversation\Engine as ConversationEngine;
use App\Conversation\Identity;
use App\Diagnostics\Engine;
use App\Diagnostics\Preflight;
use App\Llm\SecretVault;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationSetting;
use App\Models\DeviceLabAccess;
use App\Models\Diagnostic;
use App\Models\DiagnosticConclusion;
use App\Models\DiagnosticStep;
use App\Models\NocDevice;
use App\Noc\Inventory;
use App\Noc\ReadOnlyLab\Access;
use App\Noc\ReadOnlyLab\Failure;
use App\Noc\ReadOnlyLab\Platform;
use App\Noc\ReadOnlyLab\RouterOs7\Approval;
use App\Noc\ReadOnlyLab\RouterOs7\Integration;
use App\Noc\ReadOnlyLab\RouterOs7\Operation;
use App\Noc\ReadOnlyLab\Ssh;
use App\Telegram\Accounts;
use App\Telegram\Client;
use App\Telegram\Deliver;
use App\Telegram\Ingress;
use App\Telegram\Link;
use App\Telegram\Outbound;
use App\Telegram\Runner;
use App\Telegram\Sweep;
use App\Telegram\Update;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use phpseclib3\Crypt\RSA;
use Tests\IdentityTestCase;

/** Real Laravel Agent API + non-root Runtime/Executor + SSH simulator; disposable DB only. */
class RouterOsTaskIntegrationTest extends IdentityTestCase
{
    private Agent $agent;

    private NocDevice $device;

    private string $token;

    private array $proof = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! Integration::enabled()) {
            $this->markTestSkipped('Run ROUTEROS_TASK_LAB=1 scripts/lab/device-readonly/run.sh.');
        }
        // Real HTTP callbacks need committed fixture rows. This database is owned by the runner.
        $this->assertSame('agent_center_test', DB::connection()->getDatabaseName());
        DB::commit();
        $this->context()->forUser($this->admin);
        config(['device_analysis.lab_enabled' => true, 'device_analysis.lab_tenants' => [$this->a->id, $this->b->id],
            'device_readonly_lab.enabled' => true, 'device_readonly_lab.routeros_protocol' => true,
            'device_readonly_lab.tenants' => [$this->a->id, $this->b->id], 'llm.key_file' => '/lab/vault']);
        Http::preventStrayRequests();
        file_put_contents('/lab/rollback-tenant', (string) $this->a->id);
        file_put_contents('/lab/central-scope.json', json_encode(['tenants' => [$this->a->id, $this->b->id]]));
        file_put_contents('/lab/scenario', 'ros-normal');
        $this->device = app(Inventory::class)->save($this->admin, ['name' => 'MikroTik de borda', 'hostname' => 'mikrotik-fixture.lab.example', 'management_ip' => '192.0.2.11',
            'manufacturer' => 'mikrotik', 'platform' => 'routeros7', 'model' => 'CCR2004-16G-2S+', 'os_version' => '7.16.2', 'category' => 'router',
            'network_role' => 'Borda', 'pop' => 'LAB', 'environment' => 'lab', 'admin_status' => 'active']);
        $id = (string) Str::uuid();
        $this->token = $id.'.'.bin2hex(random_bytes(32));
        $this->agent = (new Agent)->forceFill(['id' => $id, 'tenant_id' => $this->a->id, 'name' => 'Python candidate · SSH SIMULADO', 'hostname' => 'isolated-lab', 'status' => 'active', 'version' => '0.6.4',
            'token_hash' => hash('sha256', $this->token), 'enrolled_at' => now(), 'last_seen_ip' => '127.0.0.1', 'last_heartbeat_at' => now(),
            'capabilities' => [Integration::CAPABILITY], 'metadata' => ['task_protocol' => 1, 'managed_settings' => ['revision' => 1, 'source_ips' => ['127.0.0.1'], 'auto_public_targets' => false, 'allowed_networks' => [], 'allowed_dns_names' => []]]]);
        $this->agent->save();
        app(Access::class)->approve($this->admin, $this->device, file_get_contents('/lab/host.pub'), file_get_contents('/lab/client'));
        (new ConversationSetting)->forceFill(['tenant_id' => $this->a->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $this->admin->id])->save();
        $this->privateFile('state/identity.json', ['agent_id' => $id, 'token' => $this->token]);
        $this->privateFile('config.json', ['central_url' => 'http://127.0.0.1:8089', 'allow_http_loopback' => true,
            'state_dir' => '/lab/agent-private/state', 'identity_file' => '/lab/agent-private/state/identity.json', 'log_dir' => '/lab/agent-private/logs',
            'heartbeat_interval' => 2, 'poll_interval' => 1, 'timeout' => 3, 'auto_public_targets' => false, 'allowed_networks' => [], 'allowed_dns_names' => [], 'allow_managed_settings' => false, 'device_operations_frozen' => true]);
        $this->waitFor(fn () => $this->agent->fresh()->last_poll_at !== null, 'candidate first authenticated poll');
    }

    private function privateFile(string $name, array $value): void
    {
        $path = '/lab/agent-private/'.$name;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
            chown(dirname($path), 65534);
            chgrp(dirname($path), 65534);
        }
        $temporary = $path.'.new';
        file_put_contents($temporary, json_encode($value, JSON_THROW_ON_ERROR));
        chmod($temporary, 0600);
        chown($temporary, 65534);
        chgrp($temporary, 65534);
        rename($temporary, $path);
    }

    private function waitFor(callable $condition, string $what, int $seconds = 25): void
    {
        $end = microtime(true) + $seconds;
        do {
            app(Engine::class)->tick();
            if ($condition()) {
                return;
            }
            usleep(100000);
        } while (microtime(true) < $end);
        $this->fail('Timed out waiting for '.$what);
    }

    private function authorize(Operation $op, array $params = []): array
    {
        $this->context()->forUser($this->admin);
        app(Approval::class)->attest($this->admin, $this->device->fresh(), $this->agent->fresh(), $op, $params, ['read', 'ssh'], app(Approval::class)->fingerprint(DeviceLabAccess::where('device_id', $this->device->id)->sole()->host_public_key), Integration::EXECUTOR);
        $decision = app(Preflight::class)->device($this->admin, $this->device->fresh(), $this->agent->fresh(), $op, $params);
        $this->assertSame('ALLOWED', $decision['state']);
        $input = $decision['input'];
        $this->provision($input);

        return $input;
    }

    private function provision(array $input, ?string $key = null): void
    {
        $this->privateFile($this->device->id.'.json', ['binding' => $input['binding'], 'binding_digest' => $input['binding_digest'],
            'private_key' => $key ?? app(SecretVault::class)->decrypt(DeviceLabAccess::where('device_id', $this->device->id)->sole())]);
    }

    private function runQuery(Operation $op, array $params = [], string $scenario = 'normal', ?string $expectedError = null): Diagnostic
    {
        file_put_contents('/lab/scenario', 'ros-'.$scenario);
        $input = $this->authorize($op, $params);
        $identity = Identity::web($this->admin);
        $conversation = app(ConversationEngine::class)->create($identity, (string) Str::uuid(), $this->agent->id);
        $request = match ($op) {
            Operation::Identity => 'Verifique a identificação do roteador MikroTik de borda.',
            Operation::Bgp => 'Verifique os peers BGP do MikroTik de borda.',
            Operation::Route => 'Consulte a tabela de rotas do roteador MikroTik de borda para '.$params['destination'].'.',
            Operation::Interfaces => 'Verifique as interfaces do roteador MikroTik de borda.',
            Operation::Counters => 'Verifique os contadores do roteador MikroTik de borda.',
            Operation::Resources => 'Verifique CPU e memória do roteador MikroTik de borda.',
        };
        $conversation = app(ConversationEngine::class)->send($identity, $conversation, (string) Str::uuid(), $request);
        $this->assertSame('WAITING_FOR_CONFIRMATION', $conversation->state);
        $this->assertSame($op->value, $conversation->context_json['pending']['routeros_input']['operation']);
        $conversation = app(ConversationEngine::class)->send($identity, $conversation, (string) Str::uuid(), 'confirmo');
        $d = Diagnostic::findOrFail($conversation->current_diagnostic_id);
        app(Engine::class)->tick();
        $this->waitFor(fn () => ! in_array($d->fresh()->status, ['PENDING', 'RUNNING']), $op->value.' '.$scenario);
        $step = DiagnosticStep::where('diagnostic_id', $d->id)->sole();
        $this->assertSame($expectedError === null, $step->result['ok'], json_encode($step->result));
        if ($expectedError !== null) {
            $this->assertSame($expectedError, $step->result['error']);
        } else {
            $this->assertSame('SIMULATED', $step->result['data']['mode']);
            $this->assertSame($op->value, $step->result['data']['contract_id']);
            $this->assertSame($input['binding_digest'], $step->result['data']['binding_digest']);
        }
        $this->assertSame(1, AgentTask::where('diagnostic_id', $d->id)->count());
        $this->assertSame('INCONCLUSIVO', DiagnosticConclusion::where('diagnostic_id', $d->id)->sole()->classification);
        app(ConversationEngine::class)->sync($identity, $conversation);
        $reply = ConversationMessage::where('conversation_id', $conversation->id)->where('message_type', 'result')->sole();
        $this->assertStringContainsString('LABORATÓRIO / SIMULADO', $reply->body);
        $this->proof[] = ['operation' => $op->value, 'scenario' => $scenario, 'request' => $request, 'reply' => $reply->body, 'task_id' => AgentTask::where('diagnostic_id', $d->id)->sole()->id, 'result' => $step->result];

        return $d->fresh();
    }

    public function test_complete_runtime_api_ssh_and_conversation_flow_with_security_boundaries(): void
    {
        $engine = app(ConversationEngine::class);
        $i = Identity::web($this->admin);
        $this->authorize(Operation::Bgp);
        $c = $engine->create($i, (string) Str::uuid(), $this->agent->id);
        $c = $engine->send($i, $c, (string) Str::uuid(), 'Verifique os peers BGP do MikroTik de borda.');
        $this->assertSame('WAITING_FOR_CONFIRMATION', $c->state);
        $this->assertSame(Operation::Bgp->value, $c->context_json['pending']['routeros_input']['operation']);
        $this->assertSame(0, AgentTask::count());
        $confirmKey = (string) Str::uuid();
        $c = $engine->send($i, $c, $confirmKey, 'confirmo');
        $this->assertNotNull($c->current_diagnostic_id);
        $duplicate = $engine->send($i, $c, $confirmKey, 'confirmo');
        $this->assertSame($c->current_diagnostic_id, $duplicate->current_diagnostic_id);
        app(Engine::class)->tick();
        $this->waitFor(fn () => Diagnostic::findOrFail($c->current_diagnostic_id)->status === 'COMPLETED', 'conversational BGP task');
        $c = $engine->sync($i, $c);
        $result = ConversationMessage::where('conversation_id', $c->id)->where('message_type', 'result')->sole();
        $this->assertStringContainsString('Peers BGP: 2', $result->body);
        $this->assertStringContainsString('SIMULADO', $result->body);
        $this->assertTrue($result->metadata['simulation']);
        $this->assertSame(1, AgentTask::where('diagnostic_id', $c->current_diagnostic_id)->count());
        $web = $this->actingAs($this->admin)->get('/diagnostics/'.$c->current_diagnostic_id)->assertOk();
        $web->assertSee('LABORATÓRIO / SIMULADO')->assertSee('DEVICE_BGP_PEERS_READ');
        $snapshot = preg_replace('/(<meta name="csrf-token" content=")[^"]+/', '$1LAB_REDACTED', $web->getContent());
        $snapshot = preg_replace('/(name="_token" value=")[^"]+/', '$1LAB_REDACTED', $snapshot);
        file_put_contents('/lab/routeros-task-panel.html', $snapshot);
        file_put_contents('/lab/routeros-task-web.json', json_encode(['request' => 'Verifique os peers BGP do MikroTik de borda.', 'confirmation' => 'DEVICE_BGP_PEERS_READ', 'reply' => $result->body, 'single_task' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        foreach (Operation::cases() as $op) {
            $this->runQuery($op, $op === Operation::Route ? ['destination' => '8.8.8.8'] : []);
        }
        foreach (['auth' => 'routeros_auth_failed', 'timeout' => 'timeout', 'truncate' => 'routeros_invalid_output', 'invalid' => 'routeros_invalid_output', 'sensitive' => 'routeros_invalid_output', 'rows' => 'routeros_output_limit', 'identity' => 'routeros_identity_rejected', 'oversize' => 'routeros_output_limit'] as $scenario => $error) {
            $this->runQuery(Operation::Bgp, scenario: $scenario, expectedError: $error);
        }
        $this->telegramFlow();
        $this->extraSecurity();
        $serialized = json_encode($this->proof);
        $this->assertStringNotContainsString('FIXTURE_REDACT_ME', $serialized);
        $this->assertStringNotContainsString('PRIVATE KEY', $serialized);
        file_put_contents('/lab/routeros-task-results.json', json_encode($this->proof, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        file_put_contents('/lab/scenario', 'ros-normal');
        // Preflight denies without opening a transport or substituting a different capability.
        $input = $this->authorize(Operation::Bgp);
        $before = AgentTask::count();
        $connections = count(file('/lab/connections.jsonl'));
        app(Access::class)->revoke($this->admin, $this->device);
        $denied = $engine->create($i, (string) Str::uuid(), $this->agent->id);
        $denied = $engine->send($i, $denied, (string) Str::uuid(), 'Verifique os peers BGP do MikroTik de borda.');
        $this->assertArrayNotHasKey('pending', $denied->context_json);
        $this->assertSame($before, AgentTask::count());
        $this->assertSame($connections, count(file('/lab/connections.jsonl')));
        $this->assertSame('DENIED', app(Preflight::class)->device($this->admin, $this->device->fresh(), $this->agent->fresh(), Operation::Bgp, [])['state']);
        $this->assertSame('policy_denied', app(Preflight::class)->deviceTask($this->agent, $input));
        $this->context()->forUser($this->viewer);
        $this->assertSame('DENIED', app(Preflight::class)->device($this->viewer, $this->device, $this->agent->fresh(), Operation::Bgp, [])['state']);
        $this->context()->forUser($this->admin);
        foreach (DB::select("SELECT relname,relforcerowsecurity FROM pg_class WHERE relname IN ('noc_devices','device_lab_accesses','diagnostics','diagnostic_steps','agent_tasks','agent_task_attempts','diagnostic_plans','diagnostic_conclusions')") as $table) {
            $this->assertTrue($table->relforcerowsecurity);
        }
        $this->device->forceFill(['environment' => 'production'])->save();
        $this->assertSame('DENIED', app(Preflight::class)->device($this->admin, $this->device->fresh(), $this->agent->fresh(), Operation::Bgp, [])['state']);
        $this->device->forceFill(['environment' => 'lab'])->save();
        $this->context()->forUser($this->other);
        $this->assertNull(NocDevice::find($this->device->id));
        $this->assertNull(NocDevice::withoutGlobalScopes()->find($this->device->id));
        $this->assertSame(0, AgentTask::where('agent_id', $this->agent->id)->count());
        $this->context()->forUser($this->admin);
        config(['app.env' => 'production']);
        $this->assertFalse(Integration::enabled());
        $this->assertNotContains(Integration::CAPABILITY, Protocol::allowedCapabilities());
        $this->assertTrue(Protocol::policy($this->agent->id)['device_operations_frozen']);
        config(['app.env' => 'testing']);
        $this->assertTrue(Integration::enabled());
        $connections = count(file('/lab/connections.jsonl'));
        try {
            app(Ssh::class)->collect(Platform::RouterOs, \App\Noc\ReadOnlyLab\Operation::Resources, [], 'unused', 'unused');
            $this->fail('The Laravel SSH alternative must be closed in the integrated lab.');
        } catch (Failure $failure) {
            $this->assertSame('ROUTEROS_AGENT_REQUIRED', $failure->reason);
        }
        $this->assertSame($connections, count(file('/lab/connections.jsonl')));
        file_put_contents('/lab/routeros-task-engine.json', json_encode(['task_protocol' => 1, 'transport' => 'Real HTTP over isolated loopback; native Agent API authentication', 'execution' => 'Unmodified Runtime/Executor/action_worker/journal/lease protocol', 'tasks' => AgentTask::count(), 'attempts' => DB::table('agent_task_attempts')->count(), 'conclusions' => DiagnosticConclusion::count(), 'freeze_operational' => true, 'physical_device_access' => false, 'preflight_denied_no_connection' => true], JSON_PRETTY_PRINT));
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'Peers BGP: 2') && str_contains($request['text'], 'SIMULADO'));
    }

    public function test_identity_only_physical_preparation_stays_simulated_and_blocks_other_capabilities(): void
    {
        $d = $this->runQuery(Operation::Identity);
        $input = DiagnosticStep::where('diagnostic_id', $d->id)->sole()->definition['input'];
        $this->assertSame($d->id, app(Integration::class)->enqueue($this->admin, $this->agent->fresh(), $input)->id);
        $this->assertSame(1, AgentTask::where('diagnostic_id', $d->id)->count());
        $connections = count(file('/lab/connections.jsonl'));
        foreach (Operation::cases() as $op) {
            if ($op === Operation::Identity) {
                continue;
            }
            $decision = app(Preflight::class)->device($this->admin, $this->device->fresh(), $this->agent->fresh(), $op, $op === Operation::Route ? ['destination' => '8.8.8.8'] : []);
            $this->assertSame('DENIED', $decision['state']);
        }
        $this->context()->forUser($this->viewer);
        $this->assertSame('DENIED', app(Preflight::class)->device($this->viewer, $this->device, $this->agent->fresh(), Operation::Identity, [])['state']);
        $this->context()->forUser($this->other);
        $this->assertNull(NocDevice::withoutGlobalScopes()->find($this->device->id));
        $this->context()->forUser($this->admin);
        $bad = $input;
        $bad['binding']['equipment_id'] = (string) Str::uuid();
        $this->assertSame('policy_denied', app(Preflight::class)->deviceTask($this->agent, $bad));
        $row = DeviceLabAccess::where('device_id', $this->device->id)->sole();
        $proof = $row->protocol_approval;
        $proof['expires_at'] = now()->subMinute()->toIso8601String();
        $row->forceFill(['protocol_approval' => $proof])->save();
        $this->assertSame('STALE', app(Preflight::class)->device($this->admin, $this->device, $this->agent->fresh(), Operation::Identity, [])['state']);
        $this->assertSame($connections, count(file('/lab/connections.jsonl')));
        $wrong = RSA::createKey(2048);
        app(Access::class)->approve($this->admin, $this->device, $wrong->getPublicKey()->toString('OpenSSH'), file_get_contents('/lab/client'));
        $this->runQuery(Operation::Identity, expectedError: 'routeros_identity_rejected');
        app(Access::class)->approve($this->admin, $this->device, file_get_contents('/lab/host.pub'), file_get_contents('/lab/client'));
        $this->runQuery(Operation::Identity, scenario: 'timeout', expectedError: 'timeout');
        @unlink('/lab/query-held');
        @unlink('/lab/query-release');
        file_put_contents('/lab/scenario', 'ros-hold');
        $input = $this->authorize(Operation::Identity);
        $cancelled = app(Integration::class)->enqueue($this->admin, $this->agent->fresh(), $input);
        app(Engine::class)->tick();
        $this->waitFor(fn () => is_file('/lab/query-held'), 'identity cancellation barrier');
        app(Engine::class)->cancel($cancelled, $this->admin);
        $this->waitFor(fn () => $cancelled->fresh()->status === 'CANCELLED', 'identity cancellation');
        $this->assertTrue(Protocol::policy($this->agent->id)['device_operations_frozen']);
        file_put_contents('/lab/physical-identity-offline.json', json_encode(['mode' => 'SIMULATED', 'physical_ready' => false, 'operation' => Operation::Identity->value, 'other_five_denied' => true, 'cases' => ['identity', 'wrong_host_key', 'viewer', 'other_equipment', 'other_tenant', 'other_capabilities', 'expired_policy', 'duplicate', 'timeout', 'cancellation'], 'queries' => $this->proof, 'device_freeze' => true, 'physical_connections' => 0], JSON_PRETTY_PRINT));
    }

    private function telegramFlow(): void
    {
        file_put_contents('/lab/scenario', 'ros-normal');
        config(['telegram.enabled' => true, 'app.url' => 'https://central.example.test']);
        Queue::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['id' => 123456789, 'is_bot' => true, 'username' => 'lab_fixture_bot', 'message_id' => 77]])]);
        $accounts = app(Accounts::class);
        $bot = $accounts->configure($this->admin, '123456789:'.str_repeat('f', 35));
        $accounts->register($bot, $this->admin, true);
        $serial = 3000;
        $receive = function (string $text) use ($bot, &$serial) {
            $serial++;
            $payload = ['update_id' => $serial, 'message' => ['message_id' => $serial, 'date' => now()->timestamp, 'chat' => ['id' => 12345, 'type' => 'private'], 'from' => ['id' => 12345, 'is_bot' => false], 'text' => $text]];
            $request = Request::create('/api/telegram', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => Crypt::decryptString($bot->secret_cipher)], content: json_encode($payload));
            app(Ingress::class)->accept($request, $this->a->id, $bot->id);
            $this->context()->forUser($this->admin);
            foreach (Update::where('status', 'PENDING')->pluck('id') as $id) {
                app(Runner::class)->process($this->a->id, $id);
                $this->context()->forUser($this->admin);
            }
        };
        $receive('/start '.$accounts->issue($this->admin, $bot));
        $link = Link::where('status', 'AWAITING_APPROVAL')->sole();
        $accounts->approve($link, $this->admin);
        $this->authorize(Operation::Bgp);
        $receive('Verifique os peers BGP do MikroTik de borda.');
        $c = Conversation::findOrFail($link->fresh()->conversation_id);
        $this->assertSame('WAITING_FOR_CONFIRMATION', $c->state);
        $receive('confirmo');
        $c->refresh();
        $this->assertNotNull($c->current_diagnostic_id);
        app(Engine::class)->tick();
        $this->waitFor(fn () => Diagnostic::findOrFail($c->current_diagnostic_id)->status === 'COMPLETED', 'Telegram BGP result');
        app(Sweep::class)->run($this->a->id);
        $this->context()->forUser($this->admin);
        $result = ConversationMessage::where('conversation_id', $c->id)->where('message_type', 'result')->sole();
        $this->assertStringContainsString('Peers BGP: 2', $result->body);
        foreach (Outbound::where('status', 'PENDING')->pluck('id') as $id) {
            (new Deliver($this->a->id, $id))->handle(app(Client::class));
            $this->context()->forUser($this->admin);
        }
        $this->assertSame('SENT', Outbound::where('message_id', $result->id)->sole()->status);
        file_put_contents('/lab/routeros-task-telegram.json', json_encode(['native_ingress' => true, 'native_outbox' => true, 'external_transport' => 'HTTP fake; no Telegram API connection', 'reply' => $result->body, 'single_task' => AgentTask::where('diagnostic_id', $c->current_diagnostic_id)->count() === 1], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function extraSecurity(): void
    {
        $integration = app(Integration::class);
        $tasks = app(Engine::class);
        // A separately approved but incorrect host key must fail before public-key authentication.
        $wrong = RSA::createKey(2048);
        app(Access::class)->approve($this->admin, $this->device, $wrong->getPublicKey()->toString('OpenSSH'), file_get_contents('/lab/client'));
        $this->runQuery(Operation::Bgp, expectedError: 'routeros_identity_rejected');
        app(Access::class)->approve($this->admin, $this->device, file_get_contents('/lab/host.pub'), file_get_contents('/lab/client'));
        $input = $this->authorize(Operation::Bgp);
        $this->provision($input, $wrong->toString('PKCS1'));
        $d = $integration->enqueue($this->admin, $this->agent->fresh(), $input);
        $tasks->tick();
        $this->waitFor(fn () => $d->fresh()->status === 'COMPLETED', 'invalid client credential');
        $this->assertSame('routeros_auth_failed', DiagnosticStep::where('diagnostic_id', $d->id)->sole()->result['error']);

        $row = DeviceLabAccess::where('device_id', $this->device->id)->sole();
        $approval = $row->protocol_approval;
        $approval['expires_at'] = now()->subMinute()->toIso8601String();
        $row->forceFill(['protocol_approval' => $approval])->save();
        $this->assertSame('STALE', app(Preflight::class)->device($this->admin, $this->device, $this->agent->fresh(), Operation::Bgp, [])['state']);
        $this->authorize(Operation::Bgp);
        // Keep the initial replay test bound to a fresh authorization after the explicit expiry case.
        $input = $this->authorize(Operation::Bgp);
        $d = $integration->enqueue($this->admin, $this->agent->fresh(), $input);
        $tasks->tick();
        $this->waitFor(fn () => $d->fresh()->status === 'COMPLETED', 'fresh approval after expiry');

        // Same approval cannot materialize a second task, including after completion.
        $this->assertSame($d->id, $integration->enqueue($this->admin, $this->agent->fresh(), $input)->id);
        $this->assertSame(1, AgentTask::where('diagnostic_id', $d->id)->count());

        foreach (['operation', 'parameters', 'equipment', 'agent', 'tenant', 'destination', 'fingerprint', 'expiry'] as $mutation) {
            $input = $this->authorize(Operation::Bgp);
            $connections = count(file('/lab/connections.jsonl'));
            $d = DB::transaction(function () use ($integration, $tasks, $input, $mutation) {
                $d = $integration->enqueue($this->admin, $this->agent->fresh(), $input);
                $tasks->tick();
                $task = AgentTask::where('diagnostic_id', $d->id)->sole();
                $bad = $task->input;
                match ($mutation) {
                    'operation' => $bad['operation'] = '/system reboot',
                    'parameters' => $bad['parameters'] = ['command' => '/system reboot'],
                    'equipment' => $bad['binding']['equipment_id'] = (string) Str::uuid(),
                    'agent' => $bad['binding']['agent_id'] = (string) Str::uuid(),
                    'tenant' => $bad['binding']['tenant_id'] = $this->b->id,
                    'destination' => $bad['binding']['destination'] = '192.0.2.11',
                    'fingerprint' => $bad['binding']['fingerprint'] = 'SHA256:unapproved',
                    'expiry' => $bad['binding']['expires_at'] = now()->subMinute()->timestamp,
                };
                $task->forceFill(['input' => $bad])->save();

                return $d;
            });
            $this->waitFor(fn () => AgentTask::where('diagnostic_id', $d->id)->sole()->status === 'FAILED', 'tampered '.$mutation);
            $tasks->tick();
            $this->assertSame('policy_denied', DiagnosticStep::where('diagnostic_id', $d->id)->sole()->error_code);
            $this->assertSame($connections, count(file('/lab/connections.jsonl')));
        }

        // Policy changes while real SSH is running invalidate its successful response.
        foreach (['policy', 'cancel'] as $action) {
            @unlink('/lab/query-held');
            @unlink('/lab/query-release');
            file_put_contents('/lab/scenario', 'ros-hold');
            $input = $this->authorize(Operation::Bgp);
            $d = $integration->enqueue($this->admin, $this->agent->fresh(), $input);
            $tasks->tick();
            $this->waitFor(fn () => is_file('/lab/query-held'), 'SSH barrier '.$action);
            if ($action === 'policy') {
                $a = $this->agent->fresh();
                $meta = $a->metadata;
                $meta['managed_settings']['revision']++;
                $a->forceFill(['metadata' => $meta])->save();
                file_put_contents('/lab/query-release', 'release');
            } else {
                $tasks->cancel($d, $this->admin);
            }
            $this->waitFor(fn () => ! in_array(AgentTask::where('diagnostic_id', $d->id)->sole()->status, ['RUNNING', 'CLAIMED', 'QUEUED']), 'fenced '.$action);
            $tasks->tick();
            $step = DiagnosticStep::where('diagnostic_id', $d->id)->sole();
            $this->assertSame($action === 'policy' ? 'policy_denied' : 'CANCELLED', $action === 'policy' ? $step->result['error'] : $step->status);
            file_put_contents('/lab/query-release', 'release');
        }
        file_put_contents('/lab/scenario', 'ros-normal');

        // Two queued tasks share the same Agent execution lane and keep independent leases.
        $input = $this->authorize(Operation::Bgp);
        $one = $integration->enqueue($this->admin, $this->agent->fresh(), $input);
        $this->assertSame($one->id, $integration->enqueue($this->admin, $this->agent->fresh(), $input)->id);
        $tasks->tick();
        $this->waitFor(fn () => $one->fresh()->status === 'COMPLETED', 'single replay-fenced authorization');
        $this->assertSame(1, AgentTask::where('diagnostic_id', $one->id)->count());
        $this->assertSame(1, DB::table('agent_task_attempts')->where('task_id', AgentTask::where('diagnostic_id', $one->id)->sole()->id)->count());
        // Distinct equipment approvals still share one Agent lane.
        $firstInput = $this->authorize(Operation::Bgp);
        $first = $integration->enqueue($this->admin, $this->agent->fresh(), $firstInput);
        $original = $this->device;
        $this->device = app(Inventory::class)->save($this->admin, ['name' => 'Segundo MikroTik fixture', 'hostname' => 'second-fixture.lab.example', 'management_ip' => '192.0.2.12', 'manufacturer' => 'mikrotik', 'platform' => 'routeros7', 'model' => 'CCR2004-16G-2S+', 'os_version' => '7.16.2', 'category' => 'router', 'network_role' => 'Borda', 'pop' => 'LAB', 'environment' => 'lab', 'admin_status' => 'active']);
        $this->assertSame('UNKNOWN', app(Preflight::class)->device($this->admin, $this->device, $this->agent->fresh(), Operation::Bgp, [])['state']);
        app(Access::class)->approve($this->admin, $this->device, file_get_contents('/lab/host.pub'), file_get_contents('/lab/client'));
        $secondInput = $this->authorize(Operation::Bgp);
        $second = $integration->enqueue($this->admin, $this->agent->fresh(), $secondInput);
        $this->device = $original;
        @unlink('/lab/query-held');
        @unlink('/lab/query-release');
        file_put_contents('/lab/scenario', 'ros-hold');
        $tasks->tick();
        $this->waitFor(fn () => is_file('/lab/query-held'), 'concurrent lane barrier');
        $this->assertSame(1, AgentTask::whereIn('diagnostic_id', [$first->id, $second->id])->where('status', 'RUNNING')->count());
        $this->assertSame(1, AgentTask::whereIn('diagnostic_id', [$first->id, $second->id])->where('status', 'QUEUED')->count());
        file_put_contents('/lab/query-release', 'release');
        $this->waitFor(fn () => $first->fresh()->status === 'COMPLETED' && $second->fresh()->status === 'COMPLETED', 'serialized tasks');
        file_put_contents('/lab/scenario', 'ros-normal');
        $this->assertSame(2, AgentTask::whereIn('diagnostic_id', [$first->id, $second->id])->where('status', 'COMPLETED')->count());
        $wrongAgent = (new Agent)->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'name' => 'Unapproved executor fixture', 'hostname' => 'other-lab', 'status' => 'active', 'version' => '0.6.4', 'last_heartbeat_at' => now(), 'token_hash' => hash('sha256', random_bytes(32)), 'capabilities' => [Integration::CAPABILITY], 'metadata' => ['task_protocol' => 1]]);
        $wrongAgent->save();
        $this->assertSame('DENIED', app(Preflight::class)->device($this->admin, $this->device, $wrongAgent, Operation::Bgp, [])['state']);
        $this->proof[] = ['security' => 'wrong host/client key, eight tampered envelopes, policy changed during SSH, cancellation, approval replay', 'status' => 'PASS'];
    }
}
