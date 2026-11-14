<?php

namespace Tests\Unit;

use App\Diagnostics\Normalizer;
use App\Noc\ReadOnlyLab\RouterOs7\Evidence;
use App\Noc\ReadOnlyLab\RouterOs7\Integration;
use App\Noc\ReadOnlyLab\RouterOs7\Operation;
use App\Noc\ReadOnlyLab\RouterOs7\Protocol;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RouterOsAgentEvidenceTest extends TestCase
{
    public function test_agent_results_use_existing_six_schemas_and_reject_unknown_or_sensitive_properties(): void
    {
        $fixtures = json_decode(file_get_contents(resource_path('device-readonly-cli-fixtures.json')), true)['routeros_v1'];
        foreach (Operation::cases() as $op) {
            $params = $op === Operation::Route ? ['destination' => '8.8.8.8'] : [];
            $commands = app(Protocol::class)->commands($op, $params);
            $outputs = array_map(fn ($command) => $fixtures[$command] ?? '', $commands);
            $evidence = app(Evidence::class)->parse($op, ['identity' => $fixtures[Protocol::IDENTITY], 'outputs' => $outputs], $params);
            $evidence['binding_digest'] = str_repeat('a', 64);
            $valid = app(Normalizer::class)->normalize(Integration::CAPABILITY, ['ok' => true, 'data' => $evidence]);
            $this->assertSame($op->value, $valid['data']['contract_id']);
            // Canonical journal re-delivery changes key order, never the contract semantics.
            ksort($evidence['identity']);
            $this->assertSame($op->value, app(Normalizer::class)->normalize(Integration::CAPABILITY, ['ok' => true, 'data' => $evidence])['data']['contract_id']);
            foreach (['sensitive' => true, 'complete' => true, 'mode' => 'REAL', 'binding_digest' => []] as $key => $value) {
                try {
                    app(Normalizer::class)->normalize(Integration::CAPABILITY, ['ok' => true, 'data' => [...$evidence, $key => $value]]);
                    $this->fail('Invalid evidence accepted');
                } catch (ValidationException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }
}
