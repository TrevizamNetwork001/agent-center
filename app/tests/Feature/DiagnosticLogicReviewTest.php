<?php

namespace Tests\Feature;

use App\Agent\Protocol;
use App\Diagnostics\Classifier;
use App\Diagnostics\Normalizer;
use App\Diagnostics\Planner;
use App\Models\DiagnosticStep;
use App\Models\Profile;
use App\Web\Presentation;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DiagnosticLogicReviewTest extends TestCase
{
    private function web(): array
    {
        return ['dns_a' => ['ok' => true, 'data' => ['record_type' => 'A', 'answers' => ['8.8.8.8'], 'duration_ms' => 1]], 'dns_aaaa' => ['ok' => true, 'data' => ['record_type' => 'AAAA', 'answers' => [], 'error' => 'nodata', 'duration_ms' => 1]], 'tcp' => ['ok' => true, 'data' => ['connected' => true, 'duration_ms' => 1]], 'https' => ['ok' => true, 'data' => ['status' => 200, 'tls' => true, 'duration_ms' => 1, 'resolved_ip' => '8.8.8.8']]];
    }

    public function test_all_profiles_distinguish_policy_from_network_and_optional_failures(): void
    {
        foreach (['SITE_LENTO', 'SITE_NAO_ABRE', 'GOOGLE_YOUTUBE', 'CONTEUDO_LENTO'] as $profile) {
            $e = $this->web();
            $e['tcp'] = ['ok' => false, 'error' => 'policy_denied'];
            unset($e['https']);
            $this->assertSame('INCONCLUSIVO', app(Classifier::class)->classify($profile, $e)['classification']);
            $e['tcp'] = ['ok' => false, 'error' => 'connection_refused'];
            $this->assertSame('INDICIO_DE_FALHA', app(Classifier::class)->classify($profile, $e)['classification']);
            $e = $this->web();
            $e['ping'] = ['ok' => false, 'error' => 'action_failed'];
            $this->assertSame('NORMAL', app(Classifier::class)->classify($profile, $e)['classification']);
        }
    }

    public function test_required_missing_dns_and_missing_latency_measurement_cannot_be_normal(): void
    {
        $e = $this->web();
        unset($e['dns_a'],$e['dns_aaaa']);
        $this->assertSame('NORMAL', app(Classifier::class)->classify('SITE_NAO_ABRE', $e)['classification']);
        $this->assertSame('INCONCLUSIVO', app(Classifier::class)->classify('SITE_NAO_ABRE', $e, ['required_steps' => ['dns_a', 'dns_aaaa', 'tcp', 'https']])['classification']);
        $ping = ['ping' => ['ok' => true, 'data' => ['sent' => 5, 'received' => 5, 'loss_percent' => 0, 'duration_ms' => 1]]];
        $this->assertSame('INCONCLUSIVO', app(Classifier::class)->classify('PERDA_LATENCIA', $ping)['classification']);
        $ping['ping']['data']['avg_ms'] = 200;
        $this->assertSame('INDICIO_DE_FALHA', app(Classifier::class)->classify('PERDA_LATENCIA', $ping)['classification']);
    }

    public function test_http_head_unsupported_and_redirect_have_honest_scope(): void
    {
        foreach ([405, 501] as $status) {
            $e = $this->web();
            $e['https']['data']['status'] = $status;
            $r = app(Classifier::class)->classify('SITE_NAO_ABRE', $e);
            $this->assertSame('INCONCLUSIVO', $r['classification']);
            $this->assertStringContainsString('HEAD', $r['summary']);
        }
        $e = $this->web();
        $e['https']['data']['status'] = 302;
        $r = app(Classifier::class)->classify('SITE_NAO_ABRE', $e);
        $this->assertSame('NORMAL', $r['classification']);
        $this->assertStringContainsString('redirecionamento', implode(' ', $r['next_steps']));
        $r = app(Classifier::class)->classify('SITE_NAO_ABRE', $this->web(), [], false, 'diagnostic_timeout');
        $this->assertSame('INCONCLUSIVO', $r['classification']);
        $this->assertStringContainsString('prazo', $r['summary']);
    }

    public function test_trace_requires_a_measured_anomaly_or_explicit_request(): void
    {
        $planner = app(Planner::class);
        foreach (['policy_denied', 'action_failed', 'lease_lost'] as $error) {
            $this->assertFalse($planner->condition('trace_needed', ['tcp' => ['ok' => false, 'error' => $error]], []));
        }
        $this->assertTrue($planner->condition('trace_needed', ['tcp' => ['ok' => false, 'error' => 'connection_refused']], []));
        $this->assertTrue($planner->condition('trace_needed', ['ping' => ['ok' => true, 'data' => ['loss_percent' => 0, 'avg_ms' => 200]]], []));
        $this->assertFalse($planner->condition('trace_needed', ['tcp' => ['ok' => false, 'error' => 'connection_refused']], ['trace_on_failure' => false]));
        $this->assertTrue($planner->condition('trace_needed', [], ['trace_requested' => true, 'trace_on_failure' => false]));
    }

    public function test_ipv6_target_and_dns_profile_validation(): void
    {
        $target = app(Planner::class)->target('2001:db8::1');
        $this->assertSame('2001:db8::1', $target['host']);
        $this->assertSame('https://[2001:db8::1]/', $target['url']);
        $this->assertTrue($target['literal']);
        $profile = new Profile;
        $profile->forceFill(['key' => 'PROBLEMA_DNS']);
        $this->expectException(ValidationException::class);
        app(Planner::class)->validateTarget($profile, '8.8.8.8');
    }

    public function test_contradictory_evidence_is_rejected_and_agent_info_supports_current_versions(): void
    {
        $invalid = [['dns_lookup:v1', ['ok' => true, 'data' => ['record_type' => 'A', 'answers' => ['8.8.8.8'], 'duration_ms' => 1, 'error' => 'dns_failed']]], ['tcp_connect:v1', ['ok' => true, 'data' => ['connected' => true, 'duration_ms' => 1, 'error' => 'timeout']]], ['icmp_ping:v1', ['ok' => true, 'data' => ['sent' => 0, 'received' => 0, 'loss_percent' => 0, 'duration_ms' => 1]]], ['traceroute:v1', ['ok' => true, 'data' => ['hops' => [], 'reached_destination' => true, 'duration_ms' => 1]]], ['tcp_connect:v1', ['ok' => false, 'error' => 'timeout', 'data' => ['connected' => true]]]];
        foreach ($invalid as [$cap,$result]) {
            try {
                app(Normalizer::class)->normalize($cap, $result);
                $this->fail('contradictory evidence accepted');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
        foreach (Protocol::VERSIONS as $version) {
            $this->assertTrue(app(Normalizer::class)->normalize('agent_info', ['ok' => true, 'data' => ['version' => $version, 'hostname' => 'fixture', 'python' => '3.13', 'device_operations_frozen' => true]])['ok']);
        }
    }

    public function test_step_badges_reflect_measured_failure_and_limited_http_without_changing_execution_history(): void
    {
        foreach ([
            ['tcp_connect:v1', ['connected' => false], 'TECHNICAL_FAILURE'],
            ['http_probe:v1', ['status' => 500, 'tls' => true], 'TECHNICAL_FAILURE'],
            ['http_probe:v1', ['status' => 405, 'tls' => true], 'LIMITED'],
            ['icmp_ping:v1', ['received' => 0, 'loss_percent' => 100], 'TECHNICAL_FAILURE'],
            ['traceroute:v1', ['hops' => [], 'reached_destination' => false], 'COMPLETED'],
        ] as [$capability,$data,$expected]) {
            $step = (object) ['status' => 'COMPLETED', 'capability' => $capability, 'result' => ['ok' => true, 'data' => $data], 'error_code' => null];
            $this->assertSame($expected, Presentation::stepStatus($step));
            $this->assertSame('COMPLETED', $step->status);
        }
    }

    public function test_presentation_does_not_replace_a_timeout_summary_with_a_policy_summary(): void
    {
        $result = ['ok' => false, 'error' => 'policy_denied'];
        $conclusion = (object) ['classification' => 'INCONCLUSIVO', 'summary' => 'Prazo excedido', 'evidence' => ['tcp' => $result], 'next_steps' => []];
        $diagnostic = (object) ['conclusion' => $conclusion];
        $this->assertSame('Prazo excedido', Presentation::conclusion($diagnostic)['summary']);
    }

    public function test_traceroute_evidence_shows_hops_and_honest_destination_limit(): void
    {
        $step = new DiagnosticStep;
        $step->forceFill(['capability' => 'traceroute:v1', 'status' => 'COMPLETED', 'result' => ['ok' => true, 'data' => ['hops' => ['8.8.8.8'], 'reached_destination' => false, 'duration_ms' => 1]], 'definition' => []]);
        $html = view('diagnostics.evidence', compact('step'))->render();
        $this->assertStringContainsString('Destino não alcançado no limite de 5 saltos', $html);
        $this->assertStringContainsString('8.8.8.8', $html);
    }
}
