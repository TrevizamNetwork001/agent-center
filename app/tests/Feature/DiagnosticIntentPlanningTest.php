<?php

namespace Tests\Feature;

use App\Agent\Protocol;
use App\Conversation\DiagnosticExperience;
use App\Conversation\Engine as ConversationEngine;
use App\Conversation\Identity;
use App\Conversation\Intent;
use App\Conversation\IntentDetector;
use App\Conversation\State;
use App\Diagnostics\Catalog;
use App\Diagnostics\Classifier;
use App\Diagnostics\Engine;
use App\Diagnostics\IntentParser;
use App\Diagnostics\IntentPlanning;
use App\Diagnostics\ObjectiveCoverage;
use App\Diagnostics\OperationalCapabilities;
use App\Diagnostics\OperationalIntent;
use App\Diagnostics\Planner;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationSetting;
use App\Models\Diagnostic;
use App\Models\DiagnosticConclusion;
use App\Models\DiagnosticPlan;
use App\Models\DiagnosticStep;
use App\Models\Profile;
use App\Models\Skill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\IdentityTestCase;
use Tests\Support\FakeOperationalCapabilities;
use Tests\Support\MtrFixture;
use Tests\Support\PathReadinessFixture;

class DiagnosticIntentPlanningTest extends IdentityTestCase
{
    use PathReadinessFixture;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context()->forUser($this->operator);
        app(Catalog::class)->ensure();
        (new ConversationSetting)->forceFill(['tenant_id' => $this->a->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $this->admin->id])->save();
        $this->agent = new Agent;
        $this->agent->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'name' => 'Intent LAB', 'hostname' => 'intent-lab', 'status' => 'active', 'version' => '0.6.2',
            'token_hash' => hash('sha256', random_bytes(32)), 'capabilities' => Protocol::CAPABILITIES, 'metadata' => ['task_protocol' => 1, 'mtr_capability_confirmed_at' => now()->toIso8601String()], 'enrolled_at' => now()])->save();
        $this->pathReady($this->agent);
    }

    private function chat(): Conversation
    {
        return app(ConversationEngine::class)->create(Identity::web($this->operator), (string) Str::uuid(), $this->agent->id);
    }

    private function say(Conversation $c, string $body, ?string $key = null): Conversation
    {
        return app(ConversationEngine::class)->send(Identity::web($this->operator), $c, $key ?? (string) Str::uuid(), $body);
    }

    private function last(Conversation $c): string
    {
        return ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first()->body;
    }

    public function test_seven_categories_map_to_relevant_profiles_and_skills(): void
    {
        foreach ([
            ['como tá a rota? 8.8.8.8', OperationalIntent::Route, 'MTR_ICMP', ['mtr_path']],
            ['veja o caminho até esse IP 8.8.8.8', OperationalIntent::Route, 'MTR_ICMP', ['mtr_path']],
            ['tem perda no trajeto até 8.8.8.8?', OperationalIntent::Route, 'MTR_ICMP', ['mtr_path']],
            ['Verifique a conectividade de 8.8.8.8', OperationalIntent::Connectivity, 'CONECTIVIDADE_BASICA', ['icmp_ping']],
            ['o ping está bom? 8.8.8.8', OperationalIntent::Latency, 'PERDA_LATENCIA', ['icmp_ping']],
            ['o site está abrindo? 8.8.8.8', OperationalIntent::Web, 'SITE_NAO_ABRE', ['tcp_connect', 'http_probe']],
            ['resolva o DNS de example.test', OperationalIntent::Dns, 'PROBLEMA_DNS', ['dns_lookup']],
            ['a porta 443 está acessível? 8.8.8.8', OperationalIntent::Port, 'PORTA_SERVICO', ['tcp_connect']],
            ['o YouTube está lento?', OperationalIntent::Slowness, 'GOOGLE_YOUTUBE', ['dns_lookup', 'tcp_connect', 'http_probe', 'icmp_ping', 'traceroute']],
        ] as [$body, $goal, $profileKey, $skills]) {
            $request = app(IntentParser::class)->parse($body);
            $this->assertSame($goal->value, $request['intent'], $body);
            $this->assertSame($profileKey, app(IntentPlanning::class)->profile($goal, $this->agent, $request));
            $profile = Profile::where('key', $profileKey)->sole();
            $target = $goal === OperationalIntent::Dns ? 'example.test' : ($goal === OperationalIntent::Slowness ? 'youtube.com' : '8.8.8.8');
            $plan = app(IntentPlanning::class)->preview($profile, $this->agent, $target, ['operational_intent' => $goal->value, 'port' => $request['port']]);
            $this->assertEqualsCanonicalizing($skills, array_values(array_unique(array_column($plan['steps'], 'skill'))));
            $this->assertSame($goal->value, $plan['objective']['intent']);
        }
    }

    public function test_exact_reported_case_requires_confirmation_and_only_plans_mtr(): void
    {
        $body = "Queria avaliar essa rota aqui 8.8.8.8,\nver se está boa.";
        $legacy = app(Planner::class)->plan(Profile::where('key', 'SITE_LENTO')->sole(), '8.8.8.8', Protocol::CAPABILITIES);
        $this->assertSame(['tcp', 'https', 'ping', 'trace'], array_column($legacy['steps'], 'key'));
        $this->assertSame(Intent::Path, app(IntentDetector::class)->detect($body, State::Active));
        $c = $this->say($this->chat(), $body);
        $this->assertSame('WAITING_FOR_CONFIRMATION', $c->state);
        $this->assertSame('MTR_ICMP', $c->context_json['pending']['profile']);
        $this->assertStringContainsString('caminho de rede até 8.8.8.8', $this->last($c));
        $this->assertStringNotContainsString('HTTPS', $this->last($c));
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, AgentTask::count());
        $key = (string) Str::uuid();
        $c = $this->say($c, 'Sim, confirmo', $key);
        $this->say($c, 'Sim, confirmo', $key);
        $this->assertSame(1, Diagnostic::count());
        app(Engine::class)->tick();
        $this->assertSame(['mtr_icmp'], DiagnosticStep::pluck('key')->all());
        $this->assertSame(['mtr_path_analysis:v1'], AgentTask::pluck('capability')->all());
        $this->assertSame(OperationalIntent::Route->value, DiagnosticPlan::sole()->document['objective']['intent']);
    }

    public function test_bare_ip_and_ambiguous_request_ask_before_confirmation(): void
    {
        foreach (['8.8.8.8', 'Verifica 8.8.8.8', '8.8.8.8 está lento', 'Avalie rota ou ping de 8.8.8.8'] as $body) {
            $c = $this->say($this->chat(), $body);
            $this->assertArrayNotHasKey('pending', $c->context_json);
            $this->assertStringContainsString('caminho de rede', $this->last($c));
            $c = $this->say($c, 'Sim, confirmo');
            $this->assertSame(0, Diagnostic::count());
            $c = $this->say($c, 'rota');
            $this->assertSame('WAITING_FOR_CONFIRMATION', $c->state);
            $this->assertSame('8.8.8.8', $c->context_json['pending']['target']);
        }
    }

    public function test_objective_survives_target_question_and_port_requires_exact_supported_port(): void
    {
        $c = $this->say($this->chat(), 'Como está a rota?');
        $this->assertSame('WAITING_FOR_TARGET', $c->state);
        $c = $this->say($c, '8.8.8.8');
        $this->assertSame('MTR_ICMP', $c->context_json['pending']['profile']);
        $port = $this->say($this->chat(), 'A porta está acessível em 8.8.8.8?');
        $this->assertArrayNotHasKey('pending', $port->context_json);
        $this->assertStringContainsString('Qual porta', $this->last($port));
        $port = $this->say($port, '22');
        $this->assertStringContainsString('somente a porta 443', $this->last($port));
        $this->assertArrayNotHasKey('pending', $port->context_json);
        $port = $this->say($port, '443');
        $this->assertSame('PORTA_SERVICO', $port->context_json['pending']['profile']);
        $this->assertSame(0, Diagnostic::count());
    }

    public function test_unavailable_policy_helper_and_version_block_without_substitution(): void
    {
        foreach (['denied', 'helper', 'stale', 'protocol', 'destination', 'version', 'skill'] as $case) {
            app()->instance(OperationalCapabilities::class, new FakeOperationalCapabilities);
            $this->pathReady($this->agent);
            $reader = app(OperationalCapabilities::class);
            $decision = ['state' => 'ALLOWED', 'helper_healthy' => true];
            if ($case === 'denied') {
                $decision['state'] = 'DENIED';
            }
            if ($case === 'helper') {
                $decision['helper_healthy'] = false;
            }
            if ($case === 'stale') {
                $this->agent->forceFill(['last_heartbeat_at' => now()->subMinutes(2)])->save();
            }
            if (in_array($case, ['protocol', 'destination'], true)) {
                $reader->decisions = [];
            }
            $reader->set($this->agent, 'mtr_path_analysis:v1', $case === 'destination' ? '1.1.1.1' : '8.8.8.8', $case === 'protocol' ? 'tcp' : 'icmp', $decision);
            $this->agent->forceFill(['version' => $case === 'version' ? '0.6.1' : '0.6.2'])->save();
            Skill::where('key', 'mtr_path')->update(['enabled' => $case !== 'skill']);
            $c = $this->say($this->chat(), 'MTR ICMP até 8.8.8.8');
            $this->assertArrayNotHasKey('pending', $c->context_json, $case);
            $this->assertStringContainsString('Nenhuma tarefa', $this->last($c), $case);
            $this->assertSame(0, Diagnostic::count());
            $this->assertSame(0, AgentTask::count());
        }
    }

    public function test_traceroute_alternative_and_tcp_are_explicit_and_not_combined(): void
    {
        $this->pathReady($this->agent, protocol: 'tcp');
        $c = $this->say($this->chat(), 'Analise a rota TCP até 8.8.8.8');
        $this->assertSame('MTR_TCP', $c->context_json['pending']['profile']);
        $this->assertStringNotContainsString('ICMP', $this->last($c));
        $this->agent->forceFill(['capabilities' => ['traceroute:v1']])->save();
        $this->pathReady($this->agent, capability: 'traceroute:v1');
        $c = $this->say($this->chat(), 'Analise a rota até 8.8.8.8');
        $this->assertArrayNotHasKey('pending', $c->context_json);
        $this->assertStringContainsString('MTR_ICMP', $this->last($c));
        $this->assertSame(0, Diagnostic::count());
        $c = $this->say($this->chat(), 'Faça traceroute até 8.8.8.8');
        $this->assertStringContainsString('traceroute ICMP solicitado', $this->last($c));
        $this->assertSame('CAMINHO_TRACEROUTE', $c->context_json['pending']['profile']);
        $this->assertSame(0, Diagnostic::count());
        $this->say($c, 'sim');
        app(Engine::class)->tick();
        $this->assertSame(['traceroute:v1'], AgentTask::pluck('capability')->all());
    }

    public function test_confirmation_revalidates_policy_and_cancellation_never_creates_work(): void
    {
        $c = $this->say($this->chat(), 'Avaliar a rota até 8.8.8.8');
        $this->pathReady($this->agent, state: 'DENIED');
        $c = $this->say($c, 'sim');
        $this->assertSame(0, Diagnostic::count());
        $this->assertStringContainsString('mudou', $this->last($c));
        $this->pathReady($this->agent);
        $c = $this->say($this->chat(), 'Avaliar a rota até 8.8.8.8');
        $c = $this->say($c, 'cancelar');
        $this->say($c, 'sim');
        $this->assertSame(0, Diagnostic::count());
        $this->assertSame(0, AgentTask::count());
    }

    public function test_claim_rechecks_helper_and_runtime_error_preserves_technical_classification(): void
    {
        $c = $this->say($this->chat(), 'Avaliar a rota até 8.8.8.8');
        $c = $this->say($c, 'sim');
        $engine = app(Engine::class);
        $engine->tick();
        $task = $engine->claim($this->agent);
        $this->assertSame('mtr_path_analysis:v1', $task['capability']);
        $envelope = array_intersect_key($task, array_flip(['task_id', 'execution_id', 'generation', 'lease_token']));
        $engine->operation($this->agent, 'start', $envelope);
        $engine->operation($this->agent, 'result', $envelope + ['result' => ['ok' => false, 'error' => 'mtr_execution_failed']]);
        $d = Diagnostic::sole();
        $this->assertSame('ERRO_DE_EXECUCAO', DiagnosticConclusion::sole()->classification);
        $s = app(DiagnosticExperience::class)->snapshot($d, $this->operator);
        $this->assertSame('NAO_EXECUTADA', $s['coverage']['status']);
        $this->assertSame('INCONCLUSIVO', $s['coverage']['request_classification']);
        $this->assertSame('ERRO_DE_EXECUCAO', $s['classification']);
        $c = $this->say($c, 'Mais detalhes');
        $this->assertStringContainsString('Análise de rota: NÃO EXECUTADA', $this->last($c));
        $this->assertStringContainsString('Conclusão da solicitação: INCONCLUSIVO', $this->last($c));
        $this->actingAs($this->operator)->get('/diagnostics/'.$d->id)->assertOk()->assertSee('Classificação técnica dos testes')->assertSee('NÃO EXECUTADA')->assertSee('Conclusão da solicitação');
    }

    public function test_normal_connectivity_never_proves_route_coverage(): void
    {
        $evidence = ['tcp' => ['ok' => true, 'data' => ['connected' => true]], 'https' => ['ok' => true, 'data' => ['status' => 200, 'tls' => true, 'duration_ms' => 1]]];
        $official = app(Classifier::class)->classify('SITE_LENTO', $evidence, ['required_steps' => ['tcp', 'https']]);
        $this->assertSame('NORMAL', $official['classification']);
        $coverage = app(ObjectiveCoverage::class)->assess(OperationalIntent::Route, $evidence, [], $official['classification']);
        $this->assertSame('NAO_EXECUTADA', $coverage['status']);
        $this->assertSame('INCONCLUSIVO', $coverage['request_classification']);
        $this->assertSame('NORMAL', $official['classification']);
    }

    public function test_path_fixture_results_distinguish_measurement_coverage_and_request_conclusion(): void
    {
        foreach (['healthy' => 'NORMAL', 'isolated-loss' => 'NORMAL', 'missing-hop' => 'NORMAL', 'end-loss' => 'INDICIO_DE_FALHA', 'destination-filtered' => 'INCONCLUSIVO', 'insufficient' => 'INCONCLUSIVO'] as $fixture => $expected) {
            $evidence = ['mtr_icmp' => ['ok' => true, 'data' => MtrFixture::data($fixture)]];
            $plan = ['objective' => ['required_steps' => ['mtr_icmp']]];
            $official = app(Classifier::class)->classify('MTR_ICMP', $evidence, ['required_steps' => ['mtr_icmp']]);
            $coverage = app(ObjectiveCoverage::class)->assess(OperationalIntent::Route, $evidence, $plan, $official['classification']);
            $this->assertSame($expected, $official['classification']);
            $this->assertSame('EXECUTADA', $coverage['status']);
            $this->assertSame($expected, $coverage['request_classification']);
        }
        $evidence = ['mtr_icmp' => ['ok' => true, 'data' => MtrFixture::data('insufficient')]];
        $thresholds = ['required_steps' => ['mtr_icmp'], 'mtr_min_cycles' => 1];
        $official = app(Classifier::class)->classify('MTR_ICMP', $evidence, $thresholds);
        $coverage = app(ObjectiveCoverage::class)->assess(OperationalIntent::Route, $evidence, ['thresholds' => $thresholds], $official['classification']);
        $this->assertSame('NORMAL', $official['classification']);
        $this->assertSame('NORMAL', $coverage['request_classification']);
    }

    public function test_policy_changes_after_planning_prevent_claim_without_substitution(): void
    {
        $c = $this->say($this->chat(), 'Avaliar a rota até 8.8.8.8');
        $this->say($c, 'sim');
        $engine = app(Engine::class);
        $engine->tick();
        $this->pathReady($this->agent, state: 'DENIED');
        $this->assertNull($engine->claim($this->agent));
        $engine->tick();
        $this->assertSame('BLOCKED', DiagnosticStep::sole()->status);
        $this->assertSame('policy_denied', DiagnosticStep::sole()->error_code);
        $this->assertSame('INCONCLUSIVO', DiagnosticConclusion::sole()->classification);
        $this->assertSame(1, AgentTask::count());
    }

    public function test_explicit_methods_and_ports_are_not_silently_replaced(): void
    {
        $this->pathReady($this->agent, capability: 'traceroute:v1');
        $c = $this->say($this->chat(), 'Faça traceroute até 8.8.8.8');
        $this->assertSame('CAMINHO_TRACEROUTE', $c->context_json['pending']['profile']);
        foreach (['Faça traceroute TCP até 8.8.8.8', 'Faça MTR TCP porta 80 até 8.8.8.8'] as $body) {
            $c = $this->say($this->chat(), $body);
            $this->assertArrayNotHasKey('pending', $c->context_json);
            $this->assertStringContainsString('substitu', mb_strtolower($this->last($c)));
        }
        $this->assertSame(0, Diagnostic::count());
        $this->assertNotSame(Intent::Path, app(IntentDetector::class)->detect('O que é MTR?', State::Active));
        $coverage = app(ObjectiveCoverage::class)->assess(OperationalIntent::Route,
            ['trace' => ['ok' => true, 'data' => ['hops' => ['8.8.8.8'], 'reached_destination' => true]]],
            ['objective' => ['required_steps' => ['trace'], 'path_metrics' => true]], 'NORMAL');
        $this->assertSame('PARCIAL', $coverage['status']);
        $this->assertSame('INCONCLUSIVO', $coverage['request_classification']);
    }

    public function test_forced_rls_blocks_cross_tenant_plans_and_capability_selection(): void
    {
        $c = $this->say($this->chat(), 'Avaliar a rota até 8.8.8.8');
        $this->say($c, 'sim');
        app(Engine::class)->tick();
        foreach (['diagnostics', 'diagnostic_plans', 'diagnostic_steps', 'agent_tasks', 'profiles', 'skills'] as $table) {
            $row = DB::selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE oid = ?::regclass', [$table]);
            $this->assertTrue($row->relrowsecurity && $row->relforcerowsecurity);
        }
        $this->context()->forUser($this->other);
        $this->assertSame(0, DB::table('diagnostic_plans')->count());
        $this->assertSame(0, Agent::count());
        $this->assertSame(0, Skill::count());
        $this->assertSame(0, Profile::count());
        $this->assertSame(0, AgentTask::count());
    }
}
