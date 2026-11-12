<?php

return [
    'admin_demo_enabled' => (bool) env('DEVICE_ANALYSIS_ADMIN_DEMO_ENABLED', false),
    'admin_demo_tenants' => array_values(array_filter(array_map('intval', explode(',', (string) env('DEVICE_ANALYSIS_ADMIN_DEMO_TENANTS', ''))))),
    'lab_enabled' => (bool) env('DEVICE_ANALYSIS_LAB_ENABLED', false),
    'lab_tenants' => array_values(array_filter(array_map('intval', explode(',', (string) env('DEVICE_ANALYSIS_LAB_TENANTS', ''))))),
];
