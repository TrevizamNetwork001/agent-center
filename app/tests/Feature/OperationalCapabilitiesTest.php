<?php

namespace Tests\Feature;

use App\Agent\Protocol;
use App\Conversation\Engine as Conversations;
use App\Conversation\Identity;
use App\Diagnostics\Catalog;
use App\Diagnostics\IntentParser;
use App\Diagnostics\IntentPlanning;
use App\Diagnostics\OperationalCapabilities;
use App\Diagnostics\OperationalIntent;
use App\Diagnostics\PathReadiness;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\ConversationMessage;
use App\Models\ConversationSetting;
use App\Models\Diagnostic;
use App\Models\Profile;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;

class OperationalCapabilitiesTest extends IdentityTestCase
{
    private function agent(): Agent
    {
        $this->context()->forUser($this->operator);
        $a = new Agent;
        $a->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'name' => 'Published API fixture', 'hostname' => 'fixture', 'version' => '0.6.2', 'status' => 'active',
            'capabilities' => Protocol::CAPABILITIES, 'token_hash' => hash('sha256', random_bytes(32)), 'last_heartbeat_at' => now(),
            'metadata' => ['task_protocol' => 1, 'mtr_capability_confirmed_at' => now()->toIso8601String(), 'telemetry' => ['device_operations_frozen' => true, 'service_status' => 'active', 'settings_revision' => 1]]])->save();

        return $a;
    }

    public function test_published_advertisement_and_managed_allowlist_never_prove_effective_permission(): void
    {
        $a = $this->agent();
        $a->forceFill(['metadata' => [...$a->metadata, 'managed_settings' => ['revision' => 1, 'source_ips' => [], 'auto_public_targets' => true, 'allowed_networks' => ['8.8.8.8/32'], 'allowed_dns_names' => []],
            'observability' => ['helper' => ['healthy' => true]], 'permission_result' => ['state' => 'ALLOWED', 'destination' => '8.8.8.8', 'protocol' => 'icmp']]])->save();
        $r = app(OperationalCapabilities::class);
        $this->assertContains('mtr_path_analysis:v1', $r->snapshot($a)['capabilities']);
        $this->assertSame('UNKNOWN', $r->snapshot($a)['helper_health']);
        $this->assertSame('UNKNOWN', $r->permission($a, 'mtr_path_analysis:v1', '8.8.8.8', 'icmp')['state']);
        $this->assertSame('effective_policy_unverified', app(PathReadiness::class)->check($a, 'mtr_path_analysis:v1', '8.8.8.8', 'icmp'));
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_offline_missing_capability_and_explicit_method_keep_mtr_scope(): void
    {
        $a = $this->agent();
        $a->forceFill(['last_heartbeat_at' => now()->subMinutes(2)])->save();
        $this->assertSame('agent_unavailable', app(PathReadiness::class)->check($a, 'mtr_path_analysis:v1', '8.8.8.8', 'icmp'));
        $a->forceFill(['last_heartbeat_at' => now(), 'capabilities' => ['traceroute:v1']])->save();
        $this->assertSame('capability_unavailable', app(PathReadiness::class)->check($a, 'mtr_path_analysis:v1', '8.8.8.8', 'icmp'));
        $request = app(IntentParser::class)->parse('Quero analisar a rota para 8.8.8.8');
        $this->assertSame('ROUTE_PATH_ANALYSIS', $request['intent']);
        $this->assertSame('MTR_ICMP', app(IntentPlanning::class)->profile(OperationalIntent::Route, $a, $request));
        $this->assertSame('CAMINHO_TRACEROUTE', app(IntentPlanning::class)->profile(OperationalIntent::Route, $a, ['method' => 'traceroute']));
    }

    public function test_actual_contract_can_present_route_plan_but_never_create_work_on_confirmation(): void
    {
        $a = $this->agent();
        app(Catalog::class)->ensure();
        (new ConversationSetting)->forceFill(['tenant_id' => $this->a->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $this->admin->id])->save();
        $identity = Identity::web($this->operator);
        $engine = app(Conversations::class);
        $c = $engine->create($identity, (string) Str::uuid(), $a->id);
        $c = $engine->send($identity, $c, (string) Str::uuid(), 'Quero analisar a rota para 8.8.8.8');
        $text = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first()->body;
        $this->assertStringContainsString('MTR_ICMP', $text);
        $this->assertStringContainsString('MTR ICMP', $text);
        $this->assertStringContainsString('Autorização efetiva não comprovada', $text);
        $this->assertStringNotContainsString('HTTP', $text);
        $this->assertArrayNotHasKey('pending', $c->context_json);
        $engine->send($identity, $c, (string) Str::uuid(), 'Sim, confirmo');
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, AgentTask::count());
        $plan = app(IntentPlanning::class)->preview(Profile::where('key', 'MTR_ICMP')->sole(), $a, '8.8.8.8');
        $this->assertSame(['mtr_icmp'], array_column($plan['steps'], 'key'));
        $this->assertFalse($plan['steps'][0]['available']);
    }
}
