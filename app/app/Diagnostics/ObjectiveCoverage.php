<?php

namespace App\Diagnostics;

/** Coverage never changes the official deterministic classification. */
class ObjectiveCoverage
{
    public function assess(?OperationalIntent $goal, array $evidence, array $plan, ?string $technical, bool $cancelled = false): array
    {
        if (! $goal) {
            return ['intent' => null, 'label' => 'Objetivo não registrado', 'status' => 'NAO_REGISTRADO', 'status_label' => 'NÃO REGISTRADO', 'request_classification' => $technical];
        }
        $keys = match ($goal) {
            OperationalIntent::Route => array_values(array_intersect(['mtr_icmp', 'mtr_tcp', 'trace'], array_keys($evidence))),
            OperationalIntent::Connectivity, OperationalIntent::Latency => ['ping'], OperationalIntent::Port => ['tcp'],
            OperationalIntent::Dns => ['dns_a', 'dns_aaaa'],
            default => ['tcp', 'https'],
        };
        if ($goal === OperationalIntent::Route) {
            $planned = array_values(array_intersect(['mtr_icmp', 'mtr_tcp', 'trace'], $plan['objective']['required_steps'] ?? $plan['thresholds']['required_steps'] ?? []));
            $keys = $planned ?: ($keys ?: ['mtr_icmp']);
        }
        $measured = fn ($key) => ($evidence[$key]['ok'] ?? false) === true || in_array($evidence[$key]['error'] ?? null, ['timeout', 'connection_refused', 'unreachable', 'tls_failed', 'dns_failed'], true);
        $count = count(array_filter($keys, $measured));
        $complete = $keys !== [] && $count === count($keys) && ! $cancelled;
        $status = $complete ? 'EXECUTADA' : ($count ? 'PARCIAL' : 'NAO_EXECUTADA');
        if ($goal === OperationalIntent::Slowness && $complete) {
            $status = 'PARCIAL';
        }
        $limitations = [];
        if ($goal === OperationalIntent::Route) {
            $limitations[] = 'Ping simples e conectividade HTTPS não comprovam a saúde da rota.';
            if (in_array('trace', $keys, true)) {
                $limitations[] = 'Traceroute observa saltos e alcance; não mede perda MTR por salto.';
                if (($plan['objective']['path_metrics'] ?? false) === true) {
                    $status = $count ? 'PARCIAL' : 'NAO_EXECUTADA';
                    $complete = false;
                }
            }
            $complete = $complete && collect($keys)->every(function ($key) use ($evidence, $plan) {
                if ($key === 'trace') {
                    return ($evidence[$key]['ok'] ?? false) && ($evidence[$key]['data']['reached_destination'] ?? false);
                }

                return ($evidence[$key]['ok'] ?? false) && app(MtrPath::class)->analyze($evidence[$key]['data'], $plan['thresholds'] ?? [])['classification'] !== 'INCONCLUSIVO';
            });
        }
        if ($goal === OperationalIntent::Slowness) {
            $limitations[] = 'As sondagens investigam conectividade inicial; não medem reprodução de vídeo, banda ou todos os fatores de lentidão.';
            $complete = false;
        }

        return ['intent' => $goal->value, 'label' => $goal->label(), 'status' => $status, 'status_label' => $status === 'NAO_EXECUTADA' ? 'NÃO EXECUTADA' : $status,
            'request_classification' => $complete ? $technical : 'INCONCLUSIVO', 'limitations' => $limitations];
    }
}
