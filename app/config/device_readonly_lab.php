<?php

// Never consulted by operational Preflight, Agent tasks, or Lifecycle Manager.
return [
    'routeros_protocol' => false,
    'enabled' => (bool) env('DEVICE_READONLY_LAB_ENABLED', false),
    'tenants' => array_values(array_filter(array_map('intval', explode(',', (string) env('DEVICE_READONLY_LAB_TENANTS', ''))))),
];
