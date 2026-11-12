<?php

namespace Tests\Feature;

use App\Agent\Protocol;
use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Conversation\PlanningReply;
use App\Conversation\ResolvePreflight;
use App\Diagnostics\Catalog;
use App\Diagnostics\OperationalCapabilities;
use App\Diagnostics\Preflight;
use App\Enums\Role;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\ConversationMessage;
use App\Models\ConversationSetting;
use App\Models\Diagnostic;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;

class PreflightRealFlowTest extends IdentityTestCase
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

    private function waiting(): array
    {
        Queue::fake();
        [$agent] = $this->agent();
        app(Catalog::class)->ensure();
        (new ConversationSetting)->forceFill(['tenant_id' => $this->a->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $this->admin->id])->save();
        $engine = app(Engine::class);
        $identity = Identity::web($this->operator);
        $c = $engine->create($identity, (string) Str::uuid(), $agent->id);
        $c = $engine->send($identity, $c, (string) Str::uuid(), 'Quero analisar a rota para 8.8.8.8.');
        $this->assertSame('PENDING', $c->context_json['preflight_watch']['phase']);
        Queue::assertPushed(ResolvePreflight::class, fn ($job) => $job->conversationId === $c->id && $job->delay->timestamp === $c->context_json['preflight_watch']['request']['expires_at']);

        return [$agent, $c, $identity, $engine];
    }

    private function resolve($c): void
    {
        (new ResolvePreflight($this->a->id, $c->id))->handle(app(Engine::class));
        $this->context()->forUser($this->operator);
    }

    public function test_terminal_denial_is_automatic_without_new_user_turn_and_duplicate_jobs_are_idempotent(): void
    {
        [$agent, $c] = $this->waiting();
        $this->travel(18)->seconds();
        $request = app(Preflight::class)->exchange($agent, null);
        $this->travel(1)->seconds();
        app(Preflight::class)->exchange($agent, $this->decision($request, 'DENIED'));
        Queue::assertPushed(ResolvePreflight::class, 2);
        $this->resolve($c);
        $this->resolve($c);
        app(Preflight::class)->exchange($agent, $this->decision($request, 'DENIED'));
        $this->resolve($c);
        $messages = ConversationMessage::where('conversation_id', $c->id)->get();
        $this->assertSame(1, $messages->where('sender_type', 'user')->count());
        $this->assertSame(1, $messages->filter(fn ($m) => ($m->metadata['diagnostic_planning']['status'] ?? null) === 'DENIED')->count());
        $this->assertArrayNotHasKey('pending', $c->fresh()->context_json);
        $this->assertArrayNotHasKey('preflight_watch', $c->fresh()->context_json);
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_allowed_update_expires_and_old_confirmation_cannot_execute(): void
    {
        [$agent, $c, $identity, $engine] = $this->waiting();
        $request = app(Preflight::class)->exchange($agent, null);
        app(Preflight::class)->exchange($agent, $this->decision($request));
        $this->resolve($c);
        $this->assertSame('WAITING_FOR_CONFIRMATION', $c->fresh()->state);
        $count = ConversationMessage::count();
        $this->resolve($c);
        $this->assertSame($count, ConversationMessage::count());
        $this->travel(11)->seconds();
        $this->resolve($c);
        $this->assertSame('FOLLOW_UP', $c->fresh()->state);
        $this->assertArrayNotHasKey('pending', $c->fresh()->context_json);
        $engine->send($identity, $c->fresh(), (string) Str::uuid(), 'Confirmo');
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_pending_deadline_and_slow_allowed_response_never_grant_confirmation(): void
    {
        [$agent, $c] = $this->waiting();
        $this->travel(46)->seconds();
        $this->resolve($c);
        $this->assertArrayNotHasKey('preflight_watch', $c->fresh()->context_json);
        $last = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first();
        $this->assertSame('ERROR', $last->metadata['diagnostic_planning']['status']);
        $this->assertStringNotContainsString('política atual não permite', $last->body);
        $this->assertNull(app(Preflight::class)->exchange($agent, null));
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_changed_policy_cancelled_and_cross_tenant_waits_cannot_be_confirmed(): void
    {
        [$agent, $c, $identity, $engine] = $this->waiting();
        $request = app(Preflight::class)->exchange($agent, null);
        app(Preflight::class)->exchange($agent, $this->decision($request));
        $metadata = $agent->fresh()->metadata;
        $metadata['managed_settings']['revision']++;
        $agent->forceFill(['metadata' => $metadata])->save();
        $this->resolve($c);
        $this->assertArrayNotHasKey('pending', $c->fresh()->context_json);
        $this->assertSame('STALE', ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first()->metadata['diagnostic_planning']['status']);
        $c = $engine->send($identity, $c->fresh(), (string) Str::uuid(), 'Quero analisar a rota para 8.8.8.8.');
        $this->assertArrayHasKey('preflight_watch', $c->context_json);
        $this->operator->forceFill(['role' => \App\Enums\Role::Viewer])->save();
        $beforeRevoked = ConversationMessage::count();
        $this->resolve($c);
        $this->assertSame($beforeRevoked, ConversationMessage::count());
        $this->operator->forceFill(['role' => \App\Enums\Role::Operator])->save();
        $engine->send($identity, $c, (string) Str::uuid(), 'Cancela');
        $count = ConversationMessage::count();
        $this->resolve($c);
        (new ResolvePreflight($this->b->id, $c->id))->handle($engine);
        $this->context()->forUser($this->operator);
        $this->assertSame($count, ConversationMessage::count());
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_expired_allowed_callback_helper_failure_and_revoked_role_cannot_offer_confirmation(): void
    {
        [$agent, $c] = $this->waiting();
        $request = app(Preflight::class)->exchange($agent, null);
        app(Preflight::class)->exchange($agent, $this->decision($request));
        $this->travel(11)->seconds();
        $this->resolve($c);
        $this->assertNotSame('WAITING_FOR_CONFIRMATION', $c->fresh()->state);
        $this->assertSame('STALE', ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first()->metadata['diagnostic_planning']['status']);
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_helper_unavailable_callback_is_not_policy_denial(): void
    {
        [$agent, $c] = $this->waiting();
        $request = app(Preflight::class)->exchange($agent, null);
        $result = [...$this->decision($request, 'UNKNOWN'), 'helper_healthy' => false, 'reason' => 'helper_unavailable'];
        app(Preflight::class)->exchange($agent, $result);
        $this->resolve($c);
        $last = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first();
        $this->assertSame('HELPER_UNAVAILABLE', $last->metadata['diagnostic_planning']['status']);
        $this->assertSame('UNKNOWN', $last->metadata['diagnostic_planning']['authorization']);
        $this->assertArrayNotHasKey('pending', $c->fresh()->context_json);
    }

    public function test_revoked_role_and_changed_target_suppress_old_callback(): void
    {
        [$agent, $c, $identity, $engine] = $this->waiting();
        $request = app(Preflight::class)->exchange($agent, null);
        $engine->send($identity, $c, (string) Str::uuid(), 'Quero analisar a rota para 1.1.1.1.');
        app(Preflight::class)->exchange($agent, $this->decision($request));
        $count = ConversationMessage::count();
        $this->resolve($c);
        $this->assertSame($count, ConversationMessage::count());
        $this->assertArrayNotHasKey('pending', $c->fresh()->context_json);
        $this->operator->forceFill(['role' => Role::Viewer])->save();
        $this->resolve($c);
        $this->assertSame($count, ConversationMessage::count());
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_real_timing_pending_then_denied_then_stale_never_grants_execution(): void
    {
        [$agent] = $this->agent();
        $p = app(Preflight::class);
        $first = $p->permission($agent, 'mtr_path_analysis:v1', '8.8.8.8', 'icmp');
        $this->assertSame('UNKNOWN', $first['state']);
        $this->assertSame('preflight_pending', $first['reason']);
        $request = $agent->fresh()->metadata['diagnostic_preflight_v1']['request'];
        $this->travel(18)->seconds();
        $this->assertSame($request, $p->exchange($agent, null));
        $this->travel(1)->seconds();
        $p->exchange($agent, $this->decision($request, 'DENIED'));
        $this->assertSame('DENIED', $p->permission($agent, 'mtr_path_analysis:v1', '8.8.8.8', 'icmp')['state']);
        $this->travel(11)->seconds();
        $expired = $p->permission($agent, 'mtr_path_analysis:v1', '8.8.8.8', 'icmp');
        $this->assertSame('UNKNOWN', $expired['state']);
        $this->assertSame('preflight_stale', $expired['reason']);
        $this->assertNotSame($request['id'], $agent->fresh()->metadata['diagnostic_preflight_v1']['request']['id']);
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_no_response_within_window_is_timeout_not_policy_denial(): void
    {
        [$agent] = $this->agent();
        $p = app(Preflight::class);
        $p->permission($agent, 'mtr_path_analysis:v1', '8.8.8.8', 'icmp');
        $this->travel(46)->seconds();
        $decision = $p->permission($agent, 'mtr_path_analysis:v1', '8.8.8.8', 'icmp');
        $this->assertSame('UNKNOWN', $decision['state']);
        $this->assertSame('preflight_timeout', $decision['reason']);
        $this->assertStringNotContainsString('negada', app(PlanningReply::class)->blocked('8.8.8.8', $decision['reason']));
    }

    public function test_single_slot_busy_and_incompatible_are_not_denials(): void
    {
        [$agent] = $this->agent();
        $p = app(Preflight::class);
        $p->permission($agent, 'mtr_path_analysis:v1', '8.8.8.8', 'icmp');
        $before = $agent->fresh()->metadata['diagnostic_preflight_v1'];
        $other = $p->permission($agent, 'mtr_path_analysis:v1', '1.1.1.1', 'tcp');
        $this->assertSame('UNKNOWN', $other['state']);
        $this->assertSame('preflight_busy', $other['reason']);
        $this->assertSame($before, $agent->fresh()->metadata['diagnostic_preflight_v1']);
        $agent->forceFill(['version' => '0.6.4'])->save();
        $this->assertSame('preflight_incompatible', $p->permission($agent, 'mtr_path_analysis:v1', '8.8.8.8', 'icmp')['reason']);
    }

    public function test_helper_failure_and_inconclusive_reply_do_not_become_denial_or_allowance(): void
    {
        [$agent] = $this->agent();
        $request = $this->request($agent);
        $d = $this->decision($request, 'UNKNOWN');
        $d['helper_healthy'] = false;
        $d['reason'] = 'helper_unavailable';
        app(Preflight::class)->exchange($agent, $d);
        $this->assertSame('helper_unavailable', app(OperationalCapabilities::class)->path($agent, 'mtr_path_analysis:v1', '8.8.8.8', 'icmp'));
        $this->assertSame('UNKNOWN', app(Preflight::class)->permission($agent, 'mtr_path_analysis:v1', '8.8.8.8', 'icmp')['state']);
        $this->assertSame(0, AgentTask::count());
    }

    public function test_short_telegram_presentations_never_expose_technical_profile_names(): void
    {
        foreach (['preflight_pending', 'preflight_stale', 'preflight_timeout', 'preflight_busy', 'preflight_incompatible', 'policy_denied', 'helper_unavailable', 'agent_unavailable', 'effective_policy_unverified'] as $reason) {
            $text = app(PlanningReply::class)->blocked('8.8.8.8', $reason);
            $this->assertLessThan(350, mb_strlen($text));
            $this->assertStringContainsString('Nenhum teste foi executado', $text);
            foreach (['MTR_', 'Profile', 'capability', 'PathReadiness', 'HTTPS'] as $internal) {
                $this->assertStringNotContainsString($internal, $text);
            }
        }
    }

    public function test_web_preserves_technical_snapshot_and_denies_cross_tenant_access(): void
    {
        [$agent] = $this->agent();
        app(Catalog::class)->ensure();
        (new ConversationSetting)->forceFill(['tenant_id' => $this->a->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $this->admin->id])->save();
        $identity = Identity::web($this->operator);
        $engine = app(Engine::class);
        $c = $engine->create($identity, (string) Str::uuid(), $agent->id);
        $key = (string) Str::uuid();
        $body = 'Meu rei, quero analisar a rota para 8.8.8.8.';
        $c = $engine->send($identity, $c, $key, $body);
        $message = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first();
        $meta = $message->metadata['diagnostic_planning'];
        $this->assertSame('UNKNOWN', $meta['authorization']);
        $this->assertSame('preflight_pending', $meta['reason']);
        $this->assertSame('MTR_ICMP', $meta['profile']);
        $this->assertSame(['mtr_icmp'], $meta['steps']);
        $this->assertSame($agent->fresh()->metadata['diagnostic_preflight_v1']['request']['id'], $meta['request_id']);
        $count = ConversationMessage::where('conversation_id', $c->id)->count();
        $engine->send($identity, $c, $key, $body);
        $this->assertSame($count, ConversationMessage::where('conversation_id', $c->id)->count());
        $response = $this->actingAs($this->operator)->get('/conversations/'.$c->id)->assertOk()->assertSee('Detalhes técnicos deste planejamento')->assertSee($meta['request_id']);
        if ($directory = getenv('PREFLIGHT_FLOW_PROOF_DIR')) {
            file_put_contents($directory.'/web.html', $response->getContent());
        }
        $this->flushSession();
        $this->actingAs($this->other)->get('/conversations/'.$c->id)->assertNotFound();
        $this->context()->apply();
        $this->assertSame(0, Agent::count());
    }
}
