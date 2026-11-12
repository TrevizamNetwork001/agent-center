<?php

namespace App\Diagnostics;

use App\Agent\Protocol;
use App\Models\Profile;
use App\Models\Skill;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Catalog
{
    public const PROFILES = ['SITE_LENTO' => 'Site lento', 'PROBLEMA_DNS' => 'Problema DNS', 'SITE_NAO_ABRE' => 'Site não abre', 'PERDA_LATENCIA' => 'Perda / Latência', 'GOOGLE_YOUTUBE' => 'Google / YouTube', 'CONTEUDO_LENTO' => 'Conteúdo lento', 'MTR_ICMP' => 'Caminho MTR · ICMP', 'MTR_TCP' => 'Caminho MTR · TCP/443', 'CAMINHO_TRACEROUTE' => 'Caminho · traceroute ICMP', 'CONECTIVIDADE_BASICA' => 'Conectividade básica · ICMP', 'PORTA_SERVICO' => 'Porta de serviço · TCP/443'];

    public const SKILLS = ['agent_info' => ['agent_info', 'Informações do Agent', 5], 'dns_lookup' => ['dns_lookup:v1', 'DNS', 8], 'tcp_connect' => ['tcp_connect:v1', 'TCP 443', 5], 'http_probe' => ['http_probe:v1', 'HTTPS', 8], 'icmp_ping' => ['icmp_ping:v1', 'Latência', 15], 'traceroute' => ['traceroute:v1', 'Rota', 15], 'mtr_path' => ['mtr_path_analysis:v1', 'Caminho MTR', 20]];

    public function ensure(): void
    {
        $tenant = app(TenantContext::class)->tenantId;
        abort_unless($tenant, 403);
        DB::transaction(function () use ($tenant) {
            DB::select('SELECT pg_advisory_xact_lock(7713, ?::integer)', [$tenant]);
            foreach (self::SKILLS as $key => [$cap,$name,$timeout]) {
                if (! Skill::where('key', $key)->exists()) {
                    $s = new Skill;
                    $s->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'key' => $key, 'display_name' => $name, 'capability' => $cap, 'timeout' => $timeout, 'enabled' => true, 'safe' => true, 'version' => 1, 'input_schema' => $key === 'mtr_path' ? MtrPath::inputSchema() : ['type' => 'object', 'additionalProperties' => false, 'required' => $key === 'agent_info' ? [] : ['target'], 'properties' => ['target' => ['type' => 'string', 'maxLength' => 253], 'record_type' => ['enum' => ['A', 'AAAA']], 'transport' => ['enum' => ['udp', 'tcp']], 'resolver' => ['type' => 'string', 'format' => 'ip'], 'port' => ['const' => 443], 'url' => ['type' => 'string', 'maxLength' => 512]]], 'output_schema' => $this->outputSchema($cap)])->save();
                    app(Audit::class)->record('skill.enabled', tenantId: $tenant, entity: 'skill', metadata: ['skill_id' => $s->id]);
                }
            }
            $info = Skill::where('key', 'agent_info')->first();
            $schema = $info?->output_schema ?? [];
            $version = $schema['properties']['data']['properties']['version'] ?? [];
            $expected = ['enum' => Protocol::VERSIONS];
            $known = ($version['const'] ?? null) === '0.5.2' || isset($version['enum']) && is_array($version['enum']) && array_diff($version['enum'], Protocol::VERSIONS) === [];
            if ($info && $version !== $expected && $known) {
                $schema['properties']['data']['properties']['version'] = $expected;
                $info->forceFill(['output_schema' => $schema, 'version' => $info->version + 1])->save();
                app(Audit::class)->record('skill.schema_updated', tenantId: $tenant, entity: 'skill', metadata: ['skill_id' => $info->id]);
            }
            // Preserve administrator enablement and timeouts while extending the canonical DNS contract.
            $dns = Skill::where('key', 'dns_lookup')->first();
            if ($dns && ! isset($dns->input_schema['properties']['transport'])) {
                $input = $dns->input_schema;
                $input['properties'] += ['transport' => ['enum' => ['udp', 'tcp']], 'resolver' => ['type' => 'string', 'format' => 'ip']];
                $dns->forceFill(['input_schema' => $input, 'output_schema' => $this->outputSchema('dns_lookup:v1'), 'version' => $dns->version + 1])->save();
                app(Audit::class)->record('skill.schema_updated', tenantId: $tenant, entity: 'skill', metadata: ['skill_id' => $dns->id]);
            }
            foreach (json_decode(file_get_contents(resource_path('operational-skills.json')), true, flags: JSON_THROW_ON_ERROR) as $definition) {
                if (! Skill::where('key', $definition['key'])->exists()) {
                    $s = new Skill;
                    $s->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'key' => $definition['key'], 'display_name' => $definition['title'], 'capability' => $definition['capability'], 'timeout' => 20, 'enabled' => false, 'safe' => true, 'version' => 1, 'input_schema' => $definition['input_schema'], 'output_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['ok'], 'properties' => ['ok' => ['const' => false], 'error' => ['const' => 'capability_unavailable']]]])->save();
                    app(Audit::class)->record('skill.disabled', tenantId: $tenant, entity: 'skill', metadata: ['skill_id' => $s->id]);
                }
            }
            foreach (self::PROFILES as $key => $name) {
                if (! Profile::where('key', $key)->exists()) {
                    $p = new Profile;
                    $p->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'key' => $key, 'display_name' => $name, 'description' => $key === 'GOOGLE_YOUTUBE' ? 'DNS e conectividade HTTPS ao alvo informado; não mede reprodução de vídeo.' : 'Diagnóstico limitado às evidências das etapas executadas.', 'version' => 1, 'enabled' => true, 'definition' => ['required_target' => true, 'slow_ms' => 2000, 'loss_percent' => 10, 'latency_ms' => 150, 'trace_on_failure' => true, 'optional_icmp' => true, 'mtr_threshold_version' => 1, 'mtr_min_cycles' => 5, 'mtr_loss_percent' => 0, 'mtr_rtt_increase_ms' => 50, 'mtr_rtt_ratio' => 2]])->save();
                    app(Audit::class)->record('profile.created', tenantId: $tenant, entity: 'profile', metadata: ['profile_id' => $p->id]);
                }
            }
        });
    }

    public function outputSchema(string $cap): array
    {
        if ($cap === 'mtr_path_analysis:v1') {
            return MtrPath::schema();
        }
        $number = ['type' => 'number', 'minimum' => 0, 'maximum' => 60000];
        $ip = ['type' => 'string', 'format' => 'ip'];
        $properties = match ($cap) {
            'dns_lookup:v1' => ['answers' => ['type' => 'array', 'maxItems' => 20, 'items' => $ip], 'record_type' => ['enum' => ['A', 'AAAA']], 'duration_ms' => $number],
            'tcp_connect:v1' => ['connected' => ['type' => 'boolean'], 'duration_ms' => $number],
            'http_probe:v1' => ['status' => ['type' => 'integer', 'minimum' => 100, 'maximum' => 599], 'tls' => ['type' => 'boolean'], 'resolved_ip' => $ip, 'duration_ms' => $number],
            'icmp_ping:v1' => ['sent' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 5], 'received' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 5], 'loss_percent' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100], 'duration_ms' => $number],
            'traceroute:v1' => ['hops' => ['type' => 'array', 'maxItems' => 5, 'items' => $ip], 'reached_destination' => ['type' => 'boolean'], 'duration_ms' => $number],
            default => ['version' => ['enum' => Protocol::VERSIONS], 'hostname' => ['type' => 'string', 'maxLength' => 253], 'python' => ['type' => 'string', 'maxLength' => 30], 'device_operations_frozen' => ['const' => true]]
        };
        $required = array_keys($properties);
        if ($cap === 'dns_lookup:v1') {
            $properties += ['transport' => ['enum' => ['udp', 'tcp']], 'resolver' => $ip, 'rcode' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 15], 'truncated' => ['type' => 'boolean']];
        }
        $properties['error'] = ['enum' => [null, ...Normalizer::ERRORS]];
        if ($cap === 'icmp_ping:v1') {
            foreach (['min_ms', 'avg_ms', 'max_ms'] as $k) {
                $properties[$k] = $number;
            }
        }

        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['ok'], 'properties' => ['ok' => ['type' => 'boolean'], 'error' => ['enum' => Normalizer::ERRORS], 'data' => ['type' => 'object', 'additionalProperties' => false, 'required' => $required, 'properties' => $properties]]];
    }
}
