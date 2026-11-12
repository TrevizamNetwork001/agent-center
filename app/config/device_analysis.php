<?php

return [
    'lab_enabled' => (bool) env('DEVICE_ANALYSIS_LAB_ENABLED', false),
    'lab_tenants' => array_values(array_filter(array_map('intval', explode(',', (string) env('DEVICE_ANALYSIS_LAB_TENANTS', ''))))),
];
