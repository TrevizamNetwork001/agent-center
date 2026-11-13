<?php

namespace Tests\Unit;

use App\Models\NocDevice;
use App\Noc\ReadOnlyLab\Failure;
use App\Noc\ReadOnlyLab\Operation;
use App\Noc\ReadOnlyLab\Parser;
use App\Noc\ReadOnlyLab\Platform;
use Tests\TestCase;

class DeviceReadOnlyParserTest extends TestCase
{
    public function test_closed_parameters_and_configuration_operations_have_no_representation(): void
    {
        foreach (['system-view', 'reboot', 'DEVICE_WRITE', '/system reset-configuration'] as $op) {
            $this->assertNull(Operation::tryFrom($op));
        }
        foreach (Platform::cases() as $p) {
            try {
                $p->command(Operation::Route, ['destination' => '8.8.8.8;reboot']);
                $this->fail('Command accepted');
            } catch (Failure $e) {
                $this->assertSame('LAB_ROUTE_NOT_SUPPORTED', $e->reason);
            }
        }
    }

    public function test_all_fixture_parsers_and_malformed_truncated_and_duplicate_rows(): void
    {
        $fixtures = json_decode(file_get_contents(resource_path('device-readonly-cli-fixtures.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (Platform::cases() as $p) {
            $h = $p === Platform::Huawei;
            $d = (new NocDevice)->forceFill(['manufacturer' => $h ? 'huawei' : 'mikrotik', 'platform' => $h ? 'huawei-vrp' : 'routeros7', 'model' => $h ? 'NE8000 M4' : 'CCR2004-16G-2S+', 'os_version' => $h ? 'V800R023C00SPC500' : '7.16.2']);
            $f = $fixtures[$h ? 'huawei' : 'routeros'];
            foreach (Operation::cases() as $op) {
                $wire = ['identity' => $f[$p->identityCommand()], 'output' => $f[$p->command($op, $op === Operation::Route ? ['destination' => '8.8.8.8'] : [])]];
                $e = app(Parser::class)->parse($d, $op, $wire);
                $this->assertSame($op->value, $e['contract_id']);
                foreach (['not output', substr($wire['output'], 0, -1)] as $bad) {
                    try {
                        app(Parser::class)->parse($d, $op, [...$wire, 'output' => $bad]);
                        $this->fail('Invalid accepted');
                    } catch (Failure $ex) {
                        $this->assertSame('UNRECOGNIZED_OR_TRUNCATED_OUTPUT', $ex->reason);
                    }
                }
            }
        }
    }
}
