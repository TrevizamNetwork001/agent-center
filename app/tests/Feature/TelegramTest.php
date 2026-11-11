<?php

namespace Tests\Feature;

use App\Agent\Protocol;
use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Diagnostics\Catalog;
use App\Diagnostics\Classifier;
use App\Knowledge\Versioning;
use App\Llm\Configuration;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationSetting;
use App\Models\Diagnostic;
use App\Models\DiagnosticConclusion;
use App\Rag\Index;
use App\Telegram\Accounts;
use App\Telegram\Bot;
use App\Telegram\Client;
use App\Telegram\Deliver;
use App\Telegram\Ingress;
use App\Telegram\Link;
use App\Telegram\Outbound;
use App\Telegram\ProcessUpdate;
use App\Telegram\Runner;
use App\Telegram\Sweep;
use App\Telegram\Update;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\IntelligenceTestCase;

class TelegramTest extends IntelligenceTestCase
{
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
        $this->receive('Knowledge', 12345, null, ['chat' => ['id' => 12345, 'type' => 'group']]);
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
}
