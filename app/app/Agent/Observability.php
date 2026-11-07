<?php

namespace App\Agent;

use App\Models\Agent;
use Illuminate\Validation\Rule;

class Observability
{
    public const REASONS = ['POLICY_BASE_DENIED', 'ADMIN_CEILING_DENIED', 'MANAGED_POLICY_DENIED', 'CAPABILITY_UNAVAILABLE', 'HELPER_UNAVAILABLE', 'HELPER_UNHEALTHY', 'DEPENDENCY_MISSING', 'PERMISSION_MISSING', 'UNSUPPORTED_VERSION', 'DEVICE_OPERATIONS_FROZEN', 'POLICY_NOT_APPLIED', 'STATUS_UNKNOWN'];

    public static function rules(): array
    {
        $rules = [
            'observability' => ['sometimes', 'array:schema_version,collected_at,version,policy_revision,policy_digest,policy_applied_at,base,ceiling,managed,helper,capabilities,last_error', function ($attribute, $value, $fail) {
                if (strlen(json_encode($value)) > 16384) {
                    $fail('Relatório excede o limite.');
                }
            }],
            'observability.schema_version' => ['required_with:observability', 'integer', Rule::in([1])],
            'observability.collected_at' => ['required_with:observability', 'date_format:Y-m-d\TH:i:s.uP'],
            'observability.version' => ['sometimes', 'string', 'max:30', 'regex:/^[0-9]+\.[0-9]+\.[0-9]+$/D'],
            'observability.policy_revision' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'observability.policy_digest' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'observability.policy_applied_at' => ['nullable', 'date'],
            'observability.last_error' => ['nullable', Rule::in(self::REASONS)],
            'observability.helper' => ['nullable', 'array:name,version,installed,service_active,socket_available,authenticated,healthy'],
            'observability.helper.name' => ['sometimes', Rule::in(['mtr'])],
            'observability.helper.version' => ['nullable', 'string', 'max:30', 'regex:/^[0-9]+\.[0-9]+\.[0-9]+$/D'],
            'observability.capabilities' => ['sometimes', 'array', 'max:14'],
            'observability.capabilities.*' => ['array:name,installed,state,reason,permitted,healthy'],
            'observability.capabilities.*.name' => ['required', 'distinct', Rule::in(Protocol::CAPABILITIES)],
            'observability.capabilities.*.installed' => ['required', 'boolean'],
            'observability.capabilities.*.permitted' => ['nullable', 'boolean'],
            'observability.capabilities.*.healthy' => ['nullable', 'boolean'],
            'observability.capabilities.*.state' => ['required', Rule::in(['AVAILABLE', 'BLOCKED', 'UNAVAILABLE', 'UNKNOWN'])],
            'observability.capabilities.*.reason' => ['nullable', Rule::in(self::REASONS)],
            'permission_result' => ['sometimes', 'array:id,state,reason,layer,checked_at,revision,digest'],
            'permission_result.id' => ['required_with:permission_result', 'uuid'],
            'permission_result.state' => ['required_with:permission_result', Rule::in(['ALLOWED', 'DENIED', 'UNKNOWN'])],
            'permission_result.reason' => ['nullable', Rule::in(self::REASONS)],
            'permission_result.layer' => ['nullable', Rule::in(['base', 'ceiling', 'managed', 'helper', 'freeze'])],
            'permission_result.checked_at' => ['required_with:permission_result', 'date'],
            'permission_result.revision' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'permission_result.digest' => ['nullable', 'regex:/^[a-f0-9]{64}$/D'],
        ];
        foreach (['installed', 'service_active', 'socket_available', 'authenticated', 'healthy'] as $field) {
            $rules['observability.helper.'.$field] = ['nullable', 'boolean'];
        }
        foreach (['base', 'ceiling', 'managed'] as $layer) {
            $prefix = 'observability.'.$layer;
            $rules[$prefix] = ['nullable', 'array:allowed_networks,allowed_dns_names'.($layer === 'managed' ? ',revision,auto_public_targets' : '')];
            $rules[$prefix.'.allowed_networks'] = ['sometimes', 'array', 'max:256'];
            $rules[$prefix.'.allowed_networks.*'] = ['string', 'max:50', 'regex:/^[0-9a-fA-F:.]+\/[0-9]{1,3}$/D'];
            $rules[$prefix.'.allowed_dns_names'] = ['sometimes', 'array', 'max:256'];
            $rules[$prefix.'.allowed_dns_names.*'] = ['string', 'max:253', 'regex:/^[a-z0-9][a-z0-9.-]*$/D'];
        }
        $rules['observability.managed.revision'] = ['sometimes', 'integer', 'min:1', 'max:2147483647'];
        $rules['observability.managed.auto_public_targets'] = ['sometimes', 'boolean'];

        return $rules;
    }

    public static function managedDigest(Agent $agent): string
    {
        $settings = ManagedSettings::current($agent);
        $safe = array_intersect_key($settings, array_flip(['revision', 'allowed_networks', 'allowed_dns_names', 'auto_public_targets']));
        sort($safe['allowed_networks']);
        sort($safe['allowed_dns_names']);
        ksort($safe);

        return hash('sha256', json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function receive(Agent $agent, array $data): array
    {
        $meta = $agent->metadata ?? [];
        if (isset($data['observability'])) {
            $meta['observability'] = $data['observability'] + ['received_at' => now()->toIso8601String()];
        }
        $pending = $meta['permission_request'] ?? null;
        $result = $data['permission_result'] ?? null;
        if ($pending && $result && $pending['id'] === $result['id'] && now()->lt($pending['expires_at'])) {
            $valid = ($result['revision'] ?? null) === $pending['revision']
                && $pending['revision'] === ManagedSettings::current($agent)['revision']
                && hash_equals(self::managedDigest($agent), $result['digest'] ?? '')
                && hash_equals($pending['digest'], $result['digest'] ?? '');
            $meta['permission_result'] = $result + ['received_at' => now()->toIso8601String(), 'capability' => $pending['capability'], 'destination' => $pending['destination'], 'protocol' => $pending['protocol']];
            if (! $valid && $result['state'] === 'ALLOWED') {
                $meta['permission_result'] = [...$meta['permission_result'], 'state' => 'UNKNOWN', 'reason' => 'POLICY_NOT_APPLIED', 'layer' => 'managed'];
            }
            unset($meta['permission_request']);
        }

        return $meta;
    }

    public static function pending(Agent $agent): ?array
    {
        $pending = $agent->metadata['permission_request'] ?? null;

        return $pending && now()->lt($pending['expires_at']) ? $pending : null;
    }

    public static function view(Agent $agent): array
    {
        $report = $agent->metadata['observability'] ?? null;
        $fresh = $report && isset($report['received_at']) && now()->subSeconds(60)->lt($report['received_at'])
            && isset($report['collected_at']) && now()->subSeconds(60)->lt($report['collected_at']) && now()->addSeconds(5)->gt($report['collected_at'])
            && in_array($agent->version, Protocol::VERSIONS, true)
            && $agent->status === 'active' && ! $agent->revoked_at && $agent->last_heartbeat_at?->gt(now()->subSeconds(60));
        $policyApplied = $report && ($report['policy_revision'] ?? null) === ManagedSettings::current($agent)['revision']
            && hash_equals(self::managedDigest($agent), $report['policy_digest'] ?? '');
        $result = $agent->metadata['permission_result'] ?? null;
        if ($result && (! $fresh || ! $policyApplied || now()->subSeconds(60)->gte($result['received_at']) || ($result['revision'] ?? null) !== ManagedSettings::current($agent)['revision'] || ! hash_equals(self::managedDigest($agent), $result['digest'] ?? ''))) {
            $result = [...$result, 'state' => 'UNKNOWN', 'reason' => 'POLICY_NOT_APPLIED'];
        }

        return compact('report', 'fresh', 'policyApplied', 'result');
    }
}
