<?php

namespace App\Telegram;

use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class Ingress
{
    public function accept(Request $r, int $tenant, string $botId): array
    {
        abort_unless(config('telegram.enabled'), 404);
        app(TenantContext::class)->apply($tenant);
        try {
            $bot = Bot::whereKey($botId)->where('enabled', true)->firstOrFail();
            $secret = $r->header('X-Telegram-Bot-Api-Secret-Token', '');
            abort_unless(strlen($secret) <= 256 && hash_equals(Crypt::decryptString($bot->secret_cipher), $secret), 401);
            abort_unless(strlen($r->getContent()) <= 32768 && $r->isJson(), 422);
            $p = $r->json()->all();
            abort_unless(is_int($p['update_id'] ?? null) && $p['update_id'] >= 0, 422);
            if (! isset($p['message'])) {
                return ['ok' => true, 'ignored' => true];
            }
            $m = $p['message'];
            abort_unless(is_array($m), 422);
            if (($m['chat']['type'] ?? '') !== 'private' || ($m['from']['is_bot'] ?? true) !== false || isset($m['forward_origin']) || isset($m['via_bot']) || isset($m['sender_chat'])) {
                return ['ok' => true, 'ignored' => true];
            }
            foreach ([$m['from']['id'] ?? null, $m['chat']['id'] ?? null, $m['message_id'] ?? null, $m['date'] ?? null] as $id) {
                abort_unless(is_int($id) && $id > 0, 422);
            }
            abort_unless($m['chat']['id'] === $m['from']['id'], 422);
            if (! is_string($m['text'] ?? null) || mb_strlen($m['text']) > 4000) {
                return ['ok' => true, 'ignored' => true];
            }
            if ($m['date'] < now()->timestamp - 900 || $m['date'] > now()->timestamp + 60) {
                return ['ok' => true, 'ignored' => true];
            }
            $digest = hash_hmac('sha256', json_encode([$m['from']['id'], $m['message_id'], $m['text']], JSON_THROW_ON_ERROR), config('app.key'));

            return DB::transaction(function () use ($bot, $m, $p, $digest) {
                DB::select('SELECT pg_advisory_xact_lock(7822, ?::integer)', [(int) sprintf('%u', crc32($bot->id)) % 2147483647]);
                $old = Update::where('bot_id', $bot->id)->where('generation', $bot->generation)->where(fn ($q) => $q->where('update_id', $p['update_id'])->orWhere(fn ($q) => $q->where('chat_id', $m['chat']['id'])->where('message_id', $m['message_id'])))->first();
                if ($old) {
                    abort_unless(hash_equals($old->digest, $digest), 409);

                    return ['ok' => true, 'duplicate' => true];
                }
                foreach (['telegram:bot:'.$bot->id => 120, 'telegram:sender:'.$bot->id.':'.$m['from']['id'] => 20] as $key => $limit) {
                    abort_if(RateLimiter::tooManyAttempts($key, $limit), 429);
                    RateLimiter::hit($key, 60);
                }
                $row = new Update;
                $row->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $bot->tenant_id, 'bot_id' => $bot->id, 'generation' => $bot->generation, 'update_id' => $p['update_id'], 'chat_id' => $m['chat']['id'], 'message_id' => $m['message_id'], 'sender_id' => $m['from']['id'], 'message_date' => date('c', $m['date']), 'digest' => $digest, 'payload_cipher' => Crypt::encryptString(preg_replace('/\b[0-9]{5,20}:[A-Za-z0-9_-]{30,100}\b/', '[CREDENCIAL_REMOVIDA]', $m['text'])), 'next_attempt_at' => now()])->save();
                ProcessUpdate::dispatch($bot->tenant_id, $row->id)->afterCommit();

                return ['ok' => true];
            });
        } finally {
            app(TenantContext::class)->apply();
        }
    }
}
