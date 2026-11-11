<?php

namespace App\Telegram;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

// Fixed origin and method allowlist. Never propagate transport exceptions: URLs contain credentials.
class Client
{
    public function call(Bot $bot, string $method, array $data = []): array
    {
        if (! in_array($method, ['getMe', 'setWebhook', 'getWebhookInfo', 'deleteWebhook', 'sendMessage'], true)) {
            throw new \InvalidArgumentException('Unsupported Telegram method.');
        }
        try {
            $token = Crypt::decryptString($bot->token_cipher);
            if (! preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{30,100}$/D', $token)) {
                return ['status' => 'CREDENTIAL_INVALID'];
            }
            $r = Http::withoutRedirecting()->connectTimeout(3)->timeout(10)->withOptions(['verify' => true])->post('https://api.telegram.org/bot'.$token.'/'.$method, $data);
            $j = strlen($r->body()) <= 65536 ? $r->json() : null;
            if ($r->successful() && is_array($j) && ($j['ok'] ?? false) === true) {
                $result = $j['result'] ?? [];

                // Return only known fields; never raw API errors or request URLs.
                return ['status' => 'OK', 'result' => is_array($result) ? array_intersect_key($result, array_flip(['id', 'is_bot', 'username', 'message_id', 'pending_update_count', 'last_error_date', 'url'])) : []];
            }
            if ($r->status() === 429) {
                return ['status' => 'RATE_LIMITED', 'retry_after' => min(3600, max(1, (int) ($j['parameters']['retry_after'] ?? 60)))];
            }

            return ['status' => match ($r->status()) {
                401 => 'CREDENTIAL_INVALID', 403 => 'BOT_BLOCKED', 400 => 'INVALID_REQUEST', default => 'DELIVERY_UNKNOWN'
            }];
        } catch (\Throwable) {
            return ['status' => 'DELIVERY_UNKNOWN'];
        }
    }
}
