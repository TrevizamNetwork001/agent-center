<?php

namespace App\Diagnostics;

use Illuminate\Validation\ValidationException;

class Normalizer
{
    public const ERRORS = ['timeout', 'dns_failed', 'nodata', 'connection_refused', 'tls_failed', 'unreachable', 'policy_denied', 'action_failed', 'internal_error', 'cancelled', 'lease_lost', 'execution_timeout'];

    public function normalize(string $cap, array $r): array
    {
        $fail = fn () => throw ValidationException::withMessages(['result' => 'Resultado inválido.']);
        if (! isset($r['ok']) || ! is_bool($r['ok']) || array_diff(array_keys($r), ['ok', 'data', 'error'])) {
            $fail();
        }
        if (! $r['ok']) {
            if (! in_array($r['error'] ?? null, self::ERRORS, true)) {
                $fail();
            }

            return ['ok' => false, 'error' => $r['error']];
        }
        $d = $r['data'] ?? null;
        if (! is_array($d)) {
            $fail();
        }
        $fields = match ($cap) {
            'dns_lookup:v1' => ['answers', 'record_type', 'duration_ms', 'error'],'tcp_connect:v1' => ['connected', 'duration_ms', 'error'],'http_probe:v1' => ['status', 'tls', 'duration_ms', 'resolved_ip', 'error'],'icmp_ping:v1' => ['sent', 'received', 'loss_percent', 'min_ms', 'avg_ms', 'max_ms', 'duration_ms', 'error'],'traceroute:v1' => ['hops', 'reached_destination', 'duration_ms', 'error'],'agent_info' => ['version', 'hostname', 'python', 'device_operations_frozen'],default => []
        };
        if (! $fields || array_diff(array_keys($d), $fields)) {
            $fail();
        }
        foreach ($d as $k => $v) {
            if ($k === 'error') {
                if ($v !== null && ! in_array($v, self::ERRORS, true)) {
                    $fail();
                }
            } elseif (in_array($k, ['connected', 'tls', 'reached_destination', 'device_operations_frozen'])) {
                if (! is_bool($v)) {
                    $fail();
                }
            } elseif (in_array($k, ['answers', 'hops'])) {
                if (! is_array($v) || count($v) > 20) {
                    $fail();
                }foreach ($v as $ip) {
                    if (! is_string($ip) || ! filter_var($ip, FILTER_VALIDATE_IP)) {
                        $fail();
                    }
                }
            } elseif ($k === 'resolved_ip') {
                if (! is_string($v) || ! filter_var($v, FILTER_VALIDATE_IP)) {
                    $fail();
                }
            } elseif ($k === 'record_type') {
                if (! in_array($v, ['A', 'AAAA'], true)) {
                    $fail();
                }
            } elseif (in_array($k, ['version', 'hostname', 'python'])) {
                if (! is_string($v) || ! preg_match('/^[a-zA-Z0-9._-]{1,253}$/D', $v)) {
                    $fail();
                }
            } else {
                if ((! is_int($v) && ! is_float($v)) || is_bool($v) || ! is_finite((float) $v) || $v < 0 || $v > 60000) {
                    $fail();
                }
            }
        }
        $required = match ($cap) {
            'dns_lookup:v1' => ['answers', 'record_type', 'duration_ms'],'tcp_connect:v1' => ['connected', 'duration_ms'],'http_probe:v1' => ['status', 'tls', 'duration_ms', 'resolved_ip'],'icmp_ping:v1' => ['sent', 'received', 'loss_percent', 'duration_ms'],'traceroute:v1' => ['hops', 'reached_destination', 'duration_ms'],default => ['version', 'hostname', 'python', 'device_operations_frozen']
        };
        if (array_diff($required, array_keys($d))) {
            $fail();
        }
        if (isset($d['status']) && (! is_int($d['status']) || $d['status'] < 100 || $d['status'] > 599)) {
            $fail();
        }
        if (isset($d['sent']) && (! is_int($d['sent']) || ! is_int($d['received']) || $d['sent'] > 5 || $d['received'] > $d['sent'])) {
            $fail();
        }
        if (isset($d['loss_percent']) && $d['loss_percent'] > 100) {
            $fail();
        }

        if (isset($d['answers'])) {
            foreach ($d['answers'] as $ip) {
                if (! filter_var($ip, FILTER_VALIDATE_IP, ($d['record_type'] ?? 'A') === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6)) {
                    $fail();
                }
            }
        }
        if (isset($d['hops']) && count($d['hops']) > 5) {
            $fail();
        }
        if (isset($d['sent']) && abs($d['loss_percent'] - round(100 * ($d['sent'] - $d['received']) / max(1, $d['sent']), 2)) > 0.02) {
            $fail();
        }
        if (isset($d['min_ms'],$d['avg_ms'],$d['max_ms']) && ($d['min_ms'] > $d['avg_ms'] || $d['avg_ms'] > $d['max_ms'])) {
            $fail();
        }
        if ($cap === 'agent_info' && ($d['device_operations_frozen'] !== true || $d['version'] !== '0.5.2')) {
            $fail();
        }

        return ['ok' => true, 'data' => $d];
    }
}
