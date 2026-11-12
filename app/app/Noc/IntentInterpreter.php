<?php

namespace App\Noc;

use App\Diagnostics\OperationalIntent;
use Illuminate\Support\Str;

class IntentInterpreter
{
    public function parse(string $body): ?array
    {
        $s = Str::lower(Str::ascii($body));
        if (preg_match('/^(?:o que (?:e|significa)|como funciona|explique o conceito|consulte (?:a )?knowledge)\b/', trim($s))) {
            return null;
        }
        $request = preg_match('/\b(?:quero|queria|preciso|verifi(?:que|ca|car)|conf(?:ira|erir)|analis(?:e|ar|a)|vej(?:a|o)|chec(?:ar|a)|cheque|como (?:ta|esta)|mostre|consulte|qual rota|tem alguma)\b/', $s);
        if (! $request) {
            return null;
        }
        $device = preg_match('/\b(?:roteador|router|equipamento|bng|bras|switch|olt|ne8000|ne40e|me60|ccr|crs)\b/', $s);
        $path = preg_match('/\b(?:caminho|mtr|traceroute|trajeto)\b/', $s);
        $goal = match (true) {
            (bool) preg_match('/\bbgp\b/', $s) => OperationalIntent::DeviceBgp,
            (bool) preg_match('/\b(?:potencia|sinal|niveis?)\b.*\b(?:optico|optica|onu|onus|ont|onts)\b|\b(?:onu|onus|ont|onts)\b.*\b(?:potencia|sinal|optico|optica)\b/', $s) => OperationalIntent::DeviceOptical,
            (bool) preg_match('/\b(?:onu|onus|ont|onts)\b.*\b(?:offline|online|status|estado)\b|\b(?:status|estado)\b.*\b(?:onu|onus|ont|onts)\b/', $s) => OperationalIntent::DeviceOnu,
            (bool) preg_match('/\bpppoe\b|\bsessoes\b.*\b(?:bng|bras)\b/', $s) => OperationalIntent::DevicePppoe,
            (bool) ($device && preg_match('/\b(?:crc|erros?|contadores)\b/', $s)) => OperationalIntent::DeviceCounters,
            (bool) ($device && preg_match('/\b(?:cpu|memoria|recursos)\b/', $s)) => OperationalIntent::DeviceResources,
            (bool) ($device && preg_match('/\b(?:identificacao|versao|plataforma)\b/', $s)) => OperationalIntent::DeviceIdentity,
            (bool) ($device && preg_match('/\balarmes?\b/', $s)) => OperationalIntent::DeviceAlarms,
            (bool) ($device && preg_match('/\b(?:interfaces?|crc|flaps|uplinks?)\b/', $s)) => OperationalIntent::DeviceInterfaces,
            (bool) preg_match('/\b(?:tabela de (?:rotas|roteamento)|fib)\b/', $s) || (bool) ($device && preg_match('/\brota\b/', $s)) => OperationalIntent::DeviceRouting,
            default => null,
        };
        if (! $goal && $device && $path) {
            $goal = OperationalIntent::DeviceRouting;
        }
        if (! $goal) {
            return null;
        }

        return ['intent' => $goal->value, 'ambiguous' => (bool) ($device && $path && $goal === OperationalIntent::DeviceRouting), 'protocol' => null, 'port' => null, 'method' => null, 'service' => null, 'content_slow' => false, 'path_metrics' => false, 'noc_request' => true];
    }
}
