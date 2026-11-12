<?php

namespace App\Noc;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** Future data-only contract. Construction grants no permission and has no transport/execution method. */
final readonly class QuerySpecification
{
    public function __construct(public int $tenantId, public string $deviceId, public string $capabilityId, public int $deviceRevision, public array $parameters = [])
    {
        $keys = match ($capabilityId) {
            'routing.table','routing.fib','routing.bgp.routes' => ['destination', 'vrf'],'routing.bgp.sessions' => ['peer'],'ftth.optical.signal','ftth.onu.status' => ['pon', 'onu_id'],'system.interfaces','switching.errors' => ['interface'],default => []
        };
        if ($tenantId < 1 || ! Str::isUuid($deviceId) || $deviceRevision < 1 || ! isset(app(Catalog::class)->all()['capabilities'][$capabilityId]) || array_diff(array_keys($parameters), $keys)) {
            throw new InvalidArgumentException('Invalid typed query specification.');
        }
        foreach ($parameters as $key => $value) {
            if ($key === 'onu_id') {
                if (! is_int($value) || $value < 0 || $value > 65535) {
                    throw new InvalidArgumentException('Invalid ONU identifier.');
                }

                continue;
            }
            if (! is_string($value) || strlen($value) > 128 || ! preg_match('/^[a-zA-Z0-9_.:\/\[\]-]+$/D', $value)) {
                throw new InvalidArgumentException('Invalid typed query value.');
            }
            if (in_array($key, ['destination', 'peer'], true) && ! filter_var($value, FILTER_VALIDATE_IP)) {
                throw new InvalidArgumentException('Literal IP required.');
            }
        }
    }
}
