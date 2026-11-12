<?php

namespace Tests\Feature;

use App\Agent\Protocol;
use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Conversation\ResolvePreflight;
use App\Diagnostics\Catalog;
use App\Diagnostics\Classifier;
use App\Diagnostics\Preflight;
use App\Knowledge\Versioning;
use App\Llm\Configuration;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationSetting;
use App\Models\Diagnostic;
use App\Models\DiagnosticConclusion;
use App\Noc\Planning;
use App\Rag\Index;
use App\Telegram\Access;
use App\Telegram\Accounts;
use App\Telegram\Bot;
use App\Telegram\Client;
use App\Telegram\Deliver;
use App\Telegram\Group;
use App\Telegram\GroupContext;
use App\Telegram\Groups;
use App\Telegram\Ingress;
use App\Telegram\Link;
use App\Telegram\Member;
use App\Telegram\Outbound;
use App\Telegram\ProcessUpdate;
use App\Telegram\Runner;
use App\Telegram\SetupProgress;
use App\Telegram\Sweep;
use App\Telegram\Update;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\IntelligenceTestCase;
use Tests\Support\MtrFixture;
use Tests\Support\PathReadinessFixture;

class TelegramTest extends IntelligenceTestCase
{
    use PathReadinessFixture;

    public function test_noc_device_queries_are_understood_without_profiles_preflight_or_tasks(): void
    {
        $link = $this->link();
        foreach (['Verifique o BGP do NE8000', 'Confira a rota até 8.8.8.8 no roteador', 'Verifique a potência das ONUs'] as $text) {
            $this->say($text);
            $c = $this->conversation($link);
            $m = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first();
            $this->assertStringContainsString(Planning::UNAVAILABLE, $m->body);
            $this->assertFalse($m->metadata['noc_planning']['operational_enabled']);
            $this->assertArrayNotHasKey('pending', $c->context_json);
            $this->assertArrayNotHasKey('preflight_watch', $c->context_json);
        }
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(0, Diagnostic::count());
        $this->deliverAll();
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'consulta ao equipamento ainda não está habilitada'));
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', 'Confirma a execução'));
    }

    public function test_real_preflight_pending_then_late_denial_is_short_and_idempotent(): void
    {
        $agent = $this->agent();
        $agent->forceFill(['version' => '0.6.5', 'last_heartbeat_at' => now(), 'metadata' => [...$agent->metadata, 'mtr_capability_confirmed_at' => now()->toIso8601String()]])->save();
        $link = $this->link();
        $serial = ++$this->serial;
        $text = 'Meu rei, quero analisar a rota para 8.8.8.8.';
        $this->receive($text, update: $serial);
        $this->process();
        $c = $this->conversation($link);
        $first = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first();
        $this->assertStringContainsString('pendente', $first->body);
        $this->assertStringNotContainsString('MTR_ICMP', $first->body);
        $this->assertStringNotContainsString('Profile', $first->body);
        $this->assertSame('UNKNOWN', $first->metadata['diagnostic_planning']['authorization']);
        $this->assertSame('MTR_ICMP', $first->metadata['diagnostic_planning']['profile']);
        $request = $agent->fresh()->metadata['diagnostic_preflight_v1']['request'];
        $count = ConversationMessage::where('conversation_id', $c->id)->count();
        $this->receive($text, update: $serial);
        $this->process();
        $this->assertSame($count, ConversationMessage::where('conversation_id', $c->id)->count());
        $this->deliverAll();
        $this->travel(18)->seconds();
        app(Preflight::class)->exchange($agent, null);
        $this->travel(1)->seconds();
        app(Preflight::class)->exchange($agent, ['schema_version' => 1, 'id' => $request['id'], 'state' => 'DENIED', 'reason' => 'policy_denied', 'helper_healthy' => null, 'agent_version' => '0.6.5', 'policy_digest' => $request['policy_digest']]);
        // Execute the queued callback, without another inbound Telegram message.
        (new ResolvePreflight($this->a->id, $c->id))->handle(app(Engine::class));
        $this->scope();
        (new ResolvePreflight($this->a->id, $c->id))->handle(app(Engine::class));
        $this->scope();
        $this->assertSame(1, ConversationMessage::where('conversation_id', $c->id)->where('sender_type', 'user')->count());
        $last = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first();
        $this->assertStringContainsString('política atual não permite', $last->body);
        $this->assertStringContainsString('Nenhum teste foi executado', $last->body);
        $this->assertStringNotContainsString('MTR_ICMP', $last->body);
        $this->assertStringNotContainsString('HTTPS', $last->body);
        $this->assertSame('DENIED', $last->metadata['diagnostic_planning']['authorization']);
        $this->assertArrayNotHasKey('pending', $this->conversation($link)->context_json);
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(0, Diagnostic::count());
        $this->deliverAll();
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'consulta de autorização') && ! str_contains($r['text'] ?? '', 'Profile'));
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'política atual não permite') && ! str_contains($r['text'] ?? '', 'MTR_ICMP'));
        if ($directory = getenv('PREFLIGHT_FLOW_PROOF_DIR')) {
            file_put_contents($directory.'/telegram.json', json_encode(['pending' => $first->body, 'denied' => $last->body, 'metadata' => $last->metadata], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_async_allowed_confirmation_is_suppressed_if_delivery_is_delayed_past_validity(): void
    {
        $agent = $this->agent();
        $agent->forceFill(['version' => '0.6.5', 'last_heartbeat_at' => now(), 'metadata' => [...$agent->metadata, 'mtr_capability_confirmed_at' => now()->toIso8601String()]])->save();
        $link = $this->link();
        $this->say('Quero analisar a rota para 8.8.8.8.');
        $c = $this->conversation($link);
        $request = app(Preflight::class)->exchange($agent, null);
        app(Preflight::class)->exchange($agent, ['schema_version' => 1, 'id' => $request['id'], 'state' => 'ALLOWED', 'reason' => 'authorized', 'helper_healthy' => true, 'helper_version' => '0.6.5', 'effective_revision' => str_repeat('a', 64), 'agent_version' => '0.6.5', 'policy_digest' => $request['policy_digest']]);
        (new ResolvePreflight($this->a->id, $c->id))->handle(app(Engine::class));
        $this->scope();
        $this->assertSame('WAITING_FOR_CONFIRMATION', $c->fresh()->state);
        $confirmation = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first();
        $this->travel(11)->seconds();
        $this->deliverAll();
        $this->assertSame('SUPPRESSED', Outbound::where('message_id', $confirmation->id)->sole()->status);
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', 'Confirma a execução'));
        $this->assertSame(1, Outbound::where('conversation_id', $c->id)->where('last_error', 'SUPERSEDED_PREFLIGHT_PENDING')->count());
        (new ResolvePreflight($this->a->id, $c->id))->handle(app(Engine::class));
        $this->scope();
        $this->assertArrayNotHasKey('pending', $c->fresh()->context_json);
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_exact_ip_route_request_uses_existing_mtr_profile_after_explicit_confirmation(): void
    {
        $agent = $this->agent();
        $agent->forceFill(['metadata' => [...$agent->metadata, 'mtr_capability_confirmed_at' => now()->toIso8601String()]])->save();
        $this->pathReady($agent);
        $link = $this->link();
        $this->say('Queria avaliar essa rota aqui 8.8.8.8, ver se está boa.');
        $c = $this->conversation($link);
        $this->assertSame('WAITING_FOR_CONFIRMATION', $c->state);
        $this->assertSame('MTR_ICMP', $c->context_json['pending']['profile']);
        $this->assertSame(0, Diagnostic::count());
        $this->deliverAll();
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'caminho de rede até 8.8.8.8'));
        $this->say('Sim, confirmo');
        $this->assertSame(1, Diagnostic::count());
        app(\App\Diagnostics\Engine::class)->tick();
        $this->assertSame(['mtr_path_analysis:v1'], AgentTask::pluck('capability')->all());
        $this->assertSame('ROUTE_PATH_ANALYSIS', Diagnostic::sole()->context['operational_intent']);
        $engine = app(\App\Diagnostics\Engine::class);
        $task = $engine->claim($agent);
        $envelope = array_intersect_key($task, array_flip(['task_id', 'execution_id', 'generation', 'lease_token']));
        $engine->operation($agent, 'start', $envelope);
        $engine->operation($agent, 'result', $envelope + ['result' => ['ok' => true, 'data' => MtrFixture::data('isolated-loss')]]);
        $this->experienceSweep();
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'Análise de rota: EXECUTADA'));
        $this->assertSame('NORMAL', DiagnosticConclusion::sole()->classification);
        if ($directory = getenv('INTENT_PROOF_DIR')) {
            file_put_contents($directory.'/telegram-proof.json', json_encode(['status' => 'PASS', 'transport' => 'HTTP fake; no Telegram API calls',
                'messages' => ConversationMessage::where('conversation_id', $c->id)->orderBy('sequence')->get()->map(fn ($message) => ['sender' => $message->sender_type, 'body' => $message->body])->all()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    public function test_bare_ip_in_telegram_asks_objective_and_does_not_run_https(): void
    {
        $this->agent();
        $link = $this->link();
        $this->say('8.8.8.8');
        $c = $this->conversation($link);
        $this->assertArrayNotHasKey('pending', $c->context_json);
        $this->assertStringContainsString('Um IP sozinho não define um site HTTPS', ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first()->body);
        $this->say('Sim, confirmo');
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, AgentTask::count());
    }

    private function acceptedExperience(): array
    {
        $agent = $this->agent();
        $link = $this->link();
        $this->say('meu rei, teste youtube.com');
        $this->say('Sim, confirmo');
        $c = $this->conversation($link);
        $this->assertNotNull($c->current_diagnostic_id);
        $this->deliverAll();
        $this->api();

        return [$agent, $link, $c];
    }

    private function experienceSweep(): void
    {
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        $this->deliverAll();
    }

    private function experienceResult(array $task): array
    {
        return match ($task['capability']) {
            'dns_lookup:v1' => ['ok' => true, 'data' => ['answers' => $task['input']['record_type'] === 'A' ? ['192.0.2.20'] : [], 'record_type' => $task['input']['record_type'], 'duration_ms' => 3]],
            'tcp_connect:v1' => ['ok' => true, 'data' => ['connected' => true, 'duration_ms' => 7]],
            'http_probe:v1' => ['ok' => true, 'data' => ['status' => 200, 'tls' => true, 'duration_ms' => 42, 'resolved_ip' => $task['input']['target']]],
            default => ['ok' => false, 'error' => 'timeout'],
        };
    }

    private function completeExperience(Agent $agent, ?array $started = null): void
    {
        $tasks = app(\App\Diagnostics\Engine::class);
        for ($i = 0; $i < 16; $i++) {
            $tasks->tick();
            $task = $started ?? $tasks->claim($agent);
            if (! $task) {
                break;
            }
            if (! $started) {
                $tasks->operation($agent, 'start', $task);
            }
            $tasks->operation($agent, 'result', [...$task, 'result' => $this->experienceResult($task)]);
            $started = null;
        }
    }

    public function test_v2_native_task_states_edit_one_tracking_message_and_finish_with_authenticated_details(): void
    {
        [$agent, $link, $c] = $this->acceptedExperience();
        $tasks = app(\App\Diagnostics\Engine::class);
        $tasks->tick();
        $this->experienceSweep();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/editMessageText') && $r['message_id'] === 77 && str_contains($r['text'], 'na fila'));
        $this->api();
        $task = $tasks->claim($agent);
        $this->assertNotNull($task);
        $this->experienceSweep();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/editMessageText') && str_contains($r['text'], 'recebeu a solicitação'));
        $this->api();
        $tasks->operation($agent, 'start', $task);
        $this->experienceSweep();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/editMessageText') && str_contains($r['text'], 'em execução'));
        $this->api();
        $this->completeExperience($agent, $task);
        $d = Diagnostic::findOrFail($c->current_diagnostic_id);
        $this->assertSame('COMPLETED', $d->status);
        $this->assertSame('NORMAL', DiagnosticConclusion::where('diagnostic_id', $d->id)->sole()->classification);
        $this->experienceSweep();
        $result = ConversationMessage::where('conversation_id', $c->id)->where('message_type', 'result')->sole();
        $this->assertSame('RELAXED', $result->metadata['tone']);
        $this->assertStringContainsString('Prontinho, meu rei!', $result->body);
        Http::assertSent(function ($r) use ($d, $result) {
            $button = $r['reply_markup']['inline_keyboard'][0][0] ?? [];

            return str_ends_with($r->url(), '/editMessageText') && str_contains($r['text'], Classifier::NORMAL)
                && str_contains($r['text'], '42 ms') && $r['text'] === $result->body
                && $button['text'] === 'Ver diagnóstico completo' && $button['url'] === 'https://central.example.test'.route('diagnostics.show', $d->id, false)
                && ! parse_url($button['url'], PHP_URL_QUERY);
        });
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') || str_contains($r['text'] ?? '', 'Histórico:'));
        $this->api();
        $this->experienceSweep();
        Http::assertNothingSent();
        $count = Diagnostic::count();
        $this->say('Mas continua lento para os clientes.');
        $this->assertSame($count, Diagnostic::count());
        $this->assertArrayNotHasKey('pending', $this->conversation($link)->context_json);
        $this->assertSame(1, ConversationMessage::where('event_key', 'result:'.$d->id)->count());
    }

    public function test_v2_delayed_progress_is_coalesced_and_cannot_overwrite_a_newer_result(): void
    {
        [$agent, $link, $c] = $this->acceptedExperience();
        app(\App\Diagnostics\Engine::class)->tick();
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        $this->completeExperience($agent);
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        $this->deliverAll();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', Classifier::NORMAL));
        $this->assertSame(1, Outbound::where('status', 'SUPPRESSED')->where('last_error', 'SUPERSEDED_PROGRESS')->count());
    }

    public function test_v2_definite_edit_unavailable_falls_back_once_to_a_protected_new_message(): void
    {
        [$agent, , $c] = $this->acceptedExperience();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn ($r) => str_ends_with($r->url(), '/editMessageText')
            ? Http::response(['ok' => false, 'description' => 'Bad Request: message to edit not found'], 400)
            : Http::response(['ok' => true, 'result' => ['message_id' => 88]]));
        app(\App\Diagnostics\Engine::class)->tick();
        $this->experienceSweep();
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && $r['protect_content'] === true);
        $this->completeExperience($agent);
        $this->api();
        $this->experienceSweep();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/editMessageText') && $r['message_id'] === 88);
    }

    public function test_v2_known_target_edit_timeout_is_retryable_without_resending_initial_message(): void
    {
        [$agent, , $c] = $this->acceptedExperience();
        $client = new class extends Client
        {
            public array $methods = [];

            public function call(Bot $bot, string $method, array $data = []): array
            {
                $this->methods[] = $method;

                return ['status' => count($this->methods) === 1 ? 'DELIVERY_UNKNOWN' : 'OK', 'result' => ['message_id' => 77]];
            }
        };
        app(\App\Diagnostics\Engine::class)->tick();
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        $out = Outbound::where('conversation_id', $c->id)->where('status', 'PENDING')->sole();
        (new Deliver($this->a->id, $out->id))->handle($client);
        $this->scope();
        $this->assertSame('PENDING', $out->fresh()->status);
        $out->fresh()->forceFill(['next_attempt_at' => now()])->save();
        (new Deliver($this->a->id, $out->id))->handle($client);
        $this->scope();
        $this->assertSame('SENT', $out->fresh()->status);
        $this->assertSame(['editMessageText', 'editMessageText'], $client->methods);
    }

    public function test_v2_agent_without_recent_signal_is_reported_as_waiting_not_as_a_network_failure(): void
    {
        $agent = $this->agent();
        $agent->forceFill(['last_heartbeat_at' => now()->subMinutes(5), 'last_poll_at' => now()->subMinutes(5)])->save();
        $link = $this->link();
        $this->say('Teste youtube.com');
        $this->say('Sim');
        $c = $this->conversation($link);
        $m = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->firstOrFail();
        $this->assertStringContainsString('sem sinal recente', $m->body);
        $this->assertStringContainsString('não indica falha na rede', $m->body);
        $this->assertSame('PENDING', Diagnostic::findOrFail($c->current_diagnostic_id)->status);
        $this->assertSame(0, AgentTask::count());
    }

    public function test_v2_interrupted_known_edit_recovers_and_not_modified_is_a_success(): void
    {
        [$agent, , $c] = $this->acceptedExperience();
        app(\App\Diagnostics\Engine::class)->tick();
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        $out = Outbound::where('conversation_id', $c->id)->where('status', 'PENDING')->sole();
        $out->forceFill(['status' => 'SENDING', 'telegram_message_id' => 77, 'attempts' => 1, 'updated_at' => now()->subMinutes(3)])->save();
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        $this->assertSame('PENDING', $out->fresh()->status);
        $this->api(['ok' => false, 'description' => 'Bad Request: message is not modified'], 400);
        (new Deliver($this->a->id, $out->id))->handle(app(Client::class));
        $this->scope();
        $this->assertSame('SENT', $out->fresh()->status);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/editMessageText'));
    }

    public function test_v2_revocation_before_edit_suppresses_delivery_and_cross_tenant_cannot_claim_it(): void
    {
        [$agent, $link, $c] = $this->acceptedExperience();
        app(\App\Diagnostics\Engine::class)->tick();
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        $out = Outbound::where('conversation_id', $c->id)->where('status', 'PENDING')->sole();
        (new Deliver($this->b->id, $out->id))->handle(app(Client::class));
        $this->scope();
        $this->assertSame('PENDING', $out->fresh()->status);
        app(Accounts::class)->revoke($link->fresh(), $this->admin);
        (new Deliver($this->a->id, $out->id))->handle(app(Client::class));
        $this->scope();
        $this->assertSame('SUPPRESSED', $out->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_v2_timeout_is_inconclusive_not_a_network_failure_and_is_delivered_once(): void
    {
        [$agent, , $c] = $this->acceptedExperience();
        Diagnostic::findOrFail($c->current_diagnostic_id)->forceFill(['created_at' => now()->subMinutes(6)])->save();
        app(\App\Diagnostics\Engine::class)->tick();
        $this->experienceSweep();
        $d = Diagnostic::findOrFail($c->current_diagnostic_id);
        $this->assertSame('FAILED', $d->status);
        $this->assertSame('diagnostic_timeout', $d->error_code);
        $result = ConversationMessage::where('conversation_id', $c->id)->where('message_type', 'result')->sole();
        $this->assertSame('INCONCLUSIVO', $result->metadata['classification']);
        $this->assertStringContainsString('prazo total do diagnóstico foi excedido', $result->body);
        $this->assertStringNotContainsString('INDICIO_DE_FALHA', $result->body);
        Http::assertSentCount(1);
    }

    public function test_v2_cancelled_diagnostic_keeps_evidence_and_updates_its_tracking_message(): void
    {
        [, $link, $c] = $this->acceptedExperience();
        $this->say('Cancela');
        $this->experienceSweep();
        $this->assertSame('CANCELLED', $this->conversation($link)->state);
        $result = ConversationMessage::where('conversation_id', $c->id)->where('message_type', 'result')->sole();
        $this->assertSame('INCONCLUSIVO', $result->metadata['classification']);
        $this->assertStringContainsString('interrompido', $result->body);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/editMessageText') && str_contains($r['text'], 'encerrado'));
        $count = Diagnostic::count();
        $this->say('Sim');
        $this->assertSame($count, Diagnostic::count());
    }

    public function test_telegram_return_has_one_adaptive_reply_and_replayed_update_cannot_duplicate_it(): void
    {
        config(['conversation.idle_enabled' => true, 'conversation.idle_tenants' => [$this->a->id]]);
        $link = $this->link();
        $this->say('boa noite ce ta bao?');
        $first = $this->conversation($link);
        $this->assertSame(1, ConversationMessage::where('conversation_id', $first->id)->where('sender_type', 'system')->count());
        $this->travel(15)->minutes();
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        $this->deliverAll();
        $this->assertSame('CLOSED', $first->fresh()->state);
        $this->api();
        $this->receive('boa noite meu chapa', update: 900001);
        $this->process();
        $new = $this->conversation($link);
        $this->assertNotSame($first->id, $new->id);
        $reply = ConversationMessage::where('conversation_id', $new->id)->where('sender_type', 'system')->sole();
        $this->assertSame('RELAXED', $reply->metadata['tone']);
        $this->assertStringNotContainsString('assistente virtual', $reply->body);
        $this->assertSame(1, Outbound::where('conversation_id', $new->id)->count());
        $this->assertTrue($this->receive('boa noite meu chapa', update: 900001)['duplicate']);
        $this->process();
        $this->assertSame(1, Outbound::where('conversation_id', $new->id)->count());
        $this->deliverAll();
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => ($r['text'] ?? '') === $reply->body);
        $this->assertSame('CLOSED', $first->fresh()->state);
        $this->assertSame('ACTIVE', $link->fresh()->status);
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_manual_new_telegram_chat_also_has_one_reply_while_web_keeps_its_initial_welcome(): void
    {
        $link = $this->link();
        $this->say('/nova');
        $c = $this->conversation($link);
        $reply = ConversationMessage::where('conversation_id', $c->id)->where('sender_type', 'system')->sole();
        $this->assertStringNotContainsString('assistente virtual', $reply->body);
        $this->assertSame(1, Outbound::where('conversation_id', $c->id)->count());
        $web = app(Engine::class)->create(Identity::web($this->admin), (string) Str::uuid());
        $this->assertStringContainsString('Sou o assistente virtual', ConversationMessage::where('conversation_id', $web->id)->sole()->body);
    }

    public function test_group_return_has_one_reply_in_the_same_authorized_topic(): void
    {
        config(['conversation.idle_enabled' => true, 'conversation.idle_tenants' => [$this->a->id]]);
        [$group, $link] = $this->companyGroup('group');
        $this->groupMessage('/perguntar@lab_fixture_bot boa noite meu chapa', thread: 7);
        $context = GroupContext::where('group_id', $group->id)->where('link_id', $link->id)->where('thread_id', 7)->sole();
        $old = $context->conversation_id;
        $this->travel(15)->minutes();
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        $this->groupMessage('/perguntar@lab_fixture_bot boa noite meu chapa', thread: 7);
        $this->assertNotSame($old, $context->fresh()->conversation_id);
        $c = Conversation::findOrFail($context->fresh()->conversation_id);
        $reply = ConversationMessage::where('conversation_id', $c->id)->where('sender_type', 'system')->sole();
        $this->assertSame('RELAXED', $reply->metadata['tone']);
        $this->assertStringNotContainsString('assistente virtual', $reply->body);
        $out = Outbound::where('conversation_id', $c->id)->sole();
        $this->assertSame($context->id, $out->context_id);
        $this->assertSame(7, $out->thread_id);
        $this->assertSame($group->chat_id, $out->chat_id);
        $this->assertSame('CLOSED', Conversation::findOrFail($old)->state);
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_first_sweep_after_late_feature_activation_closes_old_chat_immediately(): void
    {
        config(['conversation.idle_enabled' => false, 'conversation.idle_tenants' => [$this->a->id]]);
        $this->travelTo(Carbon::parse('2026-11-11T23:59:14Z'));
        $link = $this->link();
        $this->say('Boa noite!');
        $c = $this->conversation($link);
        $this->travelTo(Carbon::parse('2026-11-12T00:14:14Z'));
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        $this->assertSame('OPEN', $c->fresh()->status);
        $this->travelTo(Carbon::parse('2026-11-12T00:55:37Z'));
        config(['conversation.idle_enabled' => true]);
        $this->travelTo(Carbon::parse('2026-11-12T00:56:00Z'));
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        $this->assertSame('CLOSED', $c->fresh()->state);
        $this->assertSame(3406, (int) $c->last_activity_at->diffInSeconds($c->fresh()->closed_at));
        $this->assertSame(1, ConversationMessage::where('event_key', 'idle_closed')->count());
    }

    public function test_periodic_sweeps_close_within_one_minute_of_deadline_in_all_display_timezones(): void
    {
        $original = date_default_timezone_get();
        $databaseTimezone = DB::selectOne("SELECT current_setting('TimeZone') AS timezone")->timezone;
        try {
            foreach (['Europe/Berlin', 'UTC', 'America/Sao_Paulo'] as $timezone) {
                date_default_timezone_set($timezone);
                DB::select("SELECT set_config('TimeZone', ?, true)", [$timezone]);
                config(['app.timezone' => $timezone, 'conversation.idle_enabled' => true, 'conversation.idle_tenants' => [$this->a->id]]);
                $this->travelTo(Carbon::parse('2026-11-11T23:59:14Z'));
                // Reuse the verified account, but create a new isolated native conversation.
                $link = isset($link) ? $link->fresh() : $this->link();
                $this->say('/nova');
                $c = $this->conversation($link);
                foreach (range(0, 14) as $minute) {
                    $this->travelTo(Carbon::parse('2026-11-12T00:00:00Z')->addMinutes($minute));
                    app(Sweep::class)->run($this->a->id);
                    $this->scope();
                    $this->assertSame('OPEN', $c->fresh()->status, $timezone.' minute '.$minute);
                }
                $this->travelTo(Carbon::parse('2026-11-12T00:15:00Z'));
                app(Sweep::class)->run($this->a->id);
                $this->scope();
                $this->assertSame('CLOSED', $c->fresh()->state);
                $this->assertSame(946, (int) $c->last_activity_at->diffInSeconds($c->fresh()->closed_at));
                $this->assertSame('21:15:00', $c->fresh()->closed_at->setTimezone('America/Sao_Paulo')->format('H:i:s'));
                $notice = ConversationMessage::where('conversation_id', $c->id)->where('event_key', 'idle_closed')->sole();
                $this->assertSame(1, Outbound::where('message_id', $notice->id)->count());
            }
        } finally {
            date_default_timezone_set($original);
            DB::select("SELECT set_config('TimeZone', ?, true)", [$databaseTimezone]);
        }
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_periodic_sweep_cannot_close_or_cancel_active_diagnostic(): void
    {
        config(['conversation.idle_enabled' => true, 'conversation.idle_tenants' => [$this->a->id]]);
        $link = $this->link();
        $this->agent();
        $this->say('Teste google.com');
        $this->say('Sim, confirmo');
        $c = $this->conversation($link);
        $this->assertSame('DIAGNOSTIC_RUNNING', $c->state);
        $this->travel(57)->minutes();
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        $this->assertSame('DIAGNOSTIC_RUNNING', $c->fresh()->state);
        $this->assertSame('PENDING', Diagnostic::findOrFail($c->current_diagnostic_id)->status);
        $this->assertSame(0, ConversationMessage::where('event_key', 'idle_closed')->count());
    }

    public function test_idle_timer_does_not_block_help_when_conversations_are_disabled(): void
    {
        $this->link();
        $this->say('Olá');
        ConversationSetting::query()->update(['enabled' => false]);
        foreach ([false, true] as $enabled) {
            config(['conversation.idle_enabled' => $enabled, 'conversation.idle_tenants' => [$this->a->id]]);
            $this->say('/help');
            $this->assertSame('PROCESSED', Update::latest('update_id')->first()->status);
        }
        $this->assertSame(0, ConversationMessage::where('event_key', 'idle_closed')->count());
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_idle_private_sweep_enqueues_one_thank_you_and_next_greeting_starts_new_chat(): void
    {
        config(['conversation.idle_enabled' => true, 'conversation.idle_tenants' => [$this->a->id]]);
        $link = $this->link();
        $this->say('boa noite meu chapa');
        $c = $this->conversation($link);
        $this->deliverAll();
        $this->travel(15)->minutes();
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        $this->assertSame('CLOSED', $c->fresh()->state);
        $notice = ConversationMessage::where('event_key', 'idle_closed')->sole();
        $this->assertSame(1, Outbound::where('message_id', $notice->id)->count());
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        $this->assertSame(1, Outbound::where('message_id', $notice->id)->count());
        $this->deliverAll();
        Http::assertSent(fn ($r) => ($r['text'] ?? '') === $notice->body);
        $this->say('Boa noite!');
        $new = $this->conversation($link);
        $this->assertNotSame($c->id, $new->id);
        $this->assertSame('ACTIVE', $new->state);
        $this->assertSame('ACTIVE', $link->fresh()->status);
        $this->say('/historico');
        $this->deliverAll();
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', route('conversations.show', $new->id)));
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, AgentTask::count());
    }

    public function test_social_greeting_after_explicit_close_replaces_the_linked_conversation(): void
    {
        $link = $this->link();
        $this->say('Boa noite!');
        $this->say('Encerrar');
        $closed = $this->conversation($link);
        $this->assertSame('CLOSED', $closed->state);
        $history = ConversationMessage::where('conversation_id', $closed->id)->count();

        $this->say('bom dia meu consagrado bao de mais ?');
        $new = $this->conversation($link);
        $this->assertNotSame($closed->id, $new->id);
        $this->assertSame('ACTIVE', $new->state);
        $this->assertSame('CLOSED', $closed->fresh()->state);
        $this->assertSame($history, ConversationMessage::where('conversation_id', $closed->id)->count());
        $this->assertFalse(ConversationMessage::where('conversation_id', $new->id)->where('body', 'like', 'Conversa encerrada.%')->exists());
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, AgentTask::count());
        $this->deliverAll();
        Http::assertNotSent(fn ($r) => str_starts_with($r['text'] ?? '', 'Conversa encerrada. Uma nova'));
    }

    public function test_idle_respects_fresh_inbox_and_real_history_command_but_not_background_sweep(): void
    {
        config(['conversation.idle_enabled' => true, 'conversation.idle_tenants' => [$this->a->id]]);
        $link = $this->link();
        $this->say('Olá');
        $c = $this->conversation($link);
        $this->travel(15)->minutes();
        $this->receive('/historico');
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        $this->assertSame('OPEN', $c->fresh()->status);
        $this->process();
        $this->travel(14)->minutes();
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        $this->assertSame('OPEN', $c->fresh()->status);
        $this->travel(1)->minutes();
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        $this->assertSame('CLOSED', $c->fresh()->status);
    }

    public function test_idle_group_topics_are_closed_and_delivered_with_original_scope(): void
    {
        config(['conversation.idle_enabled' => true, 'conversation.idle_tenants' => [$this->a->id]]);
        [$group, $link] = $this->companyGroup('group');
        $this->groupMessage('/nova@lab_fixture_bot', thread: 11);
        $this->groupMessage('/nova@lab_fixture_bot', thread: 22);
        $contexts = GroupContext::where('link_id', $link->id)->orderBy('thread_id')->get();
        $this->assertCount(2, $contexts);
        $this->travel(15)->minutes();
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        foreach ($contexts as $context) {
            $c = Conversation::findOrFail($context->conversation_id);
            $this->assertSame('CLOSED', $c->state);
            $notice = ConversationMessage::where('conversation_id', $c->id)->where('event_key', 'idle_closed')->sole();
            $out = Outbound::where('message_id', $notice->id)->sole();
            $this->assertSame($group->id, $out->group_id);
            $this->assertSame($context->id, $out->context_id);
            $this->assertSame($context->thread_id, $out->thread_id);
            $this->assertSame($group->chat_id, $out->chat_id);
        }
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, AgentTask::count());
    }

    public function test_social_delivery_is_identical_to_web_history_without_automatic_history_link(): void
    {
        $link = $this->link();
        $this->say('ce ta bao ?');
        $c = $this->conversation($link);
        $m = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->firstOrFail();
        $this->assertSame('SOCIAL', ConversationMessage::where('conversation_id', $c->id)->where('sender_type', 'user')->latest('sequence')->value('detected_intent'));
        $this->actingAs($this->admin)->get('/conversations/'.$c->id)->assertOk()->assertSee($m->body);
        $this->scope();
        $this->deliverAll();
        Http::assertSent(fn ($r) => ($r['text'] ?? '') === $m->body);
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', 'Histórico:'));
        $before = ConversationMessage::count();
        $this->say('/historico');
        $this->assertSame($c->id, $link->fresh()->conversation_id);
        $this->assertSame($before, ConversationMessage::count());
        $this->deliverAll();
        Http::assertSent(fn ($r) => ($r['text'] ?? '') === 'Histórico: '.route('conversations.show', $c->id));
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_history_command_cannot_expose_a_different_owners_conversation(): void
    {
        $link = $this->link();
        $other = app(Engine::class)->create(Identity::web($this->admin), (string) Str::uuid());
        $other->forceFill(['user_id' => $this->operator->id])->save();
        $link->forceFill(['conversation_id' => $other->id])->save();
        $this->say('/history');
        $this->assertSame('DENIED', Update::latest('update_id')->first()->status);
        $this->deliverAll();
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', $other->id));
    }

    public function test_diagnostic_tracking_has_authenticated_details_button_without_repeated_history_footer(): void
    {
        $link = $this->link();
        $this->agent();
        $this->say('Teste google.com');
        $this->say('Sim, confirmo');
        $c = $this->conversation($link);
        $this->assertNotNull($c->current_diagnostic_id);
        $this->deliverAll();
        Http::assertSent(fn ($r) => ($r['reply_markup']['inline_keyboard'][0][0]['text'] ?? '') === 'Ver diagnóstico completo');
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', 'Histórico:'));
        $this->api();
        $this->say('ce ta bao ?');
        $m = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->firstOrFail();
        $this->deliverAll();
        Http::assertSent(fn ($r) => ($r['text'] ?? '') === $m->body);
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', 'Histórico:'));
        $this->assertSame(1, Diagnostic::count());
    }

    private function associationData(int $userId, string $telegramId = '67890'): array
    {
        return ['bot_id' => $this->bot->id, 'generation' => $this->bot->generation, 'user_id' => $userId, 'telegram_user_id' => $telegramId, 'association_ack' => '1'];
    }

    public function test_administrator_can_associate_existing_colleague_and_replay_is_idempotent(): void
    {
        config(['telegram.admin_association_enabled' => true]);
        $data = $this->associationData($this->operator->id);
        for ($n = 0; $n < 2; $n++) {
            $this->actingAs($this->admin)->postJson('/integrations/telegram/members/associate', $data)->assertRedirect(route('telegram.index').'#company-groups');
            $this->scope();
        }
        $link = Link::where('user_id', $this->operator->id)->sole();
        $this->assertSame('ACTIVE', $link->status);
        $this->assertSame('67890', (string) $link->telegram_user_id);
        $this->assertSame('ACTIVE', Member::where('link_id', $link->id)->sole()->status);
        $audit = DB::table('audit_events')->where('event_type', 'telegram.link_admin_assigned')->sole();
        $this->assertSame($this->admin->id, $audit->user_id);
        $this->assertSame($this->operator->id, $audit->entity_id);
        $this->assertSame($link->id, json_decode($audit->metadata, true)['link_id']);
        $this->receive('Boa tarde!', 67890);
        $this->process();
        $this->assertSame('PROCESSED', Update::latest('update_id')->first()->status);
        $this->assertSame($this->operator->id, Conversation::findOrFail($link->fresh()->conversation_id)->user_id);
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, AgentTask::count());
        $this->assertSame('operator', $this->operator->fresh()->role->value);
    }

    public function test_association_requires_enabled_feature_native_admin_and_explicit_acknowledgement(): void
    {
        $data = $this->associationData($this->operator->id);
        $this->actingAs($this->admin)->postJson('/integrations/telegram/members/associate', $data)->assertForbidden();
        config(['telegram.admin_association_enabled' => true]);
        $this->flushSession();
        $this->actingAs($this->operator)->postJson('/integrations/telegram/members/associate', $data)->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->viewer)->postJson('/integrations/telegram/members/associate', $data)->assertForbidden();
        $this->flushSession();
        unset($data['association_ack']);
        $this->actingAs($this->admin)->postJson('/integrations/telegram/members/associate', $data)->assertUnprocessable()->assertJsonValidationErrors('association_ack');
        $this->scope();
        $this->assertSame(0, Link::count());
    }

    public function test_association_rejects_cross_tenant_users_and_protected_account_fields(): void
    {
        config(['telegram.admin_association_enabled' => true]);
        $this->actingAs($this->admin)->postJson('/integrations/telegram/members/associate', $this->associationData($this->other->id))->assertNotFound();
        $this->actingAs($this->admin)->postJson('/integrations/telegram/members/associate', [...$this->associationData($this->operator->id), 'tenant_id' => $this->b->id, 'role' => 'tenant_admin'])->assertUnprocessable()->assertJsonValidationErrors(['tenant_id', 'role']);
        $this->scope();
        $this->assertSame(0, Link::count());
        $this->assertSame(0, Member::count());
    }

    public function test_association_rejects_viewers_disabled_users_invalid_ids_and_old_bot_generation(): void
    {
        config(['telegram.admin_association_enabled' => true]);
        $this->actingAs($this->admin)->postJson('/integrations/telegram/members/associate', $this->associationData($this->viewer->id))->assertUnprocessable();
        $this->scope();
        $this->operator->forceFill(['status' => 'disabled'])->save();
        $this->actingAs($this->admin)->postJson('/integrations/telegram/members/associate', $this->associationData($this->operator->id))->assertUnprocessable();
        foreach (['-10012345', '0', '12345678901234567', 'not-a-number'] as $id) {
            $this->actingAs($this->admin)->postJson('/integrations/telegram/members/associate', $this->associationData($this->admin->id, $id))->assertUnprocessable()->assertJsonValidationErrors('telegram_user_id');
        }
        $this->actingAs($this->admin)->postJson('/integrations/telegram/members/associate', [...$this->associationData($this->admin->id), 'generation' => (string) Str::uuid()])->assertConflict();
        $this->scope();
        $this->assertSame(0, Link::count());
    }

    public function test_administrator_cannot_steal_an_id_or_silently_replace_an_existing_link(): void
    {
        config(['telegram.admin_association_enabled' => true]);
        $this->actingAs($this->admin)->postJson('/integrations/telegram/members/associate', $this->associationData($this->operator->id))->assertRedirect();
        $this->actingAs($this->admin)->postJson('/integrations/telegram/members/associate', $this->associationData($this->admin->id))->assertUnprocessable()->assertJsonValidationErrors('telegram_user_id');
        $this->actingAs($this->admin)->postJson('/integrations/telegram/members/associate', $this->associationData($this->operator->id, '78901'))->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->scope();
        $this->assertSame(1, Link::count());
        $this->assertSame('ACTIVE', Link::sole()->status);
        $this->assertSame('67890', (string) Link::sole()->telegram_user_id);
    }

    public function test_admin_association_does_not_destroy_a_colleagues_self_pairing_code(): void
    {
        config(['telegram.admin_association_enabled' => true]);
        $this->context()->forUser($this->operator);
        app(Accounts::class)->issue($this->operator, $this->bot);
        $this->scope();
        $old = Link::where('user_id', $this->operator->id)->sole();
        $this->actingAs($this->admin)->postJson('/integrations/telegram/members/associate', $this->associationData($this->operator->id))->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->scope();
        $this->assertSame('CODE', $old->fresh()->status);
        $this->assertSame(1, Link::count());
        $this->assertSame(0, Member::count());
    }

    public function test_association_can_be_saved_but_does_not_bypass_required_password_change(): void
    {
        config(['telegram.admin_association_enabled' => true]);
        $this->operator->forceFill(['must_change_password' => true])->save();
        $this->actingAs($this->admin)->postJson('/integrations/telegram/members/associate', $this->associationData($this->operator->id))->assertRedirect();
        $this->scope();
        $this->assertTrue($this->operator->fresh()->must_change_password);
        $this->receive('Boa tarde!', 67890);
        $this->process();
        $this->assertSame('DENIED', Update::latest('update_id')->first()->status);
        $this->assertSame(0, Conversation::count());
        $this->operator->forceFill(['must_change_password' => false])->save();
        $this->receive('Boa tarde!', 67890);
        $this->process();
        $this->assertSame('PROCESSED', Update::latest('update_id')->first()->status);
        $this->assertSame(1, Conversation::count());
    }

    public function test_explicit_member_revocation_blocks_assigned_identity_even_without_a_group(): void
    {
        config(['telegram.admin_association_enabled' => true]);
        $this->actingAs($this->admin)->postJson('/integrations/telegram/members/associate', $this->associationData($this->operator->id))->assertRedirect();
        $this->scope();
        $this->assertFalse($this->bot->fresh()->restrict_members);
        $link = Link::where('user_id', $this->operator->id)->sole();
        $this->assertTrue(app(Access::class)->allows($link));
        app(Groups::class)->revokeMember(Member::where('link_id', $link->id)->sole(), $this->admin);
        $this->assertFalse(app(Access::class)->allows($link));
        $this->receive('Boa tarde!', 67890);
        $this->process();
        $this->assertSame('DENIED', Update::latest('update_id')->first()->status);
        $this->assertSame(0, Conversation::count());
    }

    public function test_association_interface_lists_only_tenant_colleagues_and_shows_administrative_origin(): void
    {
        config(['telegram.admin_association_enabled' => true]);
        $this->actingAs($this->admin)->get('/integrations/telegram')->assertOk()->assertSee('Colaborador cadastrado')->assertSee($this->operator->name)->assertDontSee($this->other->name)->assertDontSee('Cada colaborador precisa confirmar a própria identidade');
        $this->actingAs($this->admin)->postJson('/integrations/telegram/members/associate', $this->associationData($this->operator->id))->assertRedirect();
        $this->actingAs($this->admin)->get('/integrations/telegram')->assertOk()->assertSee('Associado pelo administrador');
        $this->flushSession();
        $this->actingAs($this->operator)->get('/integrations/telegram')->assertOk()->assertSee('Identidade associada pelo administrador')->assertDontSee('data-tg-admin-association', false);
    }

    public function test_contextual_sequence_in_separate_telegram_messages_and_authorized_transition(): void
    {
        $link = $this->link();
        $provider = $this->provider();
        $model = $this->model($provider);
        $prompt = app(Versioning::class)->publish($this->prompt(), $this->admin);
        app(Configuration::class)->binding(['purpose' => 'diagnostic_explanation', 'model_id' => $model->id, 'prompt_id' => $prompt->id, 'enabled' => true, 'allow_external_context' => true, 'allow_conversation_language' => true], $this->admin);
        $client = new class extends \App\Llm\Client
        {
            public array $contexts = [];

            public function call($p, $m, array $messages, ?callable $cancelled = null, string $purpose = 'diagnostic'): array
            {
                $this->contexts[] = json_decode($messages[1]['content'], true)['conversation'];

                return ['error' => 'timeout'];
            }
        };
        $this->app->instance(\App\Llm\Client::class, $client);
        foreach (['ce ta bao?', 'Tô bão também, meu rei! Kkkkk', 'Rapaz, o YouTube tá uma carroça hoje 😂'] as $body) {
            $this->say($body);
            $c = $this->conversation($link);
            $m = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->firstOrFail();
            $this->assertSame('RELAXED', $m->metadata['tone']);
            $this->assertSame('FALLBACK', $m->metadata['language_status']);
            $this->assertStringNotContainsString('assistente virtual', $m->body);
            $this->assertStringNotContainsString('disponível', $m->body);
            $this->assertSame('ACTIVE', $c->state);
        }
        $this->assertCount(3, $client->contexts);
        $this->assertSame(['user', 'assistant', 'user', 'assistant', 'user'], array_column($client->contexts[2]['recent_turns'], 'role'));
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, AgentTask::count());
        $this->deliverAll();
        Http::assertSent(fn ($r) => ($r['text'] ?? '') === $m->body);
        $this->assertStringContainsString('vídeos', $m->body);
        $this->agent();
        $this->say('Outro diagnóstico para youtube.com');
        $pending = $this->conversation($link)->context_json['pending'];
        $this->say('Tô bão também, meu rei! Kkkkk');
        $this->assertEquals($pending, $this->conversation($link)->context_json['pending']);
        $this->assertSame(0, Diagnostic::count());
        $this->say('Sim, confirmo');
        $this->assertSame(1, Diagnostic::count());
        $before = ConversationMessage::count();
        $this->say('/historico');
        $this->assertSame($before, ConversationMessage::count());
        $this->deliverAll();
        Http::assertSent(fn ($r) => ($r['text'] ?? '') === 'Histórico: '.route('conversations.show', $c->id));
    }

    public function test_telegram_mirrors_slang_then_returns_to_standard_portuguese(): void
    {
        $link = $this->link();
        $this->say('boa noite ce ta bao?');
        $c = $this->conversation($link);
        $last = fn () => ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->firstOrFail();
        $this->assertStringContainsString('bão', $last()->body);
        $this->assertSame('RELAXED', $last()->metadata['tone']);
        $this->say('marcha');
        $this->assertSame('CASUAL_ACK', $last()->metadata['social_act']);
        $this->assertStringContainsString('Marcha', $last()->body);
        $this->say('Boa noite. Como você está?');
        $this->assertSame('CASUAL', $last()->metadata['tone']);
        $this->assertSame([], $last()->metadata['style_vocabulary']);
        $this->assertDoesNotMatchRegularExpression('/\b(?:bão|chapa|cê|marcha)\b|😄/iu', $last()->body);
        $this->say('Internet hoje ta barril kk');
        $this->assertSame('RELAXED', $last()->metadata['tone']);
        $this->assertContains('barril', $last()->metadata['style_vocabulary']);
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(0, Diagnostic::count());
        $this->deliverAll();
        Http::assertSent(fn ($r) => ($r['text'] ?? '') === $last()->body);
    }

    private Bot $bot;

    private int $serial = 10;

    private string $fixtureToken;

    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram.enabled' => true, 'app.url' => 'https://central.example.test']);
        Queue::fake();
        Http::preventStrayRequests();
        $this->fixtureToken = '123456789:'.str_repeat('f', 35); // Transport fixture; never a usable bot credential.
        $this->api();
        $setting = new ConversationSetting;
        $setting->forceFill(['tenant_id' => $this->a->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $this->admin->id])->save();
        $this->bot = app(Accounts::class)->configure($this->admin, $this->fixtureToken);
        app(Accounts::class)->register($this->bot, $this->admin, true);
    }

    private function api(?array $response = null, int $status = 200): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response($response ?? ['ok' => true, 'result' => ['id' => 123456789, 'is_bot' => true, 'username' => 'lab_fixture_bot', 'message_id' => 77, 'url' => 'https://central.example.test/api/telegram/'.$this->a->id.'/'.($this->bot->id ?? ''), 'pending_update_count' => 0]], $status)]);
    }

    private function scope(): void
    {
        $this->context()->forUser($this->admin);
    }

    private function receive(string $text, int $sender = 12345, ?int $update = null, array $extra = []): array
    {
        $serial = $update ?? ++$this->serial;
        $p = ['update_id' => $serial, 'message' => ['message_id' => $serial, 'date' => now()->timestamp, 'chat' => ['id' => $sender, 'type' => 'private'], 'from' => ['id' => $sender, 'is_bot' => false, 'username' => 'untrusted-name'], 'text' => $text, ...$extra]];
        $request = Request::create('/api/telegram', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => Crypt::decryptString($this->bot->secret_cipher)], content: json_encode($p));
        $out = app(Ingress::class)->accept($request, $this->a->id, $this->bot->id);
        $this->scope();

        return $out;
    }

    private function process(): void
    {
        $this->scope();
        foreach (Update::where('status', 'PENDING')->orderBy('update_id')->pluck('id') as $id) {
            (new ProcessUpdate($this->a->id, $id))->handle(app(Runner::class));
            $this->scope();
        }
    }

    private function say(string $text): void
    {
        $this->receive($text);
        $this->process();
    }

    private function link(): Link
    {
        $code = app(Accounts::class)->issue($this->admin, $this->bot);
        $this->say('/start '.$code);
        $link = Link::where('user_id', $this->admin->id)->where('status', 'AWAITING_APPROVAL')->sole();
        app(Accounts::class)->approve($link, $this->admin);

        return $link->fresh();
    }

    private function conversation(Link $link): Conversation
    {
        return Conversation::findOrFail($link->fresh()->conversation_id);
    }

    private function deliverAll(): void
    {
        foreach (Outbound::where('status', 'PENDING')->pluck('id') as $id) {
            (new Deliver($this->a->id, $id))->handle(app(Client::class));
            $this->scope();
        }
    }

    private function agent(): Agent
    {
        app(Catalog::class)->ensure();
        $agent = new Agent;
        $agent->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'name' => 'Synthetic agent', 'hostname' => 'fixture', 'status' => 'active', 'version' => '0.6.2', 'token_hash' => hash('sha256', random_bytes(32)), 'capabilities' => Protocol::CAPABILITIES, 'metadata' => ['task_protocol' => 1], 'enrolled_at' => now()])->save();

        return $agent;
    }

    public function test_token_encrypted_redacted_and_admin_rbac(): void
    {
        $this->assertNotContains($this->fixtureToken, array_values($this->bot->toArray()));
        $this->assertNotSame($this->fixtureToken, $this->bot->token_cipher);
        $this->assertSame($this->fixtureToken, Crypt::decryptString($this->bot->token_cipher));
        $this->actingAs($this->viewer)->post('/integrations/telegram/bot', ['bot_token' => $this->fixtureToken])->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->admin)->get('/integrations/telegram')->assertOk()->assertDontSee($this->fixtureToken)->assertSee('Telegram');
    }

    public function test_secret_required_and_tenant_context_always_reset(): void
    {
        $url = '/api/telegram/'.$this->a->id.'/'.$this->bot->id;
        $this->postJson($url, ['update_id' => 1])->assertUnauthorized();
        $this->assertNull($this->context()->tenantId);
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong')->postJson($url, ['update_id' => 1])->assertUnauthorized();
        $this->scope();
        $this->assertSame(0, Update::count());
    }

    public function test_code_single_use_requires_owner_web_approval_and_not_username(): void
    {
        $code = app(Accounts::class)->issue($this->admin, $this->bot);
        $this->say('/start '.$code);
        $link = Link::where('status', 'AWAITING_APPROVAL')->sole();
        $this->assertNull($link->code_hash);
        $this->assertSame('12345', (string) $link->telegram_user_id);
        $this->say('Consulte a Knowledge privada');
        $this->assertSame(0, Conversation::count());
        $this->say('/start '.$code);
        $this->assertSame(1, Link::count());
        $this->actingAs($this->operator)->post('/integrations/telegram/links/'.$link->id.'/approve')->assertForbidden();
        $this->scope();
        app(Accounts::class)->approve($link, $this->admin);
        $this->say('Boa tarde, meu consagrado!');
        $c = $this->conversation($link);
        $this->assertSame('telegram', $c->channel);
        $this->assertSame($link->id, $c->contact_id);
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(0, Update::whereNotNull('payload_cipher')->count());
    }

    public function test_expired_code_and_expired_approval_are_rejected(): void
    {
        $code = app(Accounts::class)->issue($this->admin, $this->bot);
        $this->travel(11)->minutes();
        $this->say('/start '.$code);
        $this->assertSame(0, Link::where('status', 'AWAITING_APPROVAL')->count());
        $code = app(Accounts::class)->issue($this->admin, $this->bot);
        $this->say('/start '.$code);
        $link = Link::where('status', 'AWAITING_APPROVAL')->sole();
        $this->travel(11)->minutes();
        $this->actingAs($this->admin)->post('/integrations/telegram/links/'.$link->id.'/approve')->assertForbidden();
    }

    public function test_duplicate_update_message_and_conflicting_replay(): void
    {
        $link = $this->link();
        $this->receive('Olá!', 12345, 900);
        $this->process();
        $before = ConversationMessage::count();
        $this->assertTrue($this->receive('Olá!', 12345, 900)['duplicate']);
        $this->process();
        $this->assertSame($before, ConversationMessage::count());
        $this->expectException(HttpException::class);
        $this->receive('Sim', 12345, 900);
    }

    public function test_groups_forwarded_bot_messages_and_stale_updates_are_ignored(): void
    {
        $this->receive('Knowledge', 12345, null, ['chat' => ['id' => -100000001, 'type' => 'group']]);
        $this->receive('Knowledge', 12345, null, ['forward_origin' => ['type' => 'user']]);
        $this->receive('Knowledge', 12345, null, ['via_bot' => ['id' => 1]]);
        $this->receive('Knowledge', 12345, null, ['from' => ['id' => 12345, 'is_bot' => true]]);
        $this->receive('Sim', 12345, null, ['date' => now()->subHour()->timestamp]);
        $this->assertSame(0, Update::count());
    }

    public function test_invalid_payload_rejected_without_queue_or_private_data(): void
    {
        $url = '/api/telegram/'.$this->a->id.'/'.$this->bot->id;
        $secret = Crypt::decryptString($this->bot->secret_cipher);
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $secret)->postJson($url, ['update_id' => '1'])->assertUnprocessable();
        $this->scope();
        $this->assertSame(0, Update::count());
    }

    public function test_flood_is_limited_but_exact_retry_is_free(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->receive('Olá', 12345, 100 + $i);
        }
        $this->assertTrue($this->receive('Olá', 12345, 101)['duplicate']);
        $this->expectException(HttpException::class);
        $this->receive('extra');
    }

    public function test_natural_tone_context_and_thanks_do_not_start_tasks(): void
    {
        $link = $this->link();
        foreach (['Boa tarde, meu consagrado!', 'Salve, calabreso! Tudo certo?', 'Rapaz, o YouTube tá uma carroça hoje kkk', 'Meu BNG está apresentando perda de pacotes.', 'Obrigado, meu rei!'] as $text) {
            $this->say($text);
        }
        $c = $this->conversation($link);
        $this->assertSame('youtube.com', $c->context_json['provided_target']);
        $this->assertSame('TECHNICAL', $c->context_json['tone']['style']);
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, AgentTask::count());
        $this->deliverAll();
        $this->assertSame(0, Outbound::where('status', 'PENDING')->count());
    }

    public function test_diagnostic_requires_confirmation_then_dispatches_once_and_cancels(): void
    {
        $this->agent();
        $link = $this->link();
        $this->say('Teste google.com');
        $c = $this->conversation($link);
        $this->assertSame('WAITING_FOR_CONFIRMATION', $c->state);
        $this->assertSame(0, Diagnostic::count());
        $this->receive('Sim, confirmo o diagnóstico de google.com.', 12345, 999);
        $this->process();
        $c = $this->conversation($link);
        $this->assertSame('DIAGNOSTIC_RUNNING', $c->state);
        $this->assertSame(1, Diagnostic::count());
        app(\App\Diagnostics\Engine::class)->tick();
        $tasks = AgentTask::count();
        $this->assertGreaterThan(0, $tasks);
        $this->receive('Sim, confirmo o diagnóstico de google.com.', 12345, 999);
        $this->process();
        $this->say('Sim');
        $this->assertSame($tasks, AgentTask::count());
        $this->say('/cancelar');
        $this->assertSame('CANCELLED', $this->conversation($link)->state);
    }

    public function test_denial_different_target_and_expired_confirmation_never_execute(): void
    {
        $this->agent();
        $link = $this->link();
        $this->say('Teste google.com');
        $this->say('Sim, confirmo o diagnóstico de example.com.');
        $this->assertSame(0, Diagnostic::count());
        $this->say('Teste google.com');
        $this->say('Não');
        $this->assertArrayNotHasKey('pending', $this->conversation($link)->context_json);
        $this->say('Teste google.com');
        $this->travel(6)->minutes();
        $this->say('Sim');
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame('CANCELLED', $this->conversation($link)->state);
    }

    public function test_missing_agent_explained_and_no_task_created(): void
    {
        $link = $this->link();
        $this->say('Teste google.com');
        $this->say('Sim');
        $this->assertSame(0, Diagnostic::count());
        $this->assertStringContainsString('Agent indisponível', ConversationMessage::where('conversation_id', $this->conversation($link)->id)->latest('sequence')->first()->body);
    }

    public function test_revoke_invalidates_pending_and_suppresses_queued_private_delivery(): void
    {
        $link = $this->link();
        $this->say('Teste google.com');
        app(Accounts::class)->revoke($link->fresh(), $this->admin);
        $this->assertSame('CANCELLED', $this->conversation($link)->state);
        $this->assertArrayNotHasKey('pending', $this->conversation($link)->context_json);
        $this->say('Sim');
        $this->deliverAll();
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, Outbound::where('link_id', $link->id)->where('status', 'SENT')->count());
    }

    public function test_unlink_requires_explicit_confirmation(): void
    {
        $link = $this->link();
        $this->say('/desvincular');
        $this->assertSame('ACTIVE', $link->fresh()->status);
        $this->say('/desvincular confirmar');
        $this->assertSame('REVOKED', $link->fresh()->status);
    }

    public function test_disabled_user_and_permission_change_block_processing_and_delivery(): void
    {
        $link = $this->link();
        $this->say('Olá!');
        $this->admin->forceFill(['status' => 'disabled'])->save();
        $this->say('Teste google.com');
        $this->assertSame(1, Update::where('status', 'DENIED')->count());
        $this->deliverAll();
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, Outbound::where('link_id', $link->id)->where('status', 'SENT')->count());
    }

    public function test_force_rls_raw_sql_and_cross_tenant_routes(): void
    {
        $link = $this->link();
        $this->say('Olá');
        $this->context()->forUser($this->other);
        foreach (['telegram_bots', 'telegram_links', 'telegram_updates', 'telegram_outbox'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
            $f = DB::selectOne('SELECT relforcerowsecurity FROM pg_class WHERE relname=?', [$table]);
            $this->assertTrue($f->relforcerowsecurity);
        }
        $this->actingAs($this->other)->post('/integrations/telegram/links/'.$link->id.'/revoke')->assertNotFound();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', Crypt::decryptString($this->bot->secret_cipher))->postJson('/api/telegram/'.$this->b->id.'/'.$this->bot->id, ['update_id' => 1])->assertNotFound();
    }

    public function test_web_cannot_send_to_telegram_history_or_forge_contact(): void
    {
        $link = $this->link();
        $this->say('Olá');
        $c = $this->conversation($link);
        $this->actingAs($this->admin)->postJson('/conversations/'.$c->id.'/messages', ['request_key' => (string) Str::uuid(), 'body' => 'Sim'])->assertForbidden();
        $this->scope();
        $this->expectException(HttpException::class);
        app(Engine::class)->send(new Identity($this->a->id, $this->admin, 'telegram', (string) Str::uuid()), $c, (string) Str::uuid(), 'Sim');
    }

    public function test_api_rate_limit_is_retried_and_timeout_is_not_replayed(): void
    {
        $link = $this->link();
        $this->say('Olá');
        $out = Outbound::where('link_id', $link->id)->firstOrFail();
        $this->api(['ok' => false, 'parameters' => ['retry_after' => 30]], 429);
        (new Deliver($this->a->id, $out->id))->handle(app(Client::class));
        $this->scope();
        $this->assertSame('PENDING', $out->fresh()->status);
        $this->travel(31)->seconds();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::failedConnection()]);
        (new Deliver($this->a->id, $out->id))->handle(app(Client::class));
        $this->scope();
        $this->assertSame('UNKNOWN', $out->fresh()->status);
        $attempts = $out->fresh()->attempts;
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        (new Deliver($this->a->id, $out->id))->handle(app(Client::class));
        $this->scope();
        $this->assertSame($attempts, $out->fresh()->attempts);
    }

    public function test_bot_blocked_revokes_link_and_suppresses_remaining_messages(): void
    {
        $link = $this->link();
        $this->say('Olá');
        $this->api(['ok' => false], 403);
        $this->deliverAll();
        $this->assertSame('REVOKED', $link->fresh()->status);
    }

    public function test_credential_rotation_revokes_links_and_old_updates(): void
    {
        $link = $this->link();
        $this->receive('Sim');
        $old = $this->bot->generation;
        $this->bot = app(Accounts::class)->configure($this->admin, $this->fixtureToken);
        $this->assertNotSame($old, $this->bot->generation);
        $this->process();
        $this->assertSame('REVOKED', $link->fresh()->status);
        $this->assertSame(1, Update::where('status', 'IGNORED')->count());
    }

    public function test_rag_sources_are_live_verified_and_revoked_before_send(): void
    {
        $link = $this->link();
        $item = app(Versioning::class)->publish($this->knowledge(['content' => 'O procedimento de lentidão exige evidências. Verifique DNS e HTTPS separadamente.']), $this->admin);
        app(Index::class)->rebuild($this->admin);
        $p = $this->provider();
        $model = $this->model($p);
        $prompt = app(Versioning::class)->publish($this->prompt(['purpose' => 'knowledge_assistance']), $this->admin);
        app(Configuration::class)->binding(['purpose' => 'knowledge_assistance', 'model_id' => $model->id, 'prompt_id' => $prompt->id, 'enabled' => true, 'allow_external_context' => true], $this->admin);
        $this->app->instance(\App\Llm\Client::class, new class extends \App\Llm\Client
        {
            public function call($p, $m, array $messages, ?callable $cancelled = null, string $purpose = 'diagnostic'): array
            {
                return ['output' => ['answer' => 'O procedimento exige evidências de DNS e HTTPS.', 'sources' => DB::table('rag_chunks')->pluck('id')->all()]];
            }
        });
        $this->say('/knowledge Qual o procedimento de lentidão?');
        $c = $this->conversation($link);
        $m = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->firstOrFail();
        $this->assertNotEmpty($m->metadata['rag_sources']);
        $out = Outbound::where('message_id', $m->id)->sole();
        (new Deliver($this->a->id, $out->id))->handle(app(Client::class));
        $this->scope();
        $this->assertSame('SENT', $out->fresh()->status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], 'Fonte: versão') && str_contains($r['text'], '/knowledge/sources/'));
        $this->say('/knowledge Explique lentidão DNS');
        $m = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first();
        $out = Outbound::where('message_id', $m->id)->sole();
        app(Versioning::class)->archive($item, $this->admin);
        (new Deliver($this->a->id, $out->id))->handle(app(Client::class));
        $this->scope();
        $this->assertSame('SUPPRESSED', $out->fresh()->status);
    }

    public function test_openai_shared_binding_natural_language_and_controlled_failure(): void
    {
        $link = $this->link();
        $p = $this->provider(['timeout' => 15]);
        $model = $this->model($p);
        $prompt = app(Versioning::class)->publish($this->prompt(), $this->admin);
        app(Configuration::class)->binding(['purpose' => 'diagnostic_explanation', 'model_id' => $model->id, 'prompt_id' => $prompt->id, 'enabled' => true, 'allow_external_context' => true, 'allow_conversation_language' => true], $this->admin);
        $mock = new class extends \App\Llm\Client
        {
            public bool $fail = false;

            public function call($p, $m, array $messages, ?callable $cancelled = null, string $purpose = 'diagnostic'): array
            {
                return $this->fail ? ['error' => 'timeout'] : ['output' => ['summary' => 'Um plantão tranquilo dá espaço para conversar com calma.', 'explanation' => 'Conversa social.', 'next_steps' => []], 'usage' => ['total_tokens' => 30], 'request_id' => 'req_telegram_fixture'];
            }
        };
        $this->app->instance(\App\Llm\Client::class, $mock);
        $this->say('E você, como é ajudar num plantão tranquilo?');
        $c = $this->conversation($link);
        $m = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first();
        $this->assertSame('ASSISTED', $m->metadata['language_status']);
        $mock->fail = true;
        $this->say('Conta uma piada leve sobre um plantão tranquilo.');
        $m = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first();
        $this->assertSame('FALLBACK', $m->metadata['language_status']);
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_sweep_recovers_inbox_and_marks_interrupted_send_unknown(): void
    {
        $this->receive('Olá');
        $row = Update::sole();
        $this->assertSame('PENDING', $row->status);
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        Queue::assertPushed(ProcessUpdate::class, fn ($j) => $j->updateId === $row->id);
        $this->process();
        $out = Outbound::first();
        $out->forceFill(['status' => 'SENDING', 'updated_at' => now()->subMinutes(3)])->save();
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        $this->assertSame('UNKNOWN', $out->fresh()->status);
        $this->assertNull($row->fresh()->payload_cipher);
    }

    public function test_second_tenant_has_its_own_bot_and_identity(): void
    {
        $first = $this->link();
        $this->say('Olá');
        $this->context()->forUser($this->other);
        $setting = new ConversationSetting;
        $setting->forceFill(['tenant_id' => $this->b->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $this->other->id])->save();
        $this->api(['ok' => true, 'result' => ['id' => 987654321, 'is_bot' => true, 'username' => 'second_lab_fixture_bot']]);
        $bot = app(Accounts::class)->configure($this->other, '987654321:'.str_repeat('s', 35));
        app(Accounts::class)->register($bot, $this->other, true);
        $code = app(Accounts::class)->issue($this->other, $bot);
        $request = Request::create('/api/telegram', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => Crypt::decryptString($bot->secret_cipher)], content: json_encode(['update_id' => 1, 'message' => ['message_id' => 1, 'date' => now()->timestamp, 'chat' => ['id' => 12345, 'type' => 'private'], 'from' => ['id' => 12345, 'is_bot' => false], 'text' => '/start '.$code]]));
        app(Ingress::class)->accept($request, $this->b->id, $bot->id);
        $this->context()->forUser($this->other);
        $update = Update::sole();
        (new ProcessUpdate($this->b->id, $update->id))->handle(app(Runner::class));
        $this->context()->forUser($this->other);
        $link = Link::sole();
        app(Accounts::class)->approve($link, $this->other);
        $this->assertSame($this->other->id, $link->user_id);
        $this->assertNotSame($first->id, $link->id);
        $this->assertSame(0, Conversation::count());
        $this->assertNull(Link::find($first->id));
        $this->scope();
        $this->assertSame($first->id, Link::sole()->id);
    }

    public function test_transport_message_id_deduplicates_even_with_new_update_id(): void
    {
        $this->receive('Olá', 12345, 900);
        $result = $this->receive('Olá', 12345, 901, ['message_id' => 900]);
        $this->assertTrue($result['duplicate']);
        $this->assertSame(1, Update::count());
    }

    public function test_greeting_cannot_extend_old_confirmation_authorization(): void
    {
        $link = $this->link();
        $this->say('Teste google.com');
        $issued = $this->conversation($link)->context_json['pending']['issued_at'];
        $this->travel(6)->minutes();
        $this->say('Boa tarde!');
        $this->assertSame($issued, $this->conversation($link)->context_json['pending']['issued_at']);
        $this->say('Sim');
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame('CANCELLED', $this->conversation($link)->state);
    }

    public function test_result_followup_is_sent_once_from_existing_engine(): void
    {
        $this->agent();
        $link = $this->link();
        $this->say('Teste google.com');
        $this->say('Sim');
        $c = $this->conversation($link);
        $d = Diagnostic::findOrFail($c->current_diagnostic_id);
        $d->forceFill(['status' => 'COMPLETED', 'finished_at' => now()])->save();
        $conclusion = new DiagnosticConclusion;
        $conclusion->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'diagnostic_id' => $d->id, 'classification' => 'NORMAL', 'summary' => Classifier::NORMAL, 'evidence' => [], 'next_steps' => [], 'created_at' => now()])->save();
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        $this->assertSame('DIAGNOSTIC_COMPLETED', $this->conversation($link)->state);
        $count = Outbound::count();
        app(Sweep::class)->run($this->a->id);
        $this->scope();
        $this->assertSame($count, Outbound::count());
        $this->assertSame(1, ConversationMessage::where('event_key', 'result:'.$d->id)->count());
        $this->actingAs($this->admin)->get('/conversations/'.$c->id)->assertOk()->assertSee('Histórico do Telegram')->assertDontSee('Interpretação assistida opcional');
    }

    public function test_private_target_never_creates_task_and_missing_target_requests_it(): void
    {
        $link = $this->link();
        $this->say('Teste');
        $this->assertSame('WAITING_FOR_TARGET', $this->conversation($link)->state);
        $this->say('localhost');
        $this->say('Sim');
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, AgentTask::count());
    }

    public function test_unknown_commands_and_unlinked_users_never_call_model(): void
    {
        $mock = new class extends \App\Llm\Client
        {
            public function call($p, $m, array $messages, ?callable $cancelled = null, string $purpose = 'diagnostic'): array
            {
                throw new \LogicException('LLM must not be called');
            }
        };
        $this->app->instance(\App\Llm\Client::class, $mock);
        $this->say('Consulte a Knowledge privada');
        $this->assertSame(0, Conversation::count());
        $link = $this->link();
        $this->say('/executor rm forbidden');
        $this->assertNull($link->fresh()->conversation_id);
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_disable_channel_fences_inbox_and_deliveries(): void
    {
        $link = $this->link();
        $this->say('Olá');
        $this->receive('Teste google.com');
        app(Accounts::class)->register($this->bot, $this->admin, false);
        $this->process();
        $this->deliverAll();
        $this->assertSame(1, Update::where('status', 'IGNORED')->count());
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, Outbound::where('status', 'SENT')->count());
    }

    public function test_manual_resend_requires_admin_and_explicit_acknowledgement(): void
    {
        $link = $this->link();
        $this->say('Olá');
        $out = Outbound::where('link_id', $link->id)->firstOrFail();
        $out->forceFill(['status' => 'UNKNOWN'])->save();
        $this->actingAs($this->admin)->postJson('/integrations/telegram/messages/'.$out->id.'/retry', [])->assertUnprocessable();
        $this->post('/integrations/telegram/messages/'.$out->id.'/retry', ['acknowledge_duplicate' => '1'])->assertRedirect();
        $this->scope();
        $this->assertSame('PENDING', $out->fresh()->status);
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_new_conversation_uses_distinct_cancel_and_message_keys(): void
    {
        $link = $this->link();
        $this->say('Olá');
        $old = $this->conversation($link);
        $this->say('Teste google.com');
        $this->say('/nova');
        $new = $this->conversation($link);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('CANCELLED', $old->fresh()->state);
        $this->assertSame('ACTIVE', $new->state);
        $this->assertArrayNotHasKey('pending', $old->fresh()->context_json);
        $this->assertSame(0, Update::where('status', 'DENIED')->count());
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_operator_links_with_existing_rbac_without_admin_access(): void
    {
        $this->admin = $this->operator;
        $this->scope();
        $link = $this->link();
        $this->say('Olá');
        $this->assertSame($this->operator->id, $this->conversation($link)->user_id);
        $this->assertSame(0, Diagnostic::count());
        $this->actingAs($this->operator)->get('/integrations/telegram')->assertOk()->assertDontSee('name="bot_token"', false);
        $this->post('/integrations/telegram/test')->assertForbidden();
    }

    public function test_credentials_in_chat_are_redacted_before_history_and_delivery(): void
    {
        $link = $this->link();
        $this->say('Meu token: '.$this->fixtureToken);
        $c = $this->conversation($link);
        $body = ConversationMessage::where('conversation_id', $c->id)->where('sender_type', 'user')->latest('sequence')->value('body');
        $this->assertStringNotContainsString($this->fixtureToken, $body);

        $this->deliverAll();
        Http::assertNotSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), $this->fixtureToken));
    }

    public function test_disable_reenable_cannot_revive_old_confirmation_or_inbox(): void
    {
        $this->agent();
        $link = $this->link();
        $this->say('Teste google.com');
        $c = $this->conversation($link);
        $this->receive('Sim');
        $old = $this->bot->generation;
        app(Accounts::class)->register($this->bot, $this->admin, false);
        $this->assertNotSame($old, $this->bot->generation);
        $this->assertSame('REVOKED', $link->fresh()->status);
        $this->assertSame('CANCELLED', $c->fresh()->state);
        $this->assertArrayNotHasKey('pending', $c->fresh()->context_json);
        app(Accounts::class)->register($this->bot, $this->admin, true);
        $this->process();
        $this->say('Sim');
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(1, Update::where('status', 'IGNORED')->count());
    }

    public function test_platform_administrator_identity_stays_in_the_verified_tenant_in_a_worker(): void
    {
        $this->context()->forUser($this->platform, $this->a->id);
        $code = app(Accounts::class)->issue($this->platform, $this->bot);
        $this->say('/start '.$code);
        $link = Link::where('user_id', $this->platform->id)->sole();
        $this->context()->forUser($this->platform, $this->a->id);
        app(Accounts::class)->approve($link, $this->platform);
        // Workers start with a tenant, without an authenticated browser user.
        $this->context()->apply($this->a->id);
        $identity = app(Accounts::class)->identity($link->fresh());
        $this->assertSame($this->platform->id, $identity->user->id);
        $this->assertNull($identity->user->tenant_id);
        $this->assertSame($this->a->id, $identity->tenantId);
        $this->assertSame($this->a->id, $this->context()->tenantId);
        $this->say('Boa tarde!');
        $this->assertSame(0, Update::where('status', '!=', 'PROCESSED')->count());
        $conversation = $this->conversation($link);
        $this->assertSame($this->platform->id, $conversation->user_id);
        $this->assertSame($this->a->id, $conversation->tenant_id);
        $this->assertSame('telegram', $conversation->channel);
        $this->deliverAll();
        $this->assertTrue(Outbound::where('link_id', $link->id)->where('status', 'SENT')->exists());
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, AgentTask::count());
        $this->context()->forUser($this->other);
        $this->assertNull(Link::find($link->id));
        $this->assertNull(Conversation::find($conversation->id));
    }

    private function groupMessage(string $text, int $sender = 12345, int $chat = -100000001, int $thread = 0): array
    {
        $out = $this->receive($text, $sender, extra: ['chat' => ['id' => $chat, 'type' => 'supergroup', 'title' => 'Empresa A'], 'message_thread_id' => $thread]);
        $this->process();

        return $out;
    }

    private function companyGroup(string $mode = 'private'): array
    {
        $link = $this->link();
        [$group, $code] = app(Groups::class)->issue($this->admin, $this->bot, $mode, $mode === 'group');
        $this->groupMessage('/vincular_grupo@lab_fixture_bot '.$code);
        app(Groups::class)->approve($group->fresh(), $this->admin);

        return [$group->fresh(), $link];
    }

    private function collaborator(): Link
    {
        $this->context()->forUser($this->operator);
        $code = app(Accounts::class)->issue($this->operator, $this->bot);
        $this->receive('/start '.$code, 23456);
        $this->process();
        $link = Link::where('user_id', $this->operator->id)->sole();
        $this->context()->forUser($this->operator);
        app(Accounts::class)->approve($link, $this->operator);
        $this->scope();

        return $link->fresh();
    }

    public function test_group_pairing_requires_verified_requesting_administrator_and_panel_confirmation(): void
    {
        $this->link();
        [$group, $code] = app(Groups::class)->issue($this->admin, $this->bot, 'private', false);
        $this->groupMessage('/vincular_grupo@lab_fixture_bot '.$code, 99999);
        $this->assertSame('CODE', $group->fresh()->status);
        $this->groupMessage('/vincular_grupo@lab_fixture_bot '.$code);
        $this->assertSame('AWAITING_APPROVAL', $group->fresh()->status);
        $this->assertNull($group->fresh()->code_hash);
        $this->assertSame(0, Conversation::count());
        app(Groups::class)->approve($group->fresh(), $this->admin);
        $this->assertSame('ACTIVE', $group->fresh()->status);
        $this->assertTrue($this->bot->fresh()->restrict_members);
        $this->assertSame(1, Member::where('status', 'ACTIVE')->count());
    }

    public function test_group_public_responses_require_explicit_visibility_acknowledgment(): void
    {
        $this->link();
        $this->actingAs($this->admin)->postJson('/integrations/telegram/groups/code', ['reply_mode' => 'group'])->assertUnprocessable();
        $this->assertSame(0, Group::count());
        $this->flushSession();
        $this->actingAs($this->operator)->postJson('/integrations/telegram/groups/code', ['reply_mode' => 'private'])->assertForbidden();
    }

    public function test_unknown_group_other_bot_and_unaddressed_group_chatter_are_ignored(): void
    {
        $r = $this->groupMessage('@lab_fixture_bot Boa tarde!', chat: -100009999);
        $this->assertTrue($r['ignored']);
        $this->companyGroup();
        $before = Update::count();
        $this->assertTrue($this->groupMessage('Conversa normal da equipe')['ignored']);
        $this->assertTrue($this->groupMessage('/help@another_bot')['ignored']);
        $this->assertSame($before, Update::count());
    }

    public function test_unknown_people_get_only_identification_notice_in_group_and_private(): void
    {
        $this->companyGroup();
        $this->groupMessage('@lab_fixture_bot Teste google.com', 99999);
        $this->receive('Teste google.com', 99999);
        $this->process();
        $this->assertSame(0, Conversation::count());
        $this->assertSame(0, Diagnostic::count());
        $notices = Outbound::whereNull('link_id')->whereNotNull('body_cipher')->get()->map(fn ($o) => Crypt::decryptString($o->body_cipher));
        $this->assertSame(2, $notices->filter(fn ($body) => $body === Access::DENIED)->count());
    }

    public function test_linked_collaborator_requires_company_approval_in_both_places(): void
    {
        [$group] = $this->companyGroup();
        $link = $this->collaborator();
        $this->receive('Boa tarde!', 23456);
        $this->process();
        $this->groupMessage('@lab_fixture_bot Boa tarde!', 23456);
        $this->assertSame(0, Conversation::count());
        app(Groups::class)->authorizeMember($this->bot, $link, $this->admin);
        $this->receive('Boa tarde!', 23456);
        $this->process();
        $this->groupMessage('@lab_fixture_bot Boa tarde!', 23456);
        $private = $link->fresh()->conversation_id;
        $context = GroupContext::where('group_id', $group->id)->where('link_id', $link->id)->sole();
        $this->assertNotNull($private);
        $this->assertNotSame($private, $context->conversation_id);
        $this->assertSame($this->operator->id, Conversation::findOrFail($context->conversation_id)->user_id);
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_group_private_mode_sends_content_only_to_verified_private_chat(): void
    {
        [$group, $link] = $this->companyGroup();
        $this->groupMessage('@lab_fixture_bot Boa tarde!');
        $this->deliverAll();
        $rows = Outbound::where('group_id', $group->id)->whereNotNull('message_id')->get();
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame((string) $link->chat_id, (string) $row->chat_id);
            $this->assertSame('SENT', $row->status);
        }
        Http::assertNotSent(fn ($r) => (string) ($r['chat_id'] ?? '') === (string) $group->chat_id && isset($r['text']) && str_contains($r['text'], 'Histórico:'));
        Http::assertSent(fn ($r) => (string) ($r['chat_id'] ?? '') === (string) $link->chat_id && str_contains($r['text'] ?? '', 'no mesmo grupo'));
    }

    public function test_group_public_mode_keeps_topics_and_user_contexts_separate(): void
    {
        [$group, $link] = $this->companyGroup('group');
        $this->groupMessage('@lab_fixture_bot Boa tarde!', thread: 7);
        $this->groupMessage('@lab_fixture_bot Boa tarde!', thread: 8);
        $contexts = GroupContext::where('group_id', $group->id)->where('link_id', $link->id)->get();
        $this->assertCount(2, $contexts);
        $this->assertSame(2, $contexts->pluck('conversation_id')->unique()->count());
        $this->deliverAll();
        Http::assertSent(fn ($r) => (string) ($r['chat_id'] ?? '') === (string) $group->chat_id && ($r['message_thread_id'] ?? null) === 7 && str_contains($r['text'] ?? '', 'Grupo:') && ! str_contains($r['text'] ?? '', 'Histórico:'));
        Http::assertSent(fn ($r) => (string) ($r['chat_id'] ?? '') === (string) $group->chat_id && ($r['message_thread_id'] ?? null) === 8 && str_contains($r['text'] ?? '', 'Grupo:') && ! str_contains($r['text'] ?? '', 'Histórico:'));
    }

    public function test_revoking_company_member_cancels_confirmation_and_suppresses_delivery(): void
    {
        [$group, $link] = $this->companyGroup();
        $agent = $this->agent();
        $this->groupMessage('/nova@lab_fixture_bot '.$agent->id);
        $this->groupMessage('@lab_fixture_bot Teste google.com');
        $context = GroupContext::where('link_id', $link->id)->sole();
        $conversation = Conversation::findOrFail($context->conversation_id);
        $this->assertSame('WAITING_FOR_CONFIRMATION', $conversation->state);
        $member = Member::where('link_id', $link->id)->sole();
        app(Groups::class)->revokeMember($member, $this->admin);
        $this->assertSame('CANCELLED', $conversation->fresh()->state);
        app(Groups::class)->authorizeMember($this->bot, $link, $this->admin);
        $this->groupMessage('@lab_fixture_bot Sim');
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, AgentTask::count());
        $this->assertTrue(Outbound::where('group_id', $group->id)->where('status', 'SUPPRESSED')->exists());
    }

    public function test_group_revocation_fences_queued_updates_and_cannot_expose_context_to_private_identity(): void
    {
        [$group, $link] = $this->companyGroup();
        $this->groupMessage('@lab_fixture_bot Boa tarde!');
        $context = GroupContext::where('link_id', $link->id)->sole();
        $identity = app(Accounts::class)->identity($link);
        try {
            app(Engine::class)->send($identity, Conversation::findOrFail($context->conversation_id), (string) Str::uuid(), 'Sim');
            $this->fail('Private identity must not authorize group context');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        app(Groups::class)->revoke($group, $this->admin);
        $this->deliverAll();
        $this->assertSame(0, Outbound::where('group_id', $group->id)->whereNotNull('message_id')->where('status', 'SENT')->count());
    }

    public function test_group_and_member_routes_reject_cross_tenant_ids_and_unverified_numeric_ids(): void
    {
        [$group] = $this->companyGroup();
        $member = Member::sole();
        $this->flushSession();
        $this->actingAs($this->other)->post('/integrations/telegram/groups/'.$group->id.'/revoke')->assertNotFound();
        $this->actingAs($this->other)->post('/integrations/telegram/members/'.$member->id.'/revoke')->assertNotFound();
        $this->flushSession();
        $this->actingAs($this->admin)->post('/integrations/telegram/members', ['telegram_user_id' => '9999999'])->assertSessionHasErrors('telegram_user_id');
        $this->scope();
        $this->assertSame('ACTIVE', $group->fresh()->status);
        $this->context()->apply();
        foreach (['telegram_groups', 'telegram_members', 'telegram_group_contexts'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
            $this->assertFalse((bool) DB::selectOne('SELECT has_table_privilege(current_user, ?, ?) AS allowed', [$table, 'DELETE'])->allowed);
        }
    }

    public function test_setup_guide_tracks_own_identity_and_real_delivery_not_another_users_link(): void
    {
        $link = $this->link();
        $this->actingAs($this->operator)->get('/integrations/telegram')->assertOk()->assertViewHas('setup', fn ($setup) => ! $setup['linked'] && $setup['current'] === 3);
        $this->scope();
        $this->say('Boa tarde!');
        $before = app(SetupProgress::class)->forUser($this->admin, $this->bot);
        $this->assertFalse($before['tested']);
        $this->deliverAll();
        $after = app(SetupProgress::class)->forUser($this->admin, $this->bot);
        $this->assertTrue($after['tested']);
        $this->assertSame(5, $after['completed']);
        $this->assertSame($link->id, $after['link']->id);
    }

    public function test_group_replay_cannot_move_a_message_to_another_topic(): void
    {
        $this->companyGroup('group');
        $extra = ['chat' => ['id' => -100000001, 'type' => 'supergroup'], 'message_thread_id' => 7];
        $this->receive('@lab_fixture_bot Boa tarde!', 12345, 98765, $extra);
        $this->process();
        $this->assertTrue($this->receive('@lab_fixture_bot Boa tarde!', 12345, 98765, $extra)['duplicate']);
        $this->expectException(HttpException::class);
        $extra['message_thread_id'] = 8;
        $this->receive('@lab_fixture_bot Boa tarde!', 12345, 98765, $extra);
    }

    public function test_group_permission_is_revalidated_before_knowledge_sources_are_sent(): void
    {
        [$group, $link] = $this->companyGroup();
        $item = app(Versioning::class)->publish($this->knowledge(['content' => 'O procedimento de lentidão exige evidências. Verifique DNS e HTTPS separadamente.']), $this->admin);
        app(Index::class)->rebuild($this->admin);
        $provider = $this->provider();
        $model = $this->model($provider);
        $prompt = app(Versioning::class)->publish($this->prompt(['purpose' => 'knowledge_assistance']), $this->admin);
        app(Configuration::class)->binding(['purpose' => 'knowledge_assistance', 'model_id' => $model->id, 'prompt_id' => $prompt->id, 'enabled' => true, 'allow_external_context' => true], $this->admin);
        $this->app->instance(\App\Llm\Client::class, new class extends \App\Llm\Client
        {
            public function call($p, $m, array $messages, ?callable $cancelled = null, string $purpose = 'diagnostic'): array
            {
                return ['output' => ['answer' => 'O procedimento exige evidências de DNS e HTTPS.', 'sources' => DB::table('rag_chunks')->pluck('id')->all()]];
            }
        });
        $this->groupMessage('/knowledge@lab_fixture_bot Qual o procedimento de lentidão?');
        $context = GroupContext::where('link_id', $link->id)->sole();
        $message = ConversationMessage::where('conversation_id', $context->conversation_id)->latest('sequence')->firstOrFail();
        $this->assertNotEmpty($message->metadata['rag_sources']);
        $out = Outbound::where('message_id', $message->id)->sole();
        app(Versioning::class)->archive($item, $this->admin);
        (new Deliver($this->a->id, $out->id))->handle(app(Client::class));
        $this->scope();
        $this->assertSame('SUPPRESSED', $out->fresh()->status);
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_runtime_cannot_drop_group_schema_or_history(): void
    {
        $this->companyGroup();
        $migration = require database_path('migrations/2026_11_12_000002_add_telegram_companies.php');
        try {
            DB::transaction(fn () => $migration->down());
            $this->fail('Schema rollback must not drop a configured group');
        } catch (QueryException $error) {
            $this->assertSame('42501', $error->getCode());
        }
        $this->assertSame(1, Group::where('status', 'ACTIVE')->count());
    }
}
