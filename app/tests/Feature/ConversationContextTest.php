<?php

namespace Tests\Feature;

use App\Agent\Protocol;
use App\Conversation\ContextualTurn;
use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Diagnostics\Catalog;
use App\Knowledge\Versioning;
use App\Llm\Client;
use App\Llm\Configuration;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\ConversationMessage;
use App\Models\ConversationSetting;
use App\Models\Diagnostic;
use App\Telegram\Bot;
use App\Telegram\Link;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\IntelligenceTestCase;

class ConversationContextTest extends IntelligenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram.enabled' => true]);
        (new ConversationSetting)->forceFill(['tenant_id' => $this->a->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $this->admin->id])->save();
        $prompt = app(Versioning::class)->publish($this->prompt(), $this->admin);
        $provider = $this->provider();
        $model = $this->model($provider);
        app(Configuration::class)->binding(['purpose' => 'diagnostic_explanation', 'model_id' => $model->id, 'prompt_id' => $prompt->id, 'enabled' => true, 'allow_external_context' => true, 'allow_conversation_language' => true], $this->admin);
    }

    private function identity(string $channel): Identity
    {
        if ($channel === 'web') {
            return Identity::web($this->admin);
        }
        $bot = Bot::first();
        if (! $bot) {
            $bot = (new Bot)->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'token_cipher' => Crypt::encryptString('123456789:'.str_repeat('f', 35)), 'token_fingerprint' => hash('sha256', random_bytes(32)), 'secret_cipher' => Crypt::encryptString(Str::random(64)), 'username' => 'context_fixture_bot', 'telegram_bot_id' => 123456789, 'enabled' => true, 'generation' => (string) Str::uuid(), 'webhook_status' => 'REGISTERED']);
            $bot->save();
            (new Link)->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'user_id' => $this->admin->id, 'bot_id' => $bot->id, 'telegram_user_id' => 12345, 'chat_id' => 12345, 'status' => 'ACTIVE', 'expires_at' => now()->addDay(), 'verified_at' => now(), 'cursor' => 0])->save();
        }

        return new Identity($this->a->id, $this->admin, 'telegram', Link::sole()->id);
    }

    private function question(string $channel, string $question): array
    {
        foreach (['conversation:language:user:'.$this->a->id.':'.$this->admin->id, 'conversation:language:tenant:'.$this->a->id] as $key) {
            RateLimiter::clear($key);
        }
        $client = new class($question) extends Client
        {
            public array $inputs = [];

            public function __construct(private string $question) {}

            public function call($p, $m, array $messages, ?callable $cancelled = null, string $purpose = 'diagnostic'): array
            {
                $this->inputs[] = json_decode($messages[1]['content'], true)['conversation'];

                return ['output' => ['summary' => count($this->inputs) === 1 ? $this->question : 'Pode continuar, estou acompanhando o contexto.', 'explanation' => 'Mensagem conversacional, sem ação operacional.', 'next_steps' => []]];
            }
        };
        app()->instance(Client::class, $client);
        $identity = $this->identity($channel);
        $c = app(Engine::class)->create($identity, (string) Str::uuid());
        $c = app(Engine::class)->send($identity, $c, (string) Str::uuid(), 'Estou aguardando uma resposta sobre meu pedido');
        $this->assertSame($question, ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->value('body'));

        return [$c, $identity, $client];
    }

    public function test_exact_waiting_question_and_fifteen_days_are_linked_in_web_and_telegram(): void
    {
        foreach (['web', 'telegram'] as $channel) {
            [$c, $identity, $client] = $this->question($channel, 'Há quanto tempo você está esperando resposta?');
            $topic = $c->context_json['topic'];
            $key = (string) Str::uuid();
            $c = app(Engine::class)->send($identity, $c, $key, '15 dias');
            $m = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->firstOrFail();
            $this->assertStringContainsString('esperando resposta há 15 dias', $m->body);
            $this->assertStringNotContainsString('Pode continuar', $m->body);
            $this->assertSame('waiting_duration', $m->metadata['context_answer']['kind']);
            $this->assertSame($topic, $c->context_json['topic']);
            $this->assertSame('15 dias', $client->inputs[1]['question_answer_context']['value']);
            $this->assertSame('reply_received', $c->context_json['pending_question']['kind']);
            $count = ConversationMessage::where('conversation_id', $c->id)->count();
            app(Engine::class)->send($identity, $c, $key, '15 dias');
            $this->assertSame($count, ConversationMessage::where('conversation_id', $c->id)->count());
            $c = app(Engine::class)->send($identity, $c, (string) Str::uuid(), 'não respondem');
            $this->assertStringContainsString('sem retorno', ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->value('body'));
        }
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, AgentTask::count());
    }

    public function test_short_duration_onset_and_affected_scope_variants_use_the_previous_question(): void
    {
        [$c, $identity] = $this->question('web', 'Há quanto tempo você está esperando resposta?');
        $topic = $c->context_json['topic'];
        $c = app(Engine::class)->send($identity, $c, (string) Str::uuid(), 'kkkk');
        $this->assertSame($topic, $c->context_json['topic']);
        $c = app(Engine::class)->send($identity, $c, (string) Str::uuid(), '15 dias');
        $this->assertStringContainsString('15 dias', ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->value('body'));
        foreach ([['Desde quando isso acontece?', 'desde ontem', 'desde'], ['A dificuldade ocorre em todos os clientes ou apenas em alguns?', 'só alguns', 'alguns clientes'], ['A dificuldade ocorre em todos os clientes ou apenas em alguns?', 'todos os clientes', 'todos os clientes'], ['Quando começou?', 'começou hoje', 'hoje']] as [$question, $answer, $expected]) {
            [$c, $identity] = $this->question('web', $question);
            $c = app(Engine::class)->send($identity, $c, (string) Str::uuid(), $answer);
            $body = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->value('body');
            $this->assertStringContainsString($expected === 'desde' ? 'ontem' : $expected, $body);
            $this->assertStringNotContainsString('Pode continuar', $body);
            $this->assertSame(0, AgentTask::count());
        }
    }

    public function test_legacy_last_question_is_recovered_without_a_migration_or_repeating_the_question(): void
    {
        [$c, $identity] = $this->question('web', 'Há quanto tempo você está esperando resposta?');
        $c->forceFill(['context_json' => array_diff_key($c->context_json, ['pending_question' => true, 'context_memory_version' => true])])->save();
        $c = app(Engine::class)->send($identity, $c, (string) Str::uuid(), '15 dias');
        $body = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->value('body');
        $this->assertStringContainsString('15 dias', $body);
        $this->assertStringNotContainsString('Há quanto tempo', $body);
    }

    public function test_explicit_close_variants_preserve_history_and_create_no_task_in_both_channels(): void
    {
        foreach (['web', 'telegram'] as $channel) {
            foreach (['Pode encerrar.', 'valeu, pode fechar', 'pode finalizar a conversa'] as $close) {
                [$c, $identity] = $this->question($channel, 'Há quanto tempo você está esperando resposta?');
                $old = $c->id;
                $c = app(Engine::class)->send($identity, $c, (string) Str::uuid(), $close);
                $this->assertSame('CLOSED', $c->state);
                $this->assertSame($old, $c->id);
                $this->assertArrayNotHasKey('pending_question', $c->context_json);
                $this->assertGreaterThan(0, ConversationMessage::where('conversation_id', $old)->count());
                $this->assertSame(0, Diagnostic::count());
            }
        }
    }

    public function test_close_while_diagnostic_active_requires_its_existing_cancellation_confirmation(): void
    {
        app(Catalog::class)->ensure();
        (new Agent)->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'name' => 'Context LAB', 'hostname' => 'synthetic', 'status' => 'active', 'version' => '0.6.2', 'token_hash' => hash('sha256', random_bytes(32)), 'capabilities' => Protocol::CAPABILITIES, 'metadata' => ['task_protocol' => 1], 'enrolled_at' => now()])->save();
        $identity = Identity::web($this->admin);
        $c = app(Engine::class)->create($identity, (string) Str::uuid());
        $c = app(Engine::class)->send($identity, $c, (string) Str::uuid(), 'Teste youtube.com');
        $this->assertSame('DIAGNOSTIC_RUNNING', $c->state);
        $c = app(Engine::class)->send($identity, $c, (string) Str::uuid(), 'valeu, pode fechar');
        $this->assertSame('DIAGNOSTIC_RUNNING', $c->state);
        $this->assertTrue($c->context_json['confirm_cancel']);
        $this->assertSame('PENDING', Diagnostic::findOrFail($c->current_diagnostic_id)->status);
        $this->assertNull(Diagnostic::findOrFail($c->current_diagnostic_id)->cancel_requested_at);
        $c = app(Engine::class)->send($identity, $c, (string) Str::uuid(), 'Sim, confirmo');
        $this->assertSame('CANCELLED', $c->state);
        $this->assertSame(1, Diagnostic::count());
    }

    public function test_question_memory_cannot_cross_conversation_owner_or_tenant(): void
    {
        [$c] = $this->question('web', 'Há quanto tempo você está esperando resposta?');
        $this->context()->forUser($this->other);
        try {
            app(ContextualTurn::class)->answer($c, '15 dias');
            $this->fail('Cross-tenant conversational memory allowed');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->context()->forUser($this->admin);
        $other = app(Engine::class)->create(Identity::web($this->admin), (string) Str::uuid());
        $this->assertNull(app(ContextualTurn::class)->answer($other, '15 dias'));
    }
}
