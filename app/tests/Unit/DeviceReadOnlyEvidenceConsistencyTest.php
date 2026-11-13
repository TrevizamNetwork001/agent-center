<?php

namespace Tests\Unit;

use App\Models\NocDevice;
use App\Noc\ReadOnlyLab\Failure;
use App\Noc\ReadOnlyLab\Operation;
use App\Noc\ReadOnlyLab\Parser;
use App\Noc\ReadOnlyLab\Platform;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeviceReadOnlyEvidenceConsistencyTest extends TestCase
{
    public function test_duplicate_peers_and_invalid_uptime_never_become_evidence(): void
    {
        $f = json_decode(file_get_contents(resource_path('device-readonly-cli-fixtures.json')), true, flags: JSON_THROW_ON_ERROR)['huawei'];
        $d = (new NocDevice)->forceFill(['manufacturer' => 'huawei', 'platform' => 'huawei-vrp', 'model' => 'NE8000 M4', 'os_version' => 'V800R023C00SPC500']);
        $wire = ['identity' => $f[Platform::Huawei->identityCommand()], 'output' => $f['display bgp peer']];
        try {
            app(Parser::class)->parse($d, Operation::Bgp, [...$wire, 'output' => str_replace('192.0.2.2', '192.0.2.1', $wire['output'])]);
            $this->fail('Duplicate peer accepted');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('analysis', $e->errors());
        }
        try {
            app(Parser::class)->parse($d, Operation::Bgp, [...$wire, 'output' => str_replace('01:00:00', '01:99:00', $wire['output'])]);
            $this->fail('Invalid uptime accepted');
        } catch (Failure $e) {
            $this->assertSame('INVALID_ROWS', $e->reason);
        }
    }
}
