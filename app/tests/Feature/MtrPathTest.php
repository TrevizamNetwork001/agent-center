<?php

namespace Tests\Feature;

use App\Conversation\Intent;
use App\Conversation\IntentDetector;
use App\Conversation\State;
use App\Diagnostics\Catalog;
use App\Diagnostics\Classifier;
use App\Diagnostics\Engine;
use App\Diagnostics\MtrPath;
use App\Diagnostics\Normalizer;
use App\Diagnostics\Planner;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\DiagnosticConclusion;
use App\Models\DiagnosticStep;
use App\Models\Profile;
use App\Models\Skill;
use App\Web\OperationalSnapshot;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\IdentityTestCase;
use Tests\Support\PathReadinessFixture;

class MtrPathTest extends IdentityTestCase
{
    use PathReadinessFixture;

    private function data(string $name, string $protocol = 'icmp'): array
    {
        $raw = json_decode(file_get_contents(base_path('tests/Fixtures/mtr/'.$name.'.json')), true);
        $target = $name === 'ipv6' ? '2001:4860:4860::8888' : '8.8.8.8';
        $raw['report']['mtr'] = ['protocol' => $protocol];
        $hops = [];
        foreach ($raw['report']['hubs'] as $h) {
            $responded = $h['host'] !== '???' && $h['Loss%'] < 100;
            $hops[] = ['hop_index' => $h['count'], 'address' => $h['host'] === '???' ? null : $h['host'], 'hostname' => null, 'sent' => $h['Snt'], 'received' => null, 'loss_percent' => $h['Loss%'], 'avg_ms' => $responded ? $h['Avg'] : null, 'best_ms' => $responded ? $h['Best'] : null, 'worst_ms' => $responded ? $h['Wrst'] : null, 'stdev_ms' => $responded ? $h['StDev'] : null, 'responded' => $responded, 'asn' => null];
        }
        $last = end($hops);

        return ['destination' => $target, 'resolved_ip' => $target, 'protocol' => $protocol, 'cycles' => 5, 'total_duration_ms' => 6000, 'destination_reached' => $last['address'] === $target && $last['responded'], 'hops' => $hops, 'status' => 'collected', 'error' => null, 'raw_sanitized' => $raw];
    }

    public function test_interpretation_fixtures_and_official_classifier(): void
    {
        foreach (['healthy' => 'NORMAL', 'isolated-loss' => 'NORMAL', 'end-loss' => 'INDICIO_DE_FALHA', 'isolated-latency' => 'NORMAL', 'sustained-latency' => 'INDICIO_DE_FALHA', 'missing-hop' => 'NORMAL', 'destination-filtered' => 'INCONCLUSIVO', 'insufficient' => 'INCONCLUSIVO', 'ipv6' => 'NORMAL', 'ecmp' => 'NORMAL'] as $fixture => $expected) {
            $data = $this->data($fixture);
            $analysis = app(MtrPath::class)->analyze($data);
            $this->assertSame($expected, $analysis['classification'], $fixture);
            $result = app(Classifier::class)->classify('MTR_ICMP', ['mtr_icmp' => ['ok' => true, 'data' => $data]], ['required_steps' => ['mtr_icmp'], 'mtr_requested' => true]);
            $this->assertSame($expected, $result['classification'], $fixture);
            $this->assertStringContainsString('não atribui', implode(' ', $analysis['notes']));
        }
    }

    public function test_protocol_comparison_never_claims_same_path(): void
    {
        $result = app(Classifier::class)->classify('MTR_TCP', ['mtr_icmp' => ['ok' => true, 'data' => $this->data('destination-filtered')], 'mtr_tcp' => ['ok' => true, 'data' => $this->data('healthy', 'tcp')]], ['required_steps' => ['mtr_tcp']]);
        $this->assertStringContainsString('Não prova', implode(' ', $result['next_steps']));
    }

    public function test_operational_error_is_not_network_failure(): void
    {
        $r = app(Classifier::class)->classify('MTR_TCP', ['mtr_tcp' => ['ok' => false, 'error' => 'mtr_execution_failed']], ['required_steps' => ['mtr_tcp']]);
        $this->assertSame('ERRO_DE_EXECUCAO', $r['classification']);
    }

    public function test_catalog_planner_opt_in_and_tenant_isolation(): void
    {
        $this->context()->forUser($this->admin);
        app(Catalog::class)->ensure();
        $p = Profile::where('key', 'SITE_NAO_ABRE')->sole();
        $plan = app(Planner::class)->plan($p, 'google.com', []);
        $this->assertCount(0, array_filter($plan['steps'], fn ($s) => $s['capability'] === 'mtr_path_analysis:v1'));
        $plan = app(Planner::class)->plan($p, 'google.com', ['mtr_path_analysis:v1'], ['mtr_requested' => true]);
        $paths = array_values(array_filter($plan['steps'], fn ($s) => $s['capability'] === 'mtr_path_analysis:v1'));
        $this->assertCount(2, $paths);
        $this->assertTrue($paths[0]['available']);
        $this->assertSame(1, $plan['thresholds']['mtr_threshold_version']);
        $this->context()->forUser($this->other);
        $this->assertSame(0, Skill::where('key', 'mtr_path')->count());
    }

    public function test_conversation_phrases_are_deterministic(): void
    {
        foreach (['Faz um teste de rota também.', 'Analisa a perda de pacotes até esse site.', 'Faz um MTR mais completo.', 'Em qual parte do caminho está aumentando a latência?'] as $phrase) {
            $this->assertSame(Intent::Path, app(IntentDetector::class)->detect($phrase, State::Completed));
        }
    }

    public function test_contract_rejects_forged_hops_asn_and_unknown_raw_metadata(): void
    {
        foreach (['asn', 'avg_ms', 'responded'] as $key) {
            $data = $this->data('healthy');
            $data['hops'][0][$key] = 'forged';
            try {
                app(Normalizer::class)->normalize('mtr_path_analysis:v1', ['ok' => true, 'data' => $data]);
                $this->fail('forged accepted');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_live_contract_runs_through_fencing_and_duplicate_result_acceptance(): void
    {
        $this->context()->forUser($this->admin);
        app(Catalog::class)->ensure();
        $agent = new Agent;
        $agent->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'name' => 'MTR lab', 'hostname' => 'mtr-lab', 'status' => 'active', 'version' => '0.6.2', 'token_hash' => hash('sha256', random_bytes(32)), 'capabilities' => ['mtr_path_analysis:v1'], 'metadata' => ['task_protocol' => 1, 'mtr_capability_confirmed_at' => now()->toIso8601String()], 'enrolled_at' => now()])->save();
        $engine = app(Engine::class);
        $this->pathReady($agent, protocol: 'tcp');
        $diagnostic = $engine->create(Profile::where('key', 'MTR_TCP')->sole(), $agent, '8.8.8.8', $this->admin);
        $engine->tick();
        $task = $engine->claim($agent);
        $this->assertSame('mtr_path_analysis:v1', $task['capability']);
        $envelope = array_intersect_key($task, array_flip(['task_id', 'execution_id', 'generation', 'lease_token']));
        $engine->operation($agent, 'start', $envelope);
        $bad = $envelope;
        $bad['generation']++;
        try {
            $engine->operation($agent, 'result', $bad + ['result' => ['ok' => true, 'data' => $this->data('healthy', 'tcp')]]);
            $this->fail('stale accepted');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $wrong = $this->data('healthy', 'icmp');
        try {
            $engine->operation($agent, 'result', $envelope + ['result' => ['ok' => true, 'data' => $wrong]]);
            $this->fail('protocol mismatch accepted');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $payload = $envelope + ['result' => ['ok' => true, 'data' => $this->data('healthy', 'tcp')]];
        $this->assertSame('ok', $engine->operation($agent, 'result', $payload)['status']);
        $this->assertTrue($engine->operation($agent, 'result', $payload)['duplicate']);
        $this->assertSame('COMPLETED', $diagnostic->fresh()->status);
        $this->assertSame('NORMAL', DiagnosticConclusion::where('diagnostic_id', $diagnostic->id)->sole()->classification);
    }

    public function test_agent061_or_unconfirmed_capability_never_enqueues_mtr(): void
    {
        $this->context()->forUser($this->admin);
        app(Catalog::class)->ensure();
        foreach ([['0.6.1', true], ['0.6.2', false]] as [$version,$confirmed]) {
            $a = new Agent;
            $a->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'name' => 'Pending MTR', 'hostname' => 'pending-mtr', 'status' => 'active', 'version' => $version, 'token_hash' => hash('sha256', random_bytes(32)), 'capabilities' => ['mtr_path_analysis:v1'], 'metadata' => ['task_protocol' => 1, 'mtr_capability_confirmed_at' => $confirmed ? now()->toIso8601String() : null], 'enrolled_at' => now()])->save();
            $d = app(Engine::class)->create(Profile::where('key', 'MTR_TCP')->sole(), $a, '8.8.8.8', $this->admin);
            app(Engine::class)->tick();
            $this->assertSame(0, AgentTask::where('diagnostic_id', $d->id)->count());
            $this->assertSame('BLOCKED', DiagnosticStep::where('diagnostic_id', $d->id)->sole()->status);
            $this->assertSame('INCONCLUSIVO', DiagnosticConclusion::where('diagnostic_id', $d->id)->sole()->classification);
        }
    }

    public function test_operations_counts_both_supported_agent_versions_online(): void
    {
        $this->context()->forUser($this->admin);
        foreach (['0.6.1', '0.6.2'] as $version) {
            $a = new Agent;
            $a->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->a->id, 'name' => 'Online version', 'hostname' => 'online-version', 'status' => 'active', 'version' => $version, 'token_hash' => hash('sha256', random_bytes(32)), 'capabilities' => ['agent_info'], 'metadata' => ['task_protocol' => 1, 'telemetry' => ['device_operations_frozen' => true]], 'enrolled_at' => now(), 'last_heartbeat_at' => now(), 'last_poll_at' => now()])->save();
        }
        $snapshot = app(OperationalSnapshot::class)->get();
        $this->assertSame(2, $snapshot['agents_online']);
        $this->assertSame(2, $snapshot['agents_total']);
    }
}
