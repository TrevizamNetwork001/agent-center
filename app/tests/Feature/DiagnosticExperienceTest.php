<?php

namespace Tests\Feature;

use App\Conversation\DiagnosticExperience;
use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Conversation\State;
use App\Diagnostics\Catalog;
use App\Diagnostics\Classifier;
use App\Knowledge\Versioning;
use App\Llm\Client;
use App\Llm\Configuration;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\ConversationMessage;
use App\Models\ConversationSetting;
use App\Models\Diagnostic;
use App\Models\DiagnosticConclusion;
use App\Models\Profile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\IntelligenceTestCase;

class DiagnosticExperienceTest extends IntelligenceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new ConversationSetting)->forceFill(['tenant_id' => $this->a->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $this->admin->id])->save();
    }

    private function evidence(string $class = 'NORMAL'): array
    {
        return match ($class) {
            'NORMAL' => ['dns_a' => ['ok' => true, 'data' => ['answers' => ['192.0.2.20'], 'record_type' => 'A', 'duration_ms' => 3]],
                'tcp' => ['ok' => true, 'data' => ['connected' => true, 'duration_ms' => 7]],
                'https' => ['ok' => true, 'data' => ['status' => 200, 'tls' => true, 'duration_ms' => 42, 'resolved_ip' => '192.0.2.20']]],
            'INDICIO_DE_FALHA' => ['tcp' => ['ok' => false, 'error' => 'connection_refused']],
            'ERRO_DE_EXECUCAO' => ['tcp' => ['ok' => false, 'error' => 'internal_error']],
            default => [],
        };
    }

    private function resultFixture(string $class = 'NORMAL', ?array $evidence = null): array
    {
        app(Catalog::class)->ensure();
        $agent = (new Agent)->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'name' => 'Agent LAB', 'hostname' => 'synthetic', 'status' => 'active', 'version' => '0.6.2', 'token_hash' => hash('sha256', random_bytes(32)), 'capabilities' => [], 'metadata' => [], 'enrolled_at' => now()]);
        $agent->save();
        $d = (new Diagnostic)->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'agent_id' => $agent->id, 'profile_id' => Profile::where('key', 'GOOGLE_YOUTUBE')->sole()->id, 'created_by' => $this->admin->id, 'target' => 'youtube.com', 'status' => $class === 'ERRO_DE_EXECUCAO' ? 'FAILED' : 'COMPLETED', 'started_at' => now(), 'finished_at' => now()]);
        $d->save();
        $out = app(Classifier::class)->classify('GOOGLE_YOUTUBE', $evidence ?? $this->evidence($class), ['required_steps' => $class === 'NORMAL' ? ['dns_a', 'tcp', 'https'] : ['tcp', 'https']]);
        $this->assertSame($class, $out['classification']);
        $official = (new DiagnosticConclusion)->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $d->tenant_id, 'diagnostic_id' => $d->id, ...$out, 'created_at' => now()]);
        $official->save();
        $identity = Identity::web($this->admin);
        $c = app(Engine::class)->create($identity, (string) Str::uuid());
        $c = app(Engine::class)->send($identity, $c, (string) Str::uuid(), 'boa noite meu rei');
        $c->forceFill(['current_diagnostic_id' => $d->id, 'current_target' => $d->target, 'context_json' => [...$c->context_json, 'diagnostic_ids' => [$d->id]]]);
        app(Engine::class)->transition($c, State::Running, 'fixture_accepted');
        $c->save();

        return [$c, $d, $official, $identity];
    }

    private function authorizeModel(): void
    {
        $prompt = app(Versioning::class)->publish($this->prompt(), $this->admin);
        $provider = $this->provider();
        $model = $this->model($provider);
        app(Configuration::class)->binding(['purpose' => 'diagnostic_explanation', 'model_id' => $model->id, 'prompt_id' => $prompt->id, 'enabled' => true, 'allow_external_context' => true, 'allow_conversation_language' => true], $this->admin);
    }

    public function test_all_four_classifications_are_immutable_and_presented_from_their_real_evidence(): void
    {
        foreach (['NORMAL', 'INDICIO_DE_FALHA', 'INCONCLUSIVO', 'ERRO_DE_EXECUCAO'] as $class) {
            [$c, $d, $official, $identity] = $this->resultFixture($class);
            $before = $official->fresh()->getRawOriginal();
            $c = app(Engine::class)->sync($identity, $c);
            $result = ConversationMessage::where('conversation_id', $c->id)->where('message_type', 'result')->sole();
            $this->assertSame($class, $result->metadata['classification']);
            $this->assertStringContainsString('youtube.com', $result->body);
            $this->assertStringContainsString($official->summary, $result->body);
            $this->assertStringContainsString('Limites:', $result->body);
            $this->assertStringContainsString('Agent', $result->body);
            $this->assertSame($before, $official->fresh()->getRawOriginal());
            $this->assertSame(0, AgentTask::count());
            if ($class === 'NORMAL') {
                $this->assertSame('RELAXED', $result->metadata['tone']);
                $this->assertStringContainsString('Prontinho, meu rei!', $result->body);
                $this->assertStringContainsString(Classifier::NORMAL, $result->body);
                $this->assertStringContainsString('não comprova a saúde de toda a rede', $result->body);
                $this->assertStringContainsString('HTTP 200', $result->body);
                $this->assertStringContainsString('42 ms', $result->body);
                $this->assertStringNotContainsString('MTR', $result->body);
                $this->assertStringNotContainsString('latência média', $result->body);
            } else {
                $this->assertSame('TECHNICAL', $result->metadata['tone']);
                $this->assertStringNotContainsString('Prontinho', $result->body);
            }
        }
    }

    public function test_no_evidence_and_policy_block_are_not_reported_as_executed_tests_or_network_failure(): void
    {
        foreach ([[], ['tcp' => ['ok' => false, 'error' => 'policy_denied']]] as $evidence) {
            [$c, $d, , $identity] = $this->resultFixture('INCONCLUSIVO', $evidence);
            app(Engine::class)->sync($identity, $c);
            $body = ConversationMessage::where('conversation_id', $c->id)->where('message_type', 'result')->sole()->body;
            $this->assertStringContainsString('Sem evidências de verificações executadas', $body);
            $this->assertStringNotContainsString('latência', $body);
            $this->assertStringNotContainsString('TLS validado', $body);
            if ($evidence) {
                $this->assertStringContainsString('bloqueada por política; não executada', $body);
            }
        }
    }

    public function test_authorized_llm_selects_only_grounded_phrases_and_does_not_receive_raw_addresses(): void
    {
        $this->authorizeModel();
        $client = new class extends Client
        {
            public array $inputs = [];

            public function call($p, $m, array $messages, ?callable $cancelled = null, string $purpose = 'diagnostic'): array
            {
                $data = json_decode($messages[1]['content'], true);
                $this->inputs[] = $data;

                return ['output' => ['summary' => array_key_first($data['intros']), 'explanation' => 'verificacoes', 'next_steps' => array_map(fn ($i) => $i.':plain', array_keys($data['facts']))]];
            }
        };
        app()->instance(Client::class, $client);
        [$c, $d, $official, $identity] = $this->resultFixture();
        app(Engine::class)->sync($identity, $c);
        $message = ConversationMessage::where('conversation_id', $c->id)->where('message_type', 'result')->sole();
        $this->assertSame('ASSISTED', $message->metadata['result_status']);
        $this->assertStringContainsString('Na verificação Resposta HTTPS', $message->body);
        $this->assertStringContainsString('42 ms', $message->body);
        $this->assertStringNotContainsString('192.0.2.20', json_encode($client->inputs));
        app(Engine::class)->sync($identity, $c);
        app(Engine::class)->sync($identity, $c);
        $this->assertCount(1, $client->inputs);
        $this->assertSame(1, ConversationMessage::where('conversation_id', $c->id)->where('message_type', 'result')->count());
    }

    public function test_llm_timeout_and_fabricated_or_malformed_outputs_use_the_deterministic_result(): void
    {
        $this->authorizeModel();
        foreach ([['error' => 'timeout'], ['output' => ['summary' => 'A rede toda está perfeita com 0% de perda', 'explanation' => 'evidencias', 'next_steps' => []]], ['output' => ['summary' => [], 'explanation' => [], 'next_steps' => 'bad']], ['output' => ['summary' => 'pronto', 'explanation' => 'evidencias', 'next_steps' => ['0:short', '0:short', '2:short']]]] as $response) {
            foreach (['conversation:language:user:'.$this->a->id.':'.$this->admin->id, 'conversation:language:tenant:'.$this->a->id] as $key) {
                RateLimiter::clear($key);
            }
            app()->instance(Client::class, new class($response) extends Client
            {
                public function __construct(private array $response) {}

                public function call($p, $m, array $messages, ?callable $cancelled = null, string $purpose = 'diagnostic'): array
                {
                    return $this->response;
                }
            });
            [$c, , , $identity] = $this->resultFixture();
            app(Engine::class)->sync($identity, $c);
            $result = ConversationMessage::where('conversation_id', $c->id)->where('message_type', 'result')->sole();
            $this->assertSame('FALLBACK', $result->metadata['result_status']);
            $this->assertStringContainsString(Classifier::NORMAL, $result->body);
            $this->assertStringContainsString('HTTP 200', $result->body);
            $this->assertStringNotContainsString('rede toda está perfeita', $result->body);
            $this->assertStringNotContainsString('0% de perda', $result->body);
        }
    }

    public function test_result_followup_and_evidence_questions_do_not_create_tasks_or_change_the_target(): void
    {
        [$c, $d, , $identity] = $this->resultFixture();
        $c = app(Engine::class)->sync($identity, $c);
        foreach (['Mas continua lento para os clientes.', 'Quais testes você fez?', 'Explica de um jeito mais simples', 'Sim, confirmo'] as $question) {
            $c = app(Engine::class)->send($identity, $c, (string) Str::uuid(), $question);
            $this->assertSame($d->id, $c->current_diagnostic_id);
            $this->assertArrayNotHasKey('pending', $c->context_json);
            $this->assertSame(0, AgentTask::count());
        }
        $this->assertSame(1, Diagnostic::count());
        $this->assertSame('youtube.com', $c->current_target);
    }

    public function test_missing_metrics_are_unavailable_instead_of_zero_or_invented_latency(): void
    {
        [$c, , , $identity] = $this->resultFixture('INCONCLUSIVO', ['tcp' => ['ok' => true, 'data' => []]]);
        app(Engine::class)->sync($identity, $c);
        $result = ConversationMessage::where('conversation_id', $c->id)->where('message_type', 'result')->sole();
        $this->assertStringContainsString('estado da conexão: indisponível', $result->body);
        $this->assertStringContainsString('tempo da sondagem: indisponível', $result->body);
        $this->assertStringNotContainsString('0 ms', $result->body);
        $this->assertStringNotContainsString('latência média', $result->body);
    }

    public function test_web_executive_summary_metrics_timeline_and_authenticated_tenant_isolation(): void
    {
        [$c, $d] = $this->resultFixture();
        $this->actingAs($this->admin)->get('/diagnostics/'.$d->id)->assertOk()->assertSee('RESUMO EXECUTIVO', false)->assertSee('HTTP 200')->assertSee('42 ms')->assertSee('Linha do tempo')->assertSee('youtube.com');
        $this->flushSession();
        $this->actingAs($this->other)->get('/diagnostics/'.$d->id)->assertNotFound();
        $this->context()->forUser($this->other);
        try {
            app(DiagnosticExperience::class)->snapshot($d, $this->other);
            $this->fail('Cross-tenant snapshot allowed');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
