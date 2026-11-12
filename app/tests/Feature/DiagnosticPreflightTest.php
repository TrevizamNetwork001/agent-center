<?php

namespace Tests\Feature;

use App\Agent\ManagedSettings;
use App\Agent\Protocol;
use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Diagnostics\Catalog;
use App\Diagnostics\OperationalCapabilities;
use App\Diagnostics\Preflight;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\ConversationMessage;
use App\Models\ConversationSetting;
use App\Models\Diagnostic;
use App\Models\DiagnosticStep;
use App\Models\Profile;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;

class DiagnosticPreflightTest extends IdentityTestCase
{
    private function agent(string $version = '0.6.5'): array
    {
        $this->context()->forUser($this->operator);
        $id = (string) Str::uuid();
        $token = $id.'.'.bin2hex(random_bytes(32));
        $a = new Agent;
        $a->forceFill(['id' => $id, 'tenant_id' => $this->a->id, 'name' => 'Preflight lab', 'hostname' => 'lab', 'version' => $version, 'status' => 'active', 'token_hash' => hash('sha256', $token), 'capabilities' => Protocol::CAPABILITIES, 'last_heartbeat_at' => now(), 'metadata' => ['task_protocol' => 1, 'mtr_capability_confirmed_at' => now()->toIso8601String(), 'managed_settings' => ['revision' => 1, 'source_ips' => ['127.0.0.1'], 'auto_public_targets' => false, 'allowed_networks' => ['8.8.8.8/32'], 'allowed_dns_names' => []]]])->save();

        return [$a, $token];
    }

    private function request(Agent $a, string $ip = '8.8.8.8', string $protocol = 'icmp'): array
    {
        $this->context()->forUser($this->operator);
        app(Preflight::class)->permission($a, 'mtr_path_analysis:v1', $ip, $protocol);

        app(Preflight::class)->exchange($a, null);

        return $a->fresh()->metadata['diagnostic_preflight_v1']['request'];
    }

    private function decision(array $request, string $state = 'ALLOWED'): array
    {
        return ['schema_version' => 1, 'id' => $request['id'], 'state' => $state, 'reason' => $state === 'ALLOWED' ? 'authorized' : ($state === 'DENIED' ? 'policy_denied' : 'unverified'), 'helper_healthy' => $state === 'ALLOWED' ? true : null, 'agent_version' => '0.6.5', 'policy_digest' => $request['policy_digest'], 'helper_version' => '0.6.5', 'effective_revision' => str_repeat('a', 64)];
    }

    public function test_authenticated_read_only_roundtrip_and_idempotence(): void
    {
        [$a,$token] = $this->agent();
        $request = $this->request($a);
        $result = $this->decision($request);
        $url = '/api/v1/agent/diagnostics/preflight';
        $this->postJson($url, ['schema_version' => 1])->assertUnauthorized();
        $this->withToken($token)->postJson($url, ['schema_version' => 1])->assertOk()->assertJsonPath('request.id', $request['id']);
        $this->withToken($token)->postJson($url, ['schema_version' => 1, 'result' => $result])->assertOk();
        $this->context()->forUser($this->operator);
        $first = $a->fresh()->metadata['diagnostic_preflight_v1']['received_at'];
        $this->travel(2)->seconds();
        $this->withToken($token)->postJson($url, ['schema_version' => 1, 'result' => $result])->assertOk();
        $this->context()->forUser($this->operator);
        $this->assertSame($first, $a->fresh()->metadata['diagnostic_preflight_v1']['received_at']);
        $this->assertSame('ALLOWED', app(OperationalCapabilities::class)->permission($a, 'mtr_path_analysis:v1', '8.8.8.8', 'icmp')['state']);
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_expiry_policy_destination_protocol_version_and_revocation_fail_closed(): void
    {
        [$a] = $this->agent();
        $request = $this->request($a);
        app(Preflight::class)->exchange($a, $this->decision($request));
        $read = fn ($ip = '8.8.8.8', $protocol = 'icmp') => app(Preflight::class)->permission($a, 'mtr_path_analysis:v1', $ip, $protocol)['state'];
        $this->assertSame('UNKNOWN', $read('1.1.1.1'));
        $this->assertSame('UNKNOWN', $read('8.8.8.8', 'tcp'));
        $this->travel(11)->seconds();
        $this->assertSame('UNKNOWN', $read());
        $a->refresh();
        $meta = $a->metadata;
        $settings = ManagedSettings::current($a);
        $settings['revision']++;
        $a->forceFill(['metadata' => [...$meta, 'managed_settings' => $settings]])->save();
        $this->assertSame('UNKNOWN', $read());
        $a->forceFill(['version' => '0.6.2'])->save();
        $this->assertSame('UNKNOWN', $read());
        $a->forceFill(['revoked_at' => now()])->save();
        $this->assertSame('agent_unavailable', app(OperationalCapabilities::class)->path($a, 'mtr_path_analysis:v1', '8.8.8.8', 'icmp'));
    }

    public function test_denied_is_distinct_from_unknown_and_helper_failure(): void
    {
        [$a] = $this->agent();
        $r = $this->request($a);
        $p = app(Preflight::class);
        $p->exchange($a, $this->decision($r, 'DENIED'));
        $this->assertSame('policy_denied', app(OperationalCapabilities::class)->path($a, 'mtr_path_analysis:v1', '8.8.8.8', 'icmp'));
        $this->travel(11)->seconds();
        $r = $this->request($a);
        $result = $this->decision($r, 'UNKNOWN');
        $result['reason'] = 'helper_unavailable';
        $result['helper_healthy'] = false;
        $p->exchange($a, $result);
        $this->assertSame('helper_unavailable', app(OperationalCapabilities::class)->path($a, 'mtr_path_analysis:v1', '8.8.8.8', 'icmp'));
    }

    public function test_payload_tampering_cross_agent_and_rls(): void
    {
        [$a,$token] = $this->agent();
        $r = $this->request($a);
        $url = '/api/v1/agent/diagnostics/preflight';
        $bad = $this->decision($r);
        $bad['id'] = (string) Str::uuid();
        $this->withToken($token)->postJson($url, ['schema_version' => 1, 'result' => $bad])->assertUnprocessable();
        $bad = $this->decision($r);
        $bad['helper_healthy'] = false;
        $this->withToken($token)->postJson($url, ['schema_version' => 1, 'result' => $bad])->assertUnprocessable();
        $this->withToken($token)->postJson($url, ['schema_version' => 1, 'tenant_id' => $this->b->id])->assertUnprocessable();
        $this->withToken($token)->postJson($url, ['schema_version' => 1, 'result' => [...$this->decision($r), 'command' => 'mtr']])->assertUnprocessable();
        $this->context()->forUser($this->other);
        $this->assertNull(Agent::find($a->id));
        $this->context()->apply();
        $this->assertSame(0, Agent::count());
    }

    public function test_web_confirmation_requires_current_proof_and_consumes_no_stale_decision(): void
    {
        [$a] = $this->agent();
        app(Catalog::class)->ensure();
        (new ConversationSetting)->forceFill(['tenant_id' => $this->a->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $this->admin->id])->save();
        $engine = app(Engine::class);
        $i = Identity::web($this->operator);
        $c = $engine->create($i, (string) Str::uuid(), $a->id);
        $c = $engine->send($i, $c, (string) Str::uuid(), 'Quero analisar a rota para 8.8.8.8');
        $this->assertArrayNotHasKey('pending', $c->context_json);
        $r = $a->fresh()->metadata['diagnostic_preflight_v1']['request'];
        app(Preflight::class)->exchange($a, null);
        app(Preflight::class)->exchange($a, $this->decision($r));
        $c = $engine->send($i, $c, (string) Str::uuid(), 'Quero analisar a rota para 8.8.8.8');
        $this->assertArrayHasKey('pending', $c->context_json);
        $this->assertStringContainsString('Confirma a execução?', ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->value('body'));
        $this->travel(11)->seconds();
        $c = $engine->send($i, $c, (string) Str::uuid(), 'confirmo');
        $this->assertArrayNotHasKey('pending', $c->context_json);
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_task_start_revalidates_policy_after_claim_without_executing(): void
    {
        [$agent] = $this->agent();
        app(Catalog::class)->ensure();
        $request = $this->request($agent);
        app(Preflight::class)->exchange($agent, $this->decision($request));
        $profile = Profile::where('key', 'MTR_ICMP')->sole();
        $engine = app(\App\Diagnostics\Engine::class);
        $diagnostic = $engine->create($profile, $agent, '8.8.8.8', $this->operator);
        $engine->tick();
        $task = $engine->claim($agent);
        $this->assertNotNull($task);
        $envelope = array_intersect_key($task, array_flip(['task_id', 'execution_id', 'generation', 'lease_token']));
        $agent->refresh();
        $settings = ManagedSettings::current($agent);
        $settings['revision']++;
        $agent->forceFill(['metadata' => [...$agent->metadata, 'managed_settings' => $settings]])->save();
        $reply = $engine->operation($agent, 'start', $envelope);
        $this->assertSame('blocked', $reply['status']);
        $this->assertSame('effective_policy_unverified', $reply['reason']);
        $this->assertSame('FAILED', AgentTask::sole()->status);
        $this->assertSame('BLOCKED', DiagnosticStep::where('diagnostic_id', $diagnostic->id)->sole()->status);
    }

    public function test_single_pending_slot_preserves_concurrent_request_identity(): void
    {
        [$agent] = $this->agent();
        $first = $this->request($agent);
        $this->request($agent, '1.1.1.1');
        $this->assertSame($first, $agent->fresh()->metadata['diagnostic_preflight_v1']['request']);
        $this->assertSame('UNKNOWN', app(Preflight::class)->permission($agent, 'mtr_path_analysis:v1', '1.1.1.1', 'icmp')['state']);
        $this->assertSame(0, AgentTask::count());
    }
}
