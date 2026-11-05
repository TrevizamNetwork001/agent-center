<?php

namespace Tests\Unit;

use App\Diagnostics\Classifier;
use PHPUnit\Framework\TestCase;

class DiagnosticPolicyTest extends TestCase
{
    public function test_blocked_dns_is_inconclusive_and_preserves_evidence(): void
    {
        $evidence = ['dns_a' => ['ok' => false, 'error' => 'policy_denied'], 'dns_aaaa' => ['ok' => false, 'error' => 'policy_denied']];
        $result = (new Classifier)->classify('PROBLEMA_DNS', $evidence);
        self::assertSame('INCONCLUSIVO', $result['classification']);
        self::assertSame($evidence, $result['evidence']);
        self::assertStringContainsString('política', $result['summary']);
    }

    public function test_partial_dns_success_with_blocked_required_query_is_inconclusive(): void
    {
        $result = (new Classifier)->classify('PROBLEMA_DNS', ['dns_a' => ['ok' => true, 'data' => ['answers' => ['8.8.8.8']]], 'dns_aaaa' => ['ok' => false, 'error' => 'policy_denied']]);
        self::assertSame('INCONCLUSIVO', $result['classification']);
    }

    public function test_real_dns_failure_remains_failure_despite_another_blocked_query(): void
    {
        $result = (new Classifier)->classify('PROBLEMA_DNS', ['dns_a' => ['ok' => false, 'error' => 'dns_failed'], 'dns_aaaa' => ['ok' => false, 'error' => 'policy_denied']]);
        self::assertSame('INDICIO_DE_FALHA', $result['classification']);
    }

    public function test_blocked_optional_probe_does_not_override_site_success(): void
    {
        $result = (new Classifier)->classify('SITE_LENTO', ['dns_a' => ['ok' => true, 'data' => ['answers' => ['8.8.8.8']]], 'tcp' => ['ok' => true, 'data' => ['connected' => true]], 'https' => ['ok' => true, 'data' => ['status' => 200, 'tls' => true, 'duration_ms' => 10]], 'ping' => ['ok' => false, 'error' => 'policy_denied']]);
        self::assertSame('NORMAL', $result['classification']);
    }

    public function test_nested_policy_error_does_not_turn_placeholder_metrics_into_failure(): void
    {
        $evidence = ['ping' => ['ok' => false, 'data' => ['error' => 'policy_denied', 'received' => 0, 'loss_percent' => 100]]];
        $result = (new Classifier)->classify('PERDA_LATENCIA', $evidence);
        self::assertSame('INCONCLUSIVO', $result['classification']);
        self::assertSame($evidence, $result['evidence']);
        self::assertStringContainsString('autorização', $result['next_steps'][0]);
    }

    public function test_internal_error_is_preserved_alongside_blocked_probe(): void
    {
        $result = (new Classifier)->classify('SITE_LENTO', ['dns_a' => ['ok' => false, 'error' => 'policy_denied'], 'tcp' => ['ok' => false, 'error' => 'internal_error']]);
        self::assertSame('ERRO_DE_EXECUCAO', $result['classification']);
    }

    public function test_cancelled_diagnostic_preserves_evidence_and_interruption_summary(): void
    {
        $evidence = ['dns_a' => ['ok' => false, 'error' => 'policy_denied']];
        $result = (new Classifier)->classify('PROBLEMA_DNS', $evidence, [], true);
        self::assertSame('INCONCLUSIVO', $result['classification']);
        self::assertSame($evidence, $result['evidence']);
        self::assertStringContainsString('interrompido', $result['summary']);
    }

    public function test_existing_golden_scenarios(): void
    {
        foreach (json_decode(file_get_contents(__DIR__.'/../../resources/fixtures/diagnostics.json'), true, 512, JSON_THROW_ON_ERROR) as $fixture) {
            if (! isset($fixture['evidence'])) {
                continue;
            }
            $result = (new Classifier)->classify($fixture['profile'], $fixture['evidence'], $fixture['thresholds'] ?? [], $fixture['cancelled'] ?? false);
            self::assertSame($fixture['expected'], $result['classification'], $fixture['name'] ?? 'fixture');
        }
    }
}
