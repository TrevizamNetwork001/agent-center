<?php

namespace Tests\Feature;

use App\Agent\Protocol;
use App\Agent\Releases;
use App\Diagnostics\Classifier;
use App\Diagnostics\Normalizer;
use App\Web\Presentation;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DnsPolicyRegressionTest extends TestCase
{
    public function test_forward_dns_evidence_does_not_imply_probe_network_authorization(): void
    {
        $evidence = [
            'dns_a' => ['ok' => true, 'data' => ['record_type' => 'A', 'answers' => ['192.168.1.1'], 'duration_ms' => 1, 'error' => null]],
            'dns_aaaa' => ['ok' => true, 'data' => ['record_type' => 'AAAA', 'answers' => ['fd00::1'], 'duration_ms' => 1, 'error' => null]],
        ];
        foreach ($evidence as $result) {
            app(Normalizer::class)->normalize('dns_lookup:v1', $result);
        }
        $this->assertSame('NORMAL', app(Classifier::class)->classify('PROBLEMA_DNS', $evidence)['classification']);
        $this->assertContains('0.5.7', Protocol::VERSIONS);
        $this->assertContains('0.5.6', Protocol::VERSIONS);
        $this->assertSame('0.6.1', app(Releases::class)->all()[0]['version']);
    }

    public static function scenarios(): array
    {
        $ok = fn ($type, $answers, $error = null) => ['ok' => true, 'data' => ['record_type' => $type, 'answers' => $answers, 'duration_ms' => 1, 'error' => $error]];
        $blocked = ['ok' => false, 'error' => 'policy_denied'];

        return [
            'A and AAAA denied' => [['dns_a' => $blocked, 'dns_aaaa' => $blocked], 'INCONCLUSIVO'],
            'no checks' => [[], 'INCONCLUSIVO'],
            'positive A and AAAA' => [['dns_a' => $ok('A', ['8.8.8.8']), 'dns_aaaa' => $ok('AAAA', ['2001:4860:4860::8888'])], 'NORMAL'],
            'positive A, no AAAA record' => [['dns_a' => $ok('A', ['8.8.8.8']), 'dns_aaaa' => $ok('AAAA', [], 'nodata')], 'NORMAL'],
            'addressless libc outcome is not proven NXDOMAIN' => [['dns_a' => $ok('A', [], 'nodata'), 'dns_aaaa' => $ok('AAAA', [], 'nodata')], 'INCONCLUSIVO'],
            'real DNS operation timeout' => [['dns_a' => ['ok' => false, 'error' => 'timeout'], 'dns_aaaa' => $blocked], 'INDICIO_DE_FALHA'],
            'resolver error after query' => [['dns_a' => $ok('A', [], 'dns_failed'), 'dns_aaaa' => $ok('AAAA', [], 'dns_failed')], 'INDICIO_DE_FALHA'],
            'executor timeout is not DNS timeout' => [['dns_a' => ['ok' => false, 'error' => 'execution_timeout'], 'dns_aaaa' => $blocked], 'ERRO_DE_EXECUCAO'],
            'positive A, denied AAAA is incomplete' => [['dns_a' => $ok('A', ['8.8.8.8']), 'dns_aaaa' => $blocked], 'INCONCLUSIVO'],
        ];
    }

    #[DataProvider('scenarios')]
    public function test_classifications(array $evidence, string $expected): void
    {
        foreach ($evidence as $result) {
            app(Normalizer::class)->normalize('dns_lookup:v1', $result);
        }
        $this->assertSame($expected, app(Classifier::class)->classify('PROBLEMA_DNS', $evidence)['classification']);
    }

    public function test_policy_denial_has_explicit_operational_presentation(): void
    {
        $step = (object) ['status' => 'FAILED', 'result' => ['ok' => false, 'error' => 'policy_denied'], 'error_code' => 'policy_denied'];
        $this->assertSame('POLICY_BLOCKED', Presentation::stepStatus($step));
        $this->assertSame('Bloqueado por política', Presentation::label(Presentation::groupStatus(collect([$step]))));
        $this->assertStringContainsString('política', app(Classifier::class)->classify('PROBLEMA_DNS', ['dns_a' => $step->result, 'dns_aaaa' => $step->result])['summary']);
    }

    public function test_legacy_policy_denial_display_keeps_original_record_unchanged(): void
    {
        $row = ['ok' => false, 'error' => 'policy_denied'];
        $record = (object) ['classification' => 'INDICIO_DE_FALHA', 'summary' => 'legacy', 'evidence' => ['dns_a' => $row, 'dns_aaaa' => $row]];
        $diagnostic = (object) ['conclusion' => $record, 'profile' => (object) ['key' => 'PROBLEMA_DNS']];
        $display = Presentation::conclusion($diagnostic);
        $this->assertSame('INCONCLUSIVO', $display['classification']);
        $this->assertSame('INDICIO_DE_FALHA', $record->classification);
        $this->assertSame('legacy', $record->summary);
    }

    public function test_display_timezone_preserves_instant_and_original_date(): void
    {
        $date = Carbon::parse('2026-11-05T14:44:51+01:00');
        $before = $date->toIso8601String();
        $this->assertSame('05/11/2026 10:44:51 -03:00', Presentation::time($date));
        $this->assertSame($before, $date->toIso8601String());
        $this->assertSame('10:44:51 -03:00', Presentation::time($date, 'H:i:s T'));
    }
}
