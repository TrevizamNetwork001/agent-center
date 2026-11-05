<?php

namespace App\Agent;

use App\Models\Agent;
use Carbon\CarbonImmutable;

class Protocol
{
    public const VERSIONS = ['0.5.2', '0.5.3', '0.5.4', '0.5.5', '0.5.6', '0.5.7', '0.5.8', '0.5.9', '0.6.0', '0.6.1', '0.6.2'];

    public const INSTALLER_VERSION = '0.6.1';

    public const CAPABILITIES = ['agent_info', 'icmp_ping:v1', 'dns_lookup:v1', 'tcp_connect:v1', 'traceroute:v1', 'http_probe:v1', 'mtr_path_analysis:v1'];

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
        return ($settings ? ['agent_settings' => $settings] : []) + ['schema_version' => 1, 'agent_id' => $id, 'device_operations_frozen' => true, 'tasks_enabled' => $tasks, 'task_protocol' => 1, 'allowed_capabilities' => self::CAPABILITIES, 'heartbeat_interval' => 20, 'poll_interval' => 5];
    }
}
