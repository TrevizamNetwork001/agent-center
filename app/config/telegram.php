<?php

return [
    'enabled' => env('TELEGRAM_ENABLED', false),
    'admin_association_enabled' => env('TELEGRAM_ADMIN_ASSOCIATION_ENABLED', false),
    'tenants' => array_filter(explode(',', env('TELEGRAM_TENANTS', '')), fn ($v) => ctype_digit($v) && (int) $v > 0),
    'confirmation_seconds' => 300,
];
