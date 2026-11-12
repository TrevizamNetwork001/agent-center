<?php

return [
    'adaptive_tone' => 'AUTO',
    'max_messages' => 500,
    'idle_enabled' => env('CONVERSATION_IDLE_ENABLED', false),
    'idle_minutes' => 15,
    'idle_tenants' => array_values(array_unique(array_map('intval', array_filter(explode(',', (string) env('CONVERSATION_IDLE_TENANTS', '')), fn ($id) => ctype_digit($id) && (int) $id > 0)))),
];
