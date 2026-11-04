<?php

namespace App\Diagnostics;

use App\Models\Profile;
use App\Models\Skill;
use Illuminate\Validation\ValidationException;

class Planner
{
    public function target(string $target): array
    {
        $url = str_contains($target, '://') ? $target : 'https://'.$target;
        $p = parse_url($url);
        $host = strtolower(rtrim($p['host'] ?? '', '.'));
        $host = trim($host, '[]');
        if (! $p || ($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || isset($p['port']) && $p['port'] !== 443 || strlen($target) > 512 || ! filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || ! $host) {
            throw ValidationException::withMessages(['target' => 'Informe um hostname, IP ou URL HTTPS válida sem credenciais ou query.']);
        }

        return ['host' => $host, 'url' => 'https://'.(str_contains($host, ':') ? '['.$host.']' : $host).($p['path'] ?? '/'), 'literal' => (bool) filter_var($host, FILTER_VALIDATE_IP)];
    }

    public function plan(Profile $profile, string $target, array $capabilities, array $context = []): array
    {
        $t = $this->target($target);
        $steps = [];
        $skills = Skill::all()->keyBy('key');
        $add = function ($key, $skill, $wave, $optional = false, $condition = 'always', $record = null) use (&$steps, $skills, $capabilities, $t) {
            $s = $skills[$skill];
            $input = ['target' => $t['host']];
            if ($record) {
                $input['record_type'] = $record;
            }if ($skill === 'tcp_connect') {
                $input['port'] = 443;
            }if ($skill === 'http_probe') {
                $input['url'] = $t['url'];
            }
            $steps[] = ['key' => $key, 'skill' => $skill, 'logical_group' => $s->display_name, 'capability' => $s->capability, 'skill_version' => $s->version, 'timeout' => $s->timeout, 'wave' => $wave, 'optional' => $optional, 'condition' => $condition, 'input' => $input, 'available' => $s->enabled && in_array($s->capability, $capabilities, true), 'input_schema' => $s->input_schema, 'output_schema' => $s->output_schema];
        };
        if (! $t['literal']) {
            $add('dns_a', 'dns_lookup', 1, record: 'A');
            $add('dns_aaaa', 'dns_lookup', 1, record: 'AAAA');
        }
        $network = $t['literal'] ? 'always' : 'dns_ok';
        if ($profile->key === 'PERDA_LATENCIA') {
            $add('ping', 'icmp_ping', 2, false, $network);
            $add('trace', 'traceroute', 3, true, 'trace_needed');
        } elseif ($profile->key !== 'PROBLEMA_DNS') {
            $add('tcp', 'tcp_connect', 2, false, $network);
            $add('https', 'http_probe', 3, false, 'tcp_ok');
            $add('ping', 'icmp_ping', 4, true, $network);
            $add('trace', 'traceroute', 5, true, 'trace_needed');
        }

        return ['schema_version' => 1, 'profile' => $profile->key, 'profile_version' => $profile->version, 'target' => $t, 'thresholds' => [...$profile->definition, 'trace_requested' => ($context['trace_requested'] ?? false) === true], 'steps' => $steps];
    }

    public function condition(string $condition, array $evidence, array $thresholds): bool
    {
        $dns = array_filter($evidence, fn ($r, $k) => str_starts_with($k, 'dns_') && ($r['ok'] ?? false) && count($r['data']['answers'] ?? []) > 0, ARRAY_FILTER_USE_BOTH);

        return match ($condition) {
            'always' => true,'dns_ok' => (bool) $dns,'tcp_ok' => ($evidence['tcp']['data']['connected'] ?? false) === true,'trace_needed' => ($thresholds['trace_requested'] ?? false) === true || isset($evidence['tcp']) && ! ($evidence['tcp']['data']['connected'] ?? false) || isset($evidence['https']) && (($evidence['https']['data']['status'] ?? 500) >= 500 || ($evidence['https']['data']['duration_ms'] ?? 0) > ($thresholds['slow_ms'] ?? 2000)) || isset($evidence['ping']['data']['loss_percent']) && $evidence['ping']['data']['loss_percent'] > ($thresholds['loss_percent'] ?? 10),default => false
        };
    }
}
