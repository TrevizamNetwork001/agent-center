<?php

namespace App\Telegram;

use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Models\Conversation;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class Sweep
{
    public function run(int $tenant): void
    {
        abort_unless($tenant > 0 && config('telegram.enabled'), 403);
        app(TenantContext::class)->apply($tenant);
        try {
            Outbound::where('status', 'SENDING')->where('updated_at', '<', now()->subMinutes(2))->update(['status' => 'UNKNOWN', 'last_error' => 'WORKER_INTERRUPTED']);
            foreach (Link::whereIn('status', ['CODE', 'AWAITING_APPROVAL'])->where('expires_at', '<', now())->get() as $link) {
                $link->forceFill(['status' => 'REVOKED', 'code_hash' => null, 'revoked_at' => now()])->save();
            }
            Group::whereIn('status', ['CODE', 'AWAITING_APPROVAL'])->where('expires_at', '<', now())->update(['status' => 'REVOKED', 'code_hash' => null, 'revoked_at' => now()]);
            // Revoke inbound body retention even for delayed/dead workers. History stays in the engine.
            Update::where('created_at', '<', now()->subMinutes(15))->whereNotNull('payload_cipher')->update(['status' => 'IGNORED', 'payload_cipher' => null, 'last_error' => 'EXPIRED']);
            Outbound::where('created_at', '<', now()->subDays(7))->whereNotNull('body_cipher')->update(['body_cipher' => null, 'status' => 'EXPIRED']);
            foreach (Update::where('status', 'PENDING')->where('next_attempt_at', '<=', now())->orderBy('update_id')->limit(100)->get() as $row) {
                ProcessUpdate::dispatch($tenant, $row->id);
            }
            foreach (Link::where('status', 'ACTIVE')->whereNotNull('conversation_id')->limit(1000)->get() as $item) {
                try {
                    DB::transaction(function () use ($item) {
                        $link = Link::whereKey($item->id)->lockForUpdate()->firstOrFail();
                        $bot = Bot::findOrFail($link->bot_id);
                        if (! $bot->enabled) {
                            return;
                        }
                        $identity = app(Accounts::class)->identity($link);
                        $c = app(Engine::class)->sync($identity, Conversation::findOrFail($link->conversation_id));
                        app(Runner::class)->collect($bot, $link, $c);
                    });
                } catch (\Throwable) { /* Permissions are checked again for every delivery; no private exception logs. */
                } finally {
                    app(TenantContext::class)->apply($tenant);
                }
            }
            foreach (GroupContext::whereNotNull('conversation_id')->limit(1000)->get() as $item) {
                try {
                    DB::transaction(function () use ($item) {
                        $context = GroupContext::whereKey($item->id)->lockForUpdate()->firstOrFail();
                        $group = Group::whereKey($context->group_id)->sharedLock()->firstOrFail();
                        $link = Link::whereKey($context->link_id)->lockForUpdate()->firstOrFail();
                        $bot = Bot::findOrFail($group->bot_id);
                        if ($group->status !== 'ACTIVE' || $group->generation !== $context->generation || $group->bot_generation !== $bot->generation || ! app(Access::class)->allows($link, $group)) {
                            return;
                        }
                        $base = app(Accounts::class)->identity($link);
                        $identity = new Identity($base->tenantId, $base->user, $base->channel, $base->contactId, $context->id);
                        $conversation = app(Engine::class)->sync($identity, Conversation::findOrFail($context->conversation_id));
                        app(Runner::class)->collect($bot, $link, $conversation, $group, $context);
                    });
                } catch (\Throwable) {
                    // Revalidation denies revoked groups, members, sources and users without logging private data.
                } finally {
                    app(TenantContext::class)->apply($tenant);
                }
            }
            foreach (Outbound::where('status', 'PENDING')->where('next_attempt_at', '<=', now())->limit(100)->get() as $out) {
                Deliver::dispatch($tenant, $out->id);
            }
        } finally {
            app(TenantContext::class)->apply();
        }
    }
}
