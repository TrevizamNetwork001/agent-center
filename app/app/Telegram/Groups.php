<?php

namespace App\Telegram;

use App\Conversation\Engine;
use App\Conversation\State;
use App\Models\Conversation;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class Groups
{
    public function issue(User $user, Bot $bot, string $mode, bool $shared): array
    {
        Gate::forUser($user)->authorize('manage-intelligence');
        abort_unless(config('telegram.enabled') && $bot->enabled && in_array($mode, ['private', 'group'], true) && ($mode !== 'group' || $shared), 422);
        $link = Link::where('bot_id', $bot->id)->where('user_id', $user->id)->where('status', 'ACTIVE')->firstOrFail();
        $code = Str::random(48);
        $group = new Group;
        $group->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $bot->tenant_id, 'bot_id' => $bot->id, 'bot_generation' => $bot->generation, 'generation' => (string) Str::uuid(), 'requested_by' => $user->id, 'requested_link_id' => $link->id, 'status' => 'CODE', 'code_hash' => app(Accounts::class)->hash($code), 'expires_at' => now()->addMinutes(10), 'reply_mode' => $mode, 'shared_ack' => $shared])->save();
        app(Accounts::class)->audit('telegram.group_code_created', $user);

        return [$group, $code];
    }

    public function claim(Bot $bot, Update $row, string $code): ?Group
    {
        try {
            return DB::transaction(function () use ($bot, $row, $code) {
                $group = Group::where('bot_id', $bot->id)->where('bot_generation', $bot->generation)->where('status', 'CODE')->where('code_hash', app(Accounts::class)->hash($code))->where('expires_at', '>', now())->lockForUpdate()->first();
                if (! $group) {
                    return null;
                }
                $link = Link::whereKey($group->requested_link_id)->where('telegram_user_id', $row->sender_id)->where('user_id', $group->requested_by)->where('status', 'ACTIVE')->first();
                if (! $link) {
                    return null;
                }
                try {
                    $identity = app(Accounts::class)->identity($link);
                    Gate::forUser($identity->user)->authorize('manage-intelligence');
                } catch (\Throwable) {
                    return null;
                }
                $group->forceFill(['status' => 'AWAITING_APPROVAL', 'chat_id' => $row->chat_id, 'chat_title' => $row->chat_title ?: 'Grupo da empresa', 'code_hash' => null])->save();
                app(Accounts::class)->audit('telegram.group_claimed', $identity->user);

                return $group;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        } finally {
            app(TenantContext::class)->apply($bot->tenant_id);
        }
    }

    public function approve(Group $group, User $user): void
    {
        Gate::forUser($user)->authorize('manage-intelligence');
        DB::transaction(function () use ($group, $user) {
            $bot = Bot::whereKey($group->bot_id)->lockForUpdate()->firstOrFail();
            $group = Group::whereKey($group->id)->lockForUpdate()->firstOrFail();
            abort_unless($group->requested_by === $user->id && $group->status === 'AWAITING_APPROVAL' && $group->expires_at->isFuture() && $group->bot_generation === $bot->generation && $bot->enabled, 403);
            $link = Link::whereKey($group->requested_link_id)->where('status', 'ACTIVE')->where('user_id', $user->id)->firstOrFail();
            $this->authorizeMember($bot, $link, $user);
            $bot->forceFill(['restrict_members' => true])->save();
            $group->forceFill(['status' => 'ACTIVE', 'verified_at' => now()])->save();
            app(Accounts::class)->audit('telegram.group_verified', $user);
        });
    }

    public function authorizeMember(Bot $bot, Link $link, User $user): Member
    {
        Gate::forUser($user)->authorize('manage-intelligence');
        abort_unless($link->bot_id === $bot->id && $link->status === 'ACTIVE' && $link->tenant_id === $bot->tenant_id, 403);
        try {
            app(Accounts::class)->identity($link);
        } finally {
            app(TenantContext::class)->forUser($user, $bot->tenant_id);
        }

        return DB::transaction(function () use ($bot, $link, $user) {
            DB::select('SELECT pg_advisory_xact_lock(7824, ?::integer)', [(int) sprintf('%u', crc32($bot->id.':'.$link->id)) % 2147483647]);
            $member = Member::where('bot_id', $bot->id)->where('link_id', $link->id)->lockForUpdate()->first() ?? new Member;
            $member->forceFill(['bot_id' => $bot->id, 'link_id' => $link->id, 'id' => $member->id ?: (string) Str::uuid(), 'tenant_id' => $bot->tenant_id, 'status' => 'ACTIVE', 'approved_by' => $user->id, 'approved_at' => now(), 'revoked_at' => null])->save();
            app(Accounts::class)->audit('telegram.member_authorized', $user);

            return $member;
        });
    }

    public function revokeMember(Member $member, User $user): void
    {
        Gate::forUser($user)->authorize('manage-intelligence');
        DB::transaction(function () use ($member, $user) {
            $member = Member::whereKey($member->id)->lockForUpdate()->firstOrFail();
            $member->forceFill(['status' => 'REVOKED', 'revoked_at' => now()])->save();
            foreach (GroupContext::where('link_id', $member->link_id)->get() as $context) {
                $this->invalidate($context);
            }
            $link = Link::findOrFail($member->link_id);
            if ($link->conversation_id && ($conversation = Conversation::find($link->conversation_id)) && $conversation->state === 'WAITING_FOR_CONFIRMATION') {
                app(Engine::class)->transition($conversation, State::Cancelled, 'member_revoked');
                $conversation->save();
            }
            Outbound::where('link_id', $member->link_id)->where('status', 'PENDING')->update(['status' => 'SUPPRESSED', 'body_cipher' => null, 'last_error' => 'MEMBER_REVOKED']);
            app(Accounts::class)->audit('telegram.member_revoked', $user);
        });
    }

    public function revoke(Group $group, User $user): void
    {
        Gate::forUser($user)->authorize('manage-intelligence');
        DB::transaction(function () use ($group, $user) {
            $group = Group::whereKey($group->id)->lockForUpdate()->firstOrFail();
            foreach (GroupContext::where('group_id', $group->id)->get() as $context) {
                $this->invalidate($context);
            }
            $group->forceFill(['status' => 'REVOKED', 'generation' => (string) Str::uuid(), 'code_hash' => null, 'revoked_at' => now()])->save();
            Outbound::where('group_id', $group->id)->where('status', 'PENDING')->update(['status' => 'SUPPRESSED', 'body_cipher' => null, 'last_error' => 'GROUP_REVOKED']);
            app(Accounts::class)->audit('telegram.group_revoked', $user);
        });
    }

    private function invalidate(GroupContext $context): void
    {
        if ($context->conversation_id && ($conversation = Conversation::find($context->conversation_id)) && $conversation->state === 'WAITING_FOR_CONFIRMATION') {
            app(Engine::class)->transition($conversation, State::Cancelled, 'group_access_revoked');
            $conversation->save();
        }
    }
}
