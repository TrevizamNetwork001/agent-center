<?php

namespace App\Telegram;

use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

// Fixed origin and method allowlist. Never propagate transport exceptions: URLs contain credentials.
class Client
{
    public function call(Bot $bot, string $method, array $data = []): array
    {
        $transportError = null;
        $timeoutBeforeConnect = false;
        if (! in_array($method, ['getMe', 'setWebhook', 'getWebhookInfo', 'deleteWebhook', 'sendMessage', 'editMessageText'], true)) {
            throw new \InvalidArgumentException('Unsupported Telegram method.');
        }
        try {
            $token = Crypt::decryptString($bot->token_cipher);
            if (! preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{30,100}$/D', $token)) {
                return ['status' => 'CREDENTIAL_INVALID'];
            }
            $r = Http::withoutRedirecting()->connectTimeout(3)->timeout(10)->withOptions([
                'verify' => true, 'force_ip_resolve' => 'v4',
                'on_stats' => function (TransferStats $stats) use (&$transportError, &$timeoutBeforeConnect): void {
                    $transportError = $stats->getHandlerErrorData();
                    $timeoutBeforeConnect = $transportError === 28;
                    foreach (['http_code', 'request_size', 'size_upload', 'connect_time', 'pretransfer_time'] as $field) {
                        $timeoutBeforeConnect = $timeoutBeforeConnect && in_array($stats->getHandlerStat($field), [0, 0.0], true);
                    }
                },
            ])->post('https://api.telegram.org/bot'.$token.'/'.$method, $data);
            $j = strlen($r->body()) <= 65536 ? $r->json() : null;
            if ($r->successful() && is_array($j) && ($j['ok'] ?? false) === true) {
                $result = $j['result'] ?? [];

                // Return only known fields; never raw API errors or request URLs.
                return ['status' => 'OK', 'result' => is_array($result) ? array_intersect_key($result, array_flip(['id', 'is_bot', 'username', 'message_id', 'pending_update_count', 'last_error_date', 'url'])) : []];
            }
            if ($r->status() === 429) {
                return ['status' => 'RATE_LIMITED', 'retry_after' => min(3600, max(1, (int) ($j['parameters']['retry_after'] ?? 60)))];
            }
            if ($method === 'editMessageText' && $r->status() === 400 && is_string($j['description'] ?? null)) {
                if (str_starts_with($j['description'], 'Bad Request: message is not modified')) {
                    return ['status' => 'OK', 'result' => ['message_id' => $data['message_id'] ?? null]];
                }
                if (in_array($j['description'], ['Bad Request: message to edit not found', "Bad Request: message can't be edited", 'Bad Request: MESSAGE_ID_INVALID'], true)) {
                    return ['status' => 'EDIT_UNAVAILABLE'];
                }
            }

            return ['status' => match ($r->status()) {
                401 => 'CREDENTIAL_INVALID', 403 => 'BOT_BLOCKED', 400 => 'INVALID_REQUEST', default => 'DELIVERY_UNKNOWN'
            }];
        } catch (ConnectionException) {
            // Retry only proven DNS/connect failures or a timeout with complete
            // evidence of no connection and no request. Missing stats fail closed.
            if (in_array($transportError, [6, 7], true) || $timeoutBeforeConnect) {
                return ['status' => 'TRANSPORT_UNAVAILABLE'];
            }

            return ['status' => 'DELIVERY_UNKNOWN'];
        } catch (\Throwable) {
            return ['status' => 'DELIVERY_UNKNOWN'];
        }
    }
}
