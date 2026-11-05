<?php

return ['key_file' => env('LLM_MASTER_KEY_FILE'), 'previous_key_files' => array_values(array_filter(explode(',', env('LLM_PREVIOUS_KEY_FILES', '')))), 'allowed_hosts' => array_values(array_filter(explode(',', env('LLM_ALLOWED_HOSTS', '')))), 'max_response_bytes' => 32768, 'max_request_bytes' => 48000];
