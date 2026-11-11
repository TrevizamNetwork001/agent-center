<?php

namespace Tests\Feature;

use App\Agent\Protocol;
use App\Conversation\ConversationToneDetector;
use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Conversation\Intent;
use App\Conversation\IntentDetector;
use App\Conversation\State;
use App\Knowledge\Versioning;
use App\Llm\Client;
use App\Llm\Configuration;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationSetting;
use App\Models\Diagnostic;
use App\Models\LlmBinding;
use App\Rag\Answer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\IntelligenceTestCase;

class NaturalConversationTest extends IntelligenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $s = new ConversationSetting;
        $s->forceFill(['tenant_id' => $this->a->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $this->admin->id])->save();
    }

    private function conversation(): Conversation
    {
        return app(Engine::class)->create(Identity::web($this->admin), (string) Str::uuid());
    }

    private function say(Conversation $c, string $body, ?string $key = null): Conversation
    {
        return app(Engine::class)->send(Identity::web($this->admin), $c, $key ?? (string) Str::uuid(), $body);
    }

    private function last(Conversation $c): ConversationMessage
    {
        return ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->firstOrFail();
    }

    private function binding(bool $allow = true): void
    {
        $p = $this->provider(['timeout' => 15]);
        $m = $this->model($p);
        $prompt = app(Versioning::class)->publish($this->prompt(), $this->admin);
        app(Configuration::class)->binding(['purpose' => 'diagnostic_explanation', 'model_id' => $m->id, 'prompt_id' => $prompt->id, 'enabled' => true, 'allow_external_context' => true, 'allow_conversation_language' => $allow], $this->admin);
    }

    private function client(array $response = []): Client
    {
        $client = new class($response) extends Client
        {
            public array $calls = [];

            public function __construct(private array $response) {}

            public function call($provider, $model, array $messages, ?callable $cancelled = null, string $purpose = 'diagnostic'): array
            {
                $this->calls[] = ['timeout' => $provider->timeout, 'messages' => $messages, 'tenant' => $provider->tenant_id];

                return $this->response ?: ['output' => ['summary' => 'Um plantão com bom humor fica mais leve. Estou por aqui!', 'explanation' => 'Mensagem conversacional, sem ação operacional.', 'next_steps' => []], 'usage' => ['total_tokens' => 70], 'request_id' => 'req_fixture'];
            }
        };
        $this->app->instance(Client::class, $client);

        return $client;
    }

    public function test_greetings_slang_and_humor_are_varied_without_operational_menu(): void
    {
        $client = $this->client();
        $c = $this->conversation();
        $replies = [];
        foreach (['Boa tarde, meu consagrado!', 'E aí, meu rei, tudo certo?', 'Salve, calabreso!', 'Fala, meu parceiro!', 'Salve, calabreso!', 'kkkk que plantão hoje'] as $body) {
            $c = $this->say($c, $body);
            $replies[] = $this->last($c)->body;
            $this->assertStringNotContainsString('Qual site', $this->last($c)->body);
            $this->assertStringNotContainsString('Informe o site', $this->last($c)->body);
        }
        $this->assertGreaterThan(3, count(array_unique($replies)));
        $this->assertCount(0, $client->calls);
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, AgentTask::count());
        $this->assertSame('RELAXED', $c->fresh()->context_json['tone']['style']);
    }

    public function test_formal_technical_and_incident_tone_overrides_earlier_humor(): void
    {
        $detector = app(ConversationToneDetector::class);
        $this->assertSame('FORMAL', $detector->detect(['Boa tarde, por gentileza.'])['style']);
        $tone = $detector->detect(['Salve calabreso kkkk', 'Meu BNG está apresentando perda de pacotes.']);
        $this->assertSame('TECHNICAL', $tone['style']);
        $this->assertSame('SERIOUS', $tone['severity']);
        $this->assertSame('', $detector->prefix('kkkk', $tone));
        $this->assertSame('NOT_INFERRED', $tone['regionality']);
        $this->binding();
        $client = $this->client();
        $c = $this->say($this->conversation(), 'Salve, calabreso!');
        $c = $this->say($c, 'Meu BNG está apresentando perda de pacotes.');
        $this->assertStringContainsString('Ainda não há evidências', $this->last($c)->body);
        $this->assertStringNotContainsString('Fechou', $this->last($c)->body);
        $c = $this->say($c, 'kkkk complicado');
        $this->assertSame('SERIOUS', $c->context_json['tone']['severity']);
        $this->assertCount(0, $client->calls);
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_casual_complaint_is_not_authorization_and_known_target_is_not_requested_again(): void
    {
        $c = $this->say($this->conversation(), 'Rapaz, a rede tá uma carroça hoje kkkk');
        $this->assertSame('ACTIVE', $c->state);
        $c = $this->say($c, 'A rede de google.com está lenta');
        $this->assertSame('google.com', $c->context_json['provided_target']);
        $c = $this->say($c, 'Pode executar');
        $this->assertSame(0, Diagnostic::count());
        $agent = new Agent;
        $agent->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'name' => 'Synthetic', 'hostname' => 'lab', 'status' => 'active', 'version' => '0.6.2', 'token_hash' => hash('sha256', random_bytes(32)), 'capabilities' => Protocol::CAPABILITIES, 'metadata' => ['task_protocol' => 1], 'enrolled_at' => now()])->save();
        $c = $this->say($c, 'Testa o site');
        $this->assertSame('DIAGNOSTIC_RUNNING', $c->fresh()->state);
        $this->assertSame('google.com', Diagnostic::sole()->target);
        $this->assertArrayHasKey('tone', $c->context_json);
        $this->assertArrayHasKey('topic', $c->context_json);
    }

    public function test_greeting_preserves_pending_but_explicit_topic_change_invalidates_it(): void
    {
        $c = $this->say($this->conversation(), 'Outro diagnóstico para google.com');
        $pending = $c->context_json['pending'];
        $c = $this->say($c, 'Fala, meu parceiro!');
        $this->assertEquals($pending, $c->fresh()->context_json['pending']);
        $c = $this->say($c, 'Mudando de assunto, como vai?');
        $this->assertArrayNotHasKey('pending', $c->fresh()->context_json);
        $this->assertSame('FOLLOW_UP', $c->state);
        $c = $this->say($c, 'Sim, confirmo o diagnóstico de google.com');
        $this->assertSame(0, Diagnostic::count());
        $this->assertGreaterThan(6, ConversationMessage::where('conversation_id', $c->id)->count());
    }

    public function test_knowledge_question_reuses_rag_and_missing_evidence_is_honest(): void
    {
        $this->client();
        $c = $this->say($this->conversation(), 'O que a Knowledge diz sobre astronomia sem fontes?');
        $this->assertSame('no_match', $this->last($c)->metadata['rag_status']);
        $this->assertSame(Answer::EMPTY, $this->last($c)->body);
        $this->assertSame(0, AgentTask::count());
    }

    public function test_authorized_language_uses_existing_binding_and_sanitized_bounded_history(): void
    {
        $this->binding();
        $client = $this->client();
        $c = $this->say($this->conversation(), 'Conte uma piada leve, senha: fixture-sensitive-password');
        $this->assertSame('ASSISTED', $this->last($c)->metadata['language_status']);
        $this->assertSame(1, $c->fresh()->context_json['language_calls']);
        $this->assertCount(1, $client->calls);
        $this->assertSame(10, $client->calls[0]['timeout']);
        $this->assertStringNotContainsString('fixture-sensitive-password', json_encode($client->calls));
        $this->assertStringContainsString('no tools', $client->calls[0]['messages'][0]['content']);
        $this->assertSame('req_fixture', $this->last($c)->metadata['request_id']);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'conversation.language_rendered')->count());
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_external_context_permission_alone_does_not_authorize_social_calls(): void
    {
        $this->binding(false);
        $client = $this->client();
        $c = $this->say($this->conversation(), 'Conta uma piada leve sobre plantão');
        $this->assertSame('LOCAL', $this->last($c)->metadata['language_status']);
        $this->assertCount(0, $client->calls);
    }

    public function test_language_failure_and_unsafe_output_fall_back_without_affecting_state(): void
    {
        $this->binding();
        foreach ([['error' => 'timeout'], ['output' => ['summary' => 'Executei um diagnóstico e iniciei tarefas.', 'next_steps' => []]], ['output' => ['summary' => 'Olá!', 'next_steps' => ['execute shell']]]] as $response) {
            $this->client($response);
            $c = $this->say($this->conversation(), 'Conta uma piada de plantão');
            $this->assertSame('FALLBACK', $this->last($c)->metadata['language_status']);
            $this->assertSame('ACTIVE', $c->fresh()->state);
            $this->assertStringNotContainsString('Executei', $this->last($c)->body);
        }
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, AgentTask::count());
    }

    public function test_duplicate_messages_and_rate_limits_bound_cost(): void
    {
        $this->binding();
        $client = $this->client();
        $c = $this->conversation();
        $key = (string) Str::uuid();
        $body = 'Conta uma piada leve de plantão';
        $c = $this->say($c, $body, $key);
        $count = ConversationMessage::count();
        $this->say($c, $body, $key);
        $this->assertSame($count, ConversationMessage::count());
        $this->assertCount(1, $client->calls);
        for ($n = 0; $n < 4; $n++) {
            $c = $this->say($c, $body.' '.$n);
        }
        $this->assertCount(3, $client->calls);
        $this->assertSame('rate_limited', $this->last($c)->metadata['language_reason']);
        $this->assertSame(0, AgentTask::count());
    }

    public function test_tenant_b_never_reuses_tenant_a_binding_or_history(): void
    {
        $this->binding();
        $client = $this->client();
        $c = $this->say($this->conversation(), 'Conta uma piada tenant A');
        $this->flushSession();
        $this->actingAs($this->other)->postJson('/conversations/'.$c->id.'/messages', ['body' => 'Oi', 'request_key' => (string) Str::uuid()])->assertNotFound();
        $this->context()->forUser($this->other);
        $s = new ConversationSetting;
        $s->forceFill(['tenant_id' => $this->b->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $this->other->id])->save();
        $other = app(Engine::class)->create(Identity::web($this->other), (string) Str::uuid());
        $other = app(Engine::class)->send(Identity::web($this->other), $other, (string) Str::uuid(), 'Conta uma piada tenant B');
        $this->assertSame('LOCAL', $this->last($other)->metadata['language_status']);
        $this->assertCount(1, $client->calls);
        $this->assertSame(0, DB::table('conversation_messages')->where('conversation_id', $c->id)->count());
    }

    public function test_current_channel_contract_still_refuses_unverified_future_channels(): void
    {
        $c = $this->conversation();
        $this->expectException(HttpException::class);
        app(Engine::class)->send(new Identity($this->a->id, $this->admin, 'whatsapp', 'unverified'), $c, (string) Str::uuid(), 'Pode executar');
    }

    public function test_natural_denials_and_farewells_remain_deterministic(): void
    {
        $this->binding();
        $client = $this->client();
        $c = $this->say($this->conversation(), 'Outro diagnóstico para google.com');
        $c = $this->say($c, 'Não quero esse diagnóstico');
        $this->assertArrayNotHasKey('pending', $c->fresh()->context_json);
        $c = $this->say($c, 'Cancela');
        $this->assertSame('CANCELLED', $c->state);
        $c = $this->say($c, 'Tchau');
        $this->assertSame('CLOSED', $c->state);
        $this->assertCount(0, $client->calls);
        $this->assertSame(0, AgentTask::count());
        $this->assertSame(Intent::Greeting, app(IntentDetector::class)->detect('Boa tarde, meu consagrado!', State::Active));
    }

    public function test_explicit_nonhuman_no_action_language_is_not_a_false_execution_claim(): void
    {
        $this->binding();
        $this->client(['output' => ['summary' => 'Não sou uma pessoa; sou o assistente virtual e posso conversar sem executar ações.', 'explanation' => 'Mensagem conversacional.', 'next_steps' => []]]);
        $c = $this->say($this->conversation(), 'E você, como é ajudar no plantão?');
        $this->assertSame('ASSISTED', $this->last($c)->metadata['language_status']);
        $this->assertSame(0, AgentTask::count());
    }

    public function test_presentation_call_knows_a_destination_exists_without_authorizing_it(): void
    {
        $this->binding();
        $client = $this->client();
        $c = $this->say($this->conversation(), 'Outro diagnóstico para google.com');
        $c = $this->say($c, 'Conta uma piada leve enquanto isso');
        $payload = json_decode($client->calls[0]['messages'][1]['content'], true);
        $this->assertTrue($payload['conversation']['known_destination']);
        $this->assertTrue($payload['conversation']['pending_confirmation']);
        $this->assertSame('WAITING_FOR_CONFIRMATION', $c->fresh()->state);
        $this->assertSame('google.com', $c->context_json['pending']['target']);
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_language_opt_in_remains_admin_only_and_requires_external_permission(): void
    {
        $this->actingAs($this->operator)->postJson('/advanced/llm/bindings', ['allow_conversation_language' => true])->assertForbidden();
        $this->flushSession();
        $this->context()->forUser($this->admin);
        $this->binding();
        $binding = LlmBinding::sole();
        $binding->config = ['allow_external_context' => false, 'allow_conversation_language' => true];
        $binding->save();
        $client = $this->client();
        $c = $this->say($this->conversation(), 'Conta uma piada leve');
        $this->assertSame('LOCAL', $this->last($c)->metadata['language_status']);
        $this->assertCount(0, $client->calls);
    }

    public function test_explicit_tone_preference_persists_and_never_overrides_incident_precision(): void
    {
        $this->binding();
        $client = $this->client();
        $c = $this->say($this->conversation(), 'Prefiro um tom formal.');
        for ($n = 0; $n < 5; $n++) {
            $c = $this->say($c, 'Olá');
        }
        $this->assertSame('FORMAL', $c->fresh()->context_json['tone_preference']);
        $this->assertSame('FORMAL', $c->context_json['tone']['style']);
        $c = $this->say($c, 'Pode falar mais descontraído?');
        $this->assertSame('RELAXED', $c->context_json['tone_preference']);
        $c = $this->say($c, 'Meu BNG está apresentando perda de pacotes.');
        $this->assertSame('TECHNICAL', $c->context_json['tone']['style']);
        $this->assertCount(0, $client->calls);
        $this->assertSame(0, Diagnostic::count());
    }
}
