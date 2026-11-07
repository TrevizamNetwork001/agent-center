<?php

return [
    // Configured by the infrastructure administrator, never by tenant input.
    // Example: {"agent-uuid":["192.0.2.10"]}. Literal IPs only; no DNS/URLs.
    'host_fingerprints' => json_decode(env('AGENT_MAINTENANCE_HOST_FINGERPRINTS', '{}'), true) ?: [],
    'targets' => json_decode(env('AGENT_MAINTENANCE_TARGETS', '{}'), true) ?: [],
];
