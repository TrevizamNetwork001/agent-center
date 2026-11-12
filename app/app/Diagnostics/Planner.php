<?php

namespace App\Diagnostics;

use App\Models\Profile;
use App\Models\Skill;
use Illuminate\Validation\ValidationException;

class Planner
{
    public function target(string $target): array
    {
        $target = trim($target);
        $url = filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'https://['.$target.']' : (str_contains($target, '://') ? $target : 'https://'.$target);
        $p = parse_url($url);
        $host = strtolower(rtrim($p['host'] ?? '', '.'));
        $host = trim($host, '[]');
        if (! $p || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || isset($p['port']) && $p['port'] !== 443 || strlen($target) > 512 || ! filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || ! $host) {
            throw ValidationException::withMessages(['target' => 'Informe um hostname, IP ou URL HTTPS válida sem credenciais ou query.']);
        }

        return ['host' => $host, 'url' => 'https://'.(str_contains($host, ':') ? '['.$host.']' : $host).($p['path'] ?? '/'), 'literal' => (bool) filter_var($host, FILTER_VALIDATE_IP)];
    }

    public function validateTarget(Profile $profile, string $target): array
    {
        $parsed = $this->target($target);
        if (! array_key_exists($profile->key, Catalog::PROFILES)) {
            throw ValidationException::withMessages(['profile_id' => 'Perfil de diagnóstico não suportado.']);
        }
        if ($profile->key === 'PROBLEMA_DNS' && $parsed['literal']) {
            throw ValidationException::withMessages(['target' => 'Para consultar registros A e AAAA, informe um domínio, não um IP.']);
        }

        return $parsed;
    }

    public function plan(Profile $profile, string $target, array $capabilities, array $context = []): array
    {
        $t = $this->validateTarget($profile, $target);
        $goal = isset($context['operational_intent']) ? OperationalIntent::tryFrom($context['operational_intent']) : OperationalIntent::forProfile($profile->key);
        $intentAware = ($context['intent_planning_version'] ?? null) === 1;
        if ($intentAware && $goal !== OperationalIntent::forProfile($profile->key)) {
            throw ValidationException::withMessages(['profile_id' => 'O plano não corresponde ao objetivo solicitado.']);
        }
        $path = $goal === OperationalIntent::Route;
        $steps = [];
        $skills = Skill::all()->keyBy('key');
        $add = function ($key, $skill, $wave, $optional = false, $condition = 'always', $record = null) use (&$steps, $skills, $capabilities, $t) {
            $s = $skills[$skill];
            $input = ['target' => $t['host']];
            if ($skill === 'mtr_path') {
                $input += ['destination' => $t['host'], 'protocol' => $key === 'mtr_tcp' ? 'tcp' : 'icmp', 'cycles' => 5, 'max_hops' => 16, 'interval' => 1];
            }
            if ($record) {
                $input['record_type'] = $record;
            }if ($skill === 'tcp_connect') {
                $input['port'] = 443;
            }if ($skill === 'http_probe') {
                $input['url'] = $t['url'];
            }
            $steps[] = ['key' => $key, 'skill' => $skill, 'logical_group' => $s->display_name, 'capability' => $s->capability, 'skill_version' => $s->version, 'timeout' => $s->timeout, 'wave' => $wave, 'optional' => $optional, 'condition' => $condition, 'input' => $input, 'available' => $s->enabled && $s->safe && $s->capability === Catalog::SKILLS[$skill][0] && in_array($s->capability, $capabilities, true), 'input_schema' => $s->input_schema, 'output_schema' => $s->output_schema];
        };
        if (! $t['literal']) {
            $add('dns_a', 'dns_lookup', 1, record: 'A');
            $add('dns_aaaa', 'dns_lookup', 1, record: 'AAAA');
        }
        if ($profile->key === 'PROBLEMA_DNS' && version_compare($context['agent_version'] ?? '0.0.0', '0.6.0', '>=') && filter_var($context['dns_resolvers'][0] ?? '', FILTER_VALIDATE_IP)) {
            foreach (['udp', 'tcp'] as $transport) {
                $add('dns_'.$transport, 'dns_lookup', 1, record: 'A');
                $index = count($steps) - 1;
                $steps[$index]['input'] += ['transport' => $transport, 'resolver' => $context['dns_resolvers'][0]];
                $steps[$index]['logical_group'] = 'DNS '.strtoupper($transport);
            }
        }
        $network = $t['literal'] ? 'always' : 'dns_ok';
        if (in_array($profile->key, ['MTR_ICMP', 'MTR_TCP'], true)) {
            $add($profile->key === 'MTR_TCP' ? 'mtr_tcp' : 'mtr_icmp', 'mtr_path', 2, false, $network);
        } elseif ($profile->key === 'CAMINHO_TRACEROUTE') {
            $add('trace', 'traceroute', 2, false, $network);
        } elseif ($profile->key === 'PORTA_SERVICO') {
            $add('tcp', 'tcp_connect', 2, false, $network);
        } elseif (in_array($profile->key, ['PERDA_LATENCIA', 'CONECTIVIDADE_BASICA'], true)) {
            $add('ping', 'icmp_ping', 2, false, $network);
            if (! $intentAware && $profile->key === 'PERDA_LATENCIA') {
                $add('trace', 'traceroute', 3, true, 'trace_needed');
            }
        } elseif ($profile->key !== 'PROBLEMA_DNS') {
            $add('tcp', 'tcp_connect', 2, false, $network);
            $add('https', 'http_probe', 3, false, 'tcp_ok');
            if (! $intentAware || $goal === OperationalIntent::Slowness) {
                $add('ping', 'icmp_ping', 4, true, $network);
                $add('trace', 'traceroute', 5, true, 'trace_needed');
            }
        }

        if (! $path && ! str_starts_with($profile->key, 'MTR_') && $profile->key !== 'PROBLEMA_DNS' && ($context['mtr_requested'] ?? false) === true) {
            $add('mtr_icmp', 'mtr_path', 6, false, $network);
            $add('mtr_tcp', 'mtr_path', 7, false, $network);
        }

        if ($path && $intentAware || isset($context['agent']) && ($context['mtr_requested'] ?? false) === true) {
            foreach ($steps as &$step) {
                if (in_array($step['capability'], ['mtr_path_analysis:v1', 'traceroute:v1'], true)) {
                    $reason = isset($context['agent']) ? app(PathReadiness::class)->check($context['agent'], $step['capability'], $t['host'], $step['input']['protocol'] ?? 'icmp') : 'policy_not_verified';
                    $step['blocked_reason'] = $reason;
                    $step['available'] = $step['available'] && $reason === null;
                }
            }
            unset($step);
        }
        $required = array_values(array_column(array_filter($steps, fn ($step) => ! $step['optional']), 'key'));

        return ['schema_version' => 1, 'profile' => $profile->key, 'profile_version' => $profile->version, 'target' => $t,
            'objective' => ['version' => 1, 'intent' => $goal?->value, 'label' => $goal?->label(), 'required_steps' => $required, 'path_metrics' => ($context['path_metrics'] ?? false) === true, 'path_method' => $path ? ($profile->key === 'CAMINHO_TRACEROUTE' ? 'traceroute_icmp' : ($profile->key === 'MTR_TCP' ? 'mtr_tcp' : 'mtr_icmp')) : null, 'dns_justification' => ! $t['literal'] ? ($goal === OperationalIntent::Dns ? 'Objetivo de resolução DNS.' : 'Resolver o hostname informado antes da sondagem do destino.') : null],
            'thresholds' => [...['mtr_threshold_version' => 1, 'mtr_min_cycles' => 5, 'mtr_loss_percent' => 0, 'mtr_rtt_increase_ms' => 50, 'mtr_rtt_ratio' => 2], ...$profile->definition, 'trace_requested' => ($context['trace_requested'] ?? false) === true, 'target_literal' => $t['literal'], 'mtr_requested' => ($context['mtr_requested'] ?? false) === true, 'required_steps' => $required], 'steps' => $steps];
    }

    public function condition(string $condition, array $evidence, array $thresholds): bool
    {
        $dns = array_filter($evidence, fn ($r, $k) => str_starts_with($k, 'dns_') && ($r['ok'] ?? false) === true && count($r['data']['answers'] ?? []) > 0, ARRAY_FILTER_USE_BOTH);
        $error = fn ($result) => $result['error'] ?? $result['data']['error'] ?? null;
        $tcpFailure = isset($evidence['tcp']) && (in_array($error($evidence['tcp']), ['timeout', 'connection_refused', 'unreachable'], true) || (($evidence['tcp']['ok'] ?? false) === true && ($evidence['tcp']['data']['connected'] ?? true) === false));
        $http = ($evidence['https']['ok'] ?? false) === true ? ($evidence['https']['data'] ?? []) : [];
        $httpFailure = isset($evidence['https']) && (in_array($error($evidence['https']), ['timeout', 'tls_failed', 'unreachable'], true) || $http && ((($http['status'] ?? 0) >= 500 && ($http['status'] ?? 0) !== 501) || ($http['duration_ms'] ?? 0) > ($thresholds['slow_ms'] ?? 2000)));
        $ping = ($evidence['ping']['ok'] ?? false) === true ? ($evidence['ping']['data'] ?? []) : [];
        $pingRequired = isset($thresholds['required_steps']) ? in_array('ping', $thresholds['required_steps'], true) : (! isset($evidence['tcp']) && ! isset($evidence['https']));
        $pingFailure = $pingRequired && isset($evidence['ping']) && (in_array($error($evidence['ping']), ['timeout', 'unreachable'], true) || $ping && (($ping['loss_percent'] ?? 0) > ($thresholds['loss_percent'] ?? 10) || ($ping['avg_ms'] ?? 0) > ($thresholds['latency_ms'] ?? 150)));

        return match ($condition) {
            'always' => true,
            'dns_ok' => (bool) $dns,
            'tcp_ok' => ($evidence['tcp']['ok'] ?? false) === true && ($evidence['tcp']['data']['connected'] ?? false) === true,
            'trace_needed' => ($thresholds['trace_requested'] ?? false) === true || ($thresholds['trace_on_failure'] ?? true) === true && ($tcpFailure || $httpFailure || $pingFailure),
            default => false,
        };
    }
}
