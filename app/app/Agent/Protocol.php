<?php

namespace App\Agent;

use App\Models\Agent;
use App\Noc\ReadOnlyLab\RouterOs7\Integration;
use Carbon\CarbonImmutable;

class Protocol
{
    public const VERSIONS = ['0.6.6', '0.6.5', '0.5.2', '0.5.3', '0.5.4', '0.5.5', '0.5.6', '0.5.7', '0.5.8', '0.5.9', '0.6.0', '0.6.1', '0.6.2', '0.6.3', '0.6.4'];

    public const MTR_CONTRACT_VERSION = '2026.11.06.1';

    public const INSTALLER_VERSION = '0.6.1';

    public const CAPABILITIES = ['agent_info', 'icmp_ping:v1', 'dns_lookup:v1', 'tcp_connect:v1', 'traceroute:v1', 'http_probe:v1', 'mtr_path_analysis:v1'];

    public static function allowedCapabilities(): array
    {
        return Integration::enabled() ? [...self::CAPABILITIES, Integration::CAPABILITY] : self::CAPABILITIES;
    }

    public static function mtrAvailable(Agent $agent): bool
    {
        return $agent->status === 'active' && ! $agent->revoked_at
            && in_array($agent->version, self::VERSIONS, true)
            && version_compare($agent->version ?? '0.0.0', '0.6.2', '>=')
            && in_array('mtr_path_analysis:v1', $agent->capabilities ?? [], true)
            && is_string($agent->metadata['mtr_capability_confirmed_at'] ?? null);
    }

    public static function executionCapabilities(Agent $agent): array
    {
        return array_values(array_filter($agent->capabilities ?? [], fn ($cap) => in_array($cap, self::allowedCapabilities(), true) && ($cap !== 'mtr_path_analysis:v1' || self::mtrAvailable($agent))));
    }

    public static function supportsDownloads(Agent $agent): bool
    {
        $updater = $agent->metadata['updater'] ?? [];

        return ($updater['protocol'] ?? null) === 1 && ($updater['download_protocol'] ?? null) === 1 && in_array($updater['version'] ?? null, self::VERSIONS, true);
    }

    public static function updaterReady(Agent $agent): bool
    {
        $updater = $agent->metadata['updater'] ?? [];
        if (! self::supportsDownloads($agent) || ! is_string($updater['last_poll_at'] ?? null)) {
            return false;
        }
        try {
            return CarbonImmutable::parse($updater['last_poll_at'])->gt(now()->subMinute());
        } catch (\Throwable) {
            return false;
        }
    }

    public static function policy(string $id, bool $tasks = false, ?array $settings = null): array
    {
        return ($settings ? ['agent_settings' => $settings] : []) + ['schema_version' => 1, 'agent_id' => $id, 'device_operations_frozen' => true, 'tasks_enabled' => $tasks, 'task_protocol' => 1, 'allowed_capabilities' => self::allowedCapabilities(), 'heartbeat_interval' => 20, 'poll_interval' => 5];
    }
}
