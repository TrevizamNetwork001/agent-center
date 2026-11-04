<?php

namespace App\Agent;

class Protocol
{
    public const CAPABILITIES = ['agent_info', 'icmp_ping:v1', 'dns_lookup:v1', 'tcp_connect:v1', 'traceroute:v1', 'http_probe:v1'];

    public static function policy(string $id, bool $tasks = false): array
    {
        return ['schema_version' => 1, 'agent_id' => $id, 'device_operations_frozen' => true, 'tasks_enabled' => $tasks, 'task_protocol' => 1, 'allowed_capabilities' => self::CAPABILITIES, 'heartbeat_interval' => 20, 'poll_interval' => 5];
    }
}
