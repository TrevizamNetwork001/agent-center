<?php

namespace App\Telegram;

use App\Models\ConversationMessage;
use App\Models\Tenant;
use App\Rag\Retrieval;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class Deliver implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $tenant, public string $outboundId) {}

    public function handle(Client $client): void
    {
        app(TenantContext::class)->apply($this->tenant);
        try {
            // Durable send claim before HTTP. A crash/timeout never automatically resends an ambiguous delivery.
            $claimed = DB::transaction(function () {
                $o = Outbound::whereKey($this->outboundId)->lockForUpdate()->first();
                if (! $o || $o->status !== 'PENDING' || $o->next_attempt_at->isFuture()) {
                    return false;
                }
                $o->forceFill(['status' => 'SENDING', 'attempts' => $o->attempts + 1])->save();

                return true;
            });
            if (! $claimed) {
                return;
            }
            DB::transaction(function () use ($client) {
                $o = Outbound::whereKey($this->outboundId)->lockForUpdate()->firstOrFail();
                $bot = Bot::whereKey($o->bot_id)->sharedLock()->firstOrFail();
                $allowed = Tenant::whereKey($bot->tenant_id)->where('status', 'active')->exists() && config('telegram.enabled') && $bot->enabled && $bot->generation === $o->generation;
                $body = $o->body_cipher ? Crypt::decryptString($o->body_cipher) : '';
                if ($o->link_id) {
                    $link = Link::whereKey($o->link_id)->lockForUpdate()->firstOrFail();
                    try {
                        $identity = app(Accounts::class)->identity($link);
                    } catch (\Throwable) {
                        $allowed = false;
                    }
                    if ($allowed && $o->message_id) {
                        $m = ConversationMessage::where('conversation_id', $o->conversation_id)->whereKey($o->message_id)->firstOrFail();
                        $body = mb_substr($m->body, 0, 2300);
                        $sources = [];
                        foreach ($m->metadata['rag_sources'] ?? [] as $sourceId) {
                            $source = app(Retrieval::class)->source($identity->user, $sourceId);
                            if (! $source) {
                                $allowed = false;
                                break;
                            }
                            $sources[] = 'Fonte: versão '.$source['version_number'].', trecho '.($source['position'] + 1).' — '.mb_substr($source['title'], 0, 80).' — '.route('rag.source', $sourceId);
                        }
                        if ($sources) {
                            $body .= "\n\n".implode("\n", $sources);
                        }
                        $body .= "\n\nHistórico: ".route('conversations.show', $o->conversation_id);
                    }
                }
                if (! $allowed) {
                    $o->forceFill(['status' => 'SUPPRESSED', 'body_cipher' => null, 'last_error' => 'ACCESS_OR_SOURCE_REVOKED'])->save();

                    return;
                }
                $r = $client->call($bot, 'sendMessage', ['chat_id' => $o->chat_id, 'text' => mb_substr(preg_replace('/\b[0-9]{5,20}:[A-Za-z0-9_-]{30,100}\b/', '[CREDENCIAL_REMOVIDA]', $body), 0, 4000), 'protect_content' => true, 'link_preview_options' => ['is_disabled' => true]]);
                $status = match ($r['status']) {
                    'OK' => 'SENT', 'RATE_LIMITED' => $o->attempts < 5 ? 'PENDING' : 'FAILED', 'DELIVERY_UNKNOWN' => 'UNKNOWN', default => 'FAILED'
                };
                $o->forceFill(['status' => $status, 'telegram_message_id' => $r['result']['message_id'] ?? null, 'last_error' => $r['status'] === 'OK' ? null : $r['status'], 'next_attempt_at' => now()->addSeconds($r['retry_after'] ?? 60), 'body_cipher' => $status === 'SENT' ? null : $o->body_cipher])->save();
                if ($status !== 'SENT') {
                    app(Accounts::class)->audit('telegram.delivery_failed');
                }
                if ($r['status'] === 'BOT_BLOCKED' && isset($link)) {
                    app(Accounts::class)->revoke($link, $identity->user);
                }
            });
        } catch (\Throwable) {
            // SENDING is converted to UNKNOWN by the recovery sweep, never blindly retried.
        } finally {
            app(TenantContext::class)->apply();
        }
    }
}
