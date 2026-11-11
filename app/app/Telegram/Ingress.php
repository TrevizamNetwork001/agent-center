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
            $type = $m['chat']['type'] ?? '';
            if (! in_array($type, ['private', 'group', 'supergroup'], true) || ($m['from']['is_bot'] ?? true) !== false || isset($m['forward_origin']) || isset($m['via_bot']) || isset($m['sender_chat'])) {
                return ['ok' => true, 'ignored' => true];
            }
            foreach ([$m['from']['id'] ?? null, $m['message_id'] ?? null, $m['date'] ?? null] as $id) {
                abort_unless(is_int($id) && $id > 0, 422);
            }
            abort_unless(is_int($m['chat']['id'] ?? null) && ($type === 'private' ? $m['chat']['id'] === $m['from']['id'] : $m['chat']['id'] < 0), 422);
            $thread = $m['message_thread_id'] ?? 0;
            abort_unless(is_int($thread) && $thread >= 0 && $thread <= 2147483647, 422);
            if (! is_string($m['text'] ?? null) || mb_strlen($m['text']) > 4000) {
                return ['ok' => true, 'ignored' => true];
            }
            if ($m['date'] < now()->timestamp - 900 || $m['date'] > now()->timestamp + 60) {
                return ['ok' => true, 'ignored' => true];
            }
            $text = $m['text'];
            $group = null;
            if ($type !== 'private') {
                $group = Group::where('bot_id', $bot->id)->where('bot_generation', $bot->generation)->where('chat_id', $m['chat']['id'])->where('status', 'ACTIVE')->first();
                if (preg_match('~^/([a-z_]+)(?:@([a-z0-9_]+))?(?:\s+(.+))?$~Dis', $text, $parts)) {
                    if (isset($parts[2]) && $parts[2] !== '' && strcasecmp($parts[2], $bot->username) !== 0) {
                        return ['ok' => true, 'ignored' => true];
                    }
                    $command = strtolower($parts[1]);
                    $argument = $parts[3] ?? '';
                    $text = in_array($command, ['perguntar', 'ask'], true) ? $argument : '/'.$command.($argument !== '' ? ' '.$argument : '');
                } elseif (preg_match('~^@'.preg_quote($bot->username, '~').'[\s,:]+(.+)$~Dis', $text, $parts)) {
                    $text = $parts[1];
                } elseif ((string) ($m['reply_to_message']['from']['id'] ?? '') !== (string) $bot->telegram_bot_id || ($m['reply_to_message']['from']['is_bot'] ?? false) !== true) {
                    return ['ok' => true, 'ignored' => true];
                }
                if (! $group && ! preg_match('~^/vincular_grupo [A-Za-z0-9]{48}$~D', $text)) {
                    return ['ok' => true, 'ignored' => true];
                }
            }
            $digestParts = [$m['from']['id'], $m['message_id'], $m['text']];
            if ($type !== 'private') {
                $digestParts[] = [$m['chat']['id'], $type, $thread];
            }
            $digest = hash_hmac('sha256', json_encode($digestParts, JSON_THROW_ON_ERROR), config('app.key'));

            return DB::transaction(function () use ($bot, $m, $p, $digest, $text, $type, $group, $thread) {
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
                $row->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $bot->tenant_id, 'bot_id' => $bot->id, 'generation' => $bot->generation, 'update_id' => $p['update_id'], 'chat_id' => $m['chat']['id'], 'message_id' => $m['message_id'], 'sender_id' => $m['from']['id'], 'chat_type' => $type, 'chat_title' => $type === 'private' ? null : mb_substr(preg_replace('/[\p{C}]/u', '', (string) ($m['chat']['title'] ?? 'Grupo da empresa')), 0, 150), 'group_id' => $group?->id, 'group_generation' => $group?->generation, 'thread_id' => $thread, 'message_date' => date('c', $m['date']), 'digest' => $digest, 'payload_cipher' => Crypt::encryptString(preg_replace('/\b[0-9]{5,20}:[A-Za-z0-9_-]{30,100}\b/', '[CREDENCIAL_REMOVIDA]', $text)), 'next_attempt_at' => now()])->save();
                ProcessUpdate::dispatch($bot->tenant_id, $row->id)->afterCommit();

                return ['ok' => true];
            });
        } finally {
            app(TenantContext::class)->apply();
        }
    }
}
