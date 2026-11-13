<?php

namespace Tests\Unit;

use App\Noc\ReadOnlyLab\Failure;
use App\Noc\ReadOnlyLab\RouterOs7\Evidence;
use App\Noc\ReadOnlyLab\RouterOs7\Operation;
use App\Noc\ReadOnlyLab\RouterOs7\Protocol;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** A: deterministic contracts and parser tests; no network. */
class MikrotikProtocolTest extends TestCase
{
    public function test_closed_operations_reject_injection_unknown_parameters_and_ipv6_execution(): void
    {
        $p = app(Protocol::class);
        foreach ([['destination' => '8.8.8.8; /system reboot'], ['destination' => '8.8.8.8', 'table' => "main\n/system reboot"],
            ['destination' => '8.8.8.8', 'command' => '/system reboot'], ['destination' => '2001:db8::1'], ['destination' => '8.8.8.8', 'table' => 'main&&true']] as $params) {
            try {
                $p->commands(Operation::Route, $params);
                $this->fail('Unsafe input accepted');
            } catch (Failure|ValidationException) {
                $this->assertNull(Operation::tryFrom('/system reboot'));
            }
        }
        $commands = $p->commands(Operation::Route, ['destination' => '8.8.8.8']);
        $this->assertCount(33, $commands);
        $this->assertStringContainsString('dst-address=0.0.0.0/0', $commands[32]);
        $this->assertSame('DEVICE_IDENTIFICATION_READ', Operation::Identity->contract());
    }

    public function test_malformed_truncated_duplicate_sensitive_and_excess_rows_fail_closed(): void
    {
        $p = app(Protocol::class);
        foreach (['0 E name=peer', "0 E password=fixture\n", "0 E name=one name=two\n", "0 E name=\"unterminated\n", "\x1b[31m0 E name=peer\n", str_repeat("0 E name=peer\n", 129), str_repeat('x', 65537)] as $output) {
            try {
                $p->rows($output, ['name']);
                $this->fail('Invalid response accepted');
            } catch (Failure) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_missing_information_remains_null_and_invalid_values_are_rejected(): void
    {
        $p = app(Protocol::class);
        $this->assertNull($p->number(null));
        $this->assertSame(1073741824, $p->bytes('1024.0MiB'));
        $this->assertSame(93784.03, $p->duration('1d2h3m4s30ms'));
        foreach (['-1', '1e10', '9007199254740992', '1;reboot'] as $value) {
            try {
                $p->number($value);
                $this->fail('Invalid counter accepted');
            } catch (Failure) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_malformed_cpu_unit_is_not_coerced_to_a_valid_measurement(): void
    {
        $fixtures = json_decode(file_get_contents(resource_path('device-readonly-cli-fixtures.json')), true, flags: JSON_THROW_ON_ERROR)['routeros_v1'];
        $this->expectException(Failure::class);
        app(Evidence::class)->parse(Operation::Resources, ['identity' => $fixtures[Protocol::IDENTITY],
            'outputs' => [str_replace('12%', '12%%', $fixtures[Protocol::IDENTITY])]], []);
    }

    public function test_versioned_fixture_contracts_normalize_without_real_hardware_claims(): void
    {
        $fixtures = json_decode(file_get_contents(resource_path('device-readonly-cli-fixtures.json')), true, flags: JSON_THROW_ON_ERROR)['routeros_v1'];
        $p = app(Protocol::class);
        foreach (Operation::cases() as $op) {
            $params = $op === Operation::Route ? ['destination' => '8.8.8.8'] : [];
            $wire = ['identity' => $fixtures[Protocol::IDENTITY], 'outputs' => array_map(fn ($command) => $fixtures[$command], $p->commands($op, $params))];
            $e = app(Evidence::class)->parse($op, $wire, $params);
            $this->assertSame('SIMULATED', $e['mode']);
            $this->assertSame('LAB', $e['environment']);
            $this->assertSame($op->value, $e['contract_id']);
            $this->assertFalse($e['complete']);
            $wire['outputs'] = array_map(fn ($output) => str_replace("\n", "\r\n", $output), $wire['outputs']);
            $this->assertSame($op->value, app(Evidence::class)->parse($op, $wire, $params)['contract_id']);
        }
    }
}
