<?php

namespace App\Conversation;

use App\Enums\Role;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class IdleSweep
{
    public function run(int $tenant): int
    {
        abort_unless($tenant > 0, 403);
        if (! config('conversation.idle_enabled') || ! in_array($tenant, config('conversation.idle_tenants', []), true)) {
            return 0;
        }
        $context = app(TenantContext::class);
        $closed = 0;
        $context->apply($tenant);
        try {
            if (! Tenant::whereKey($tenant)->where('status', 'active')->exists()) {
                return 0;
            }
            // Telegram closes inside its existing transport transaction, with link/context locks first.
            $rows = Conversation::where('channel', 'web')->where('status', 'OPEN')
                ->where('last_activity_at', '<=', now()->subMinutes(config('conversation.idle_minutes')))
                ->orderBy('last_activity_at')->limit(1000)->get();
            foreach ($rows as $c) {
                try {
                    // Native users self-visibility also supports platform admins whose tenant_id is NULL.
                    $context->apply(userId: $c->user_id);
                    $user = User::find($c->user_id);
                    if (! $user || ($user->tenant_id !== $tenant && ! ($user->tenant_id === null && $user->role === Role::PlatformAdmin))) {
                        continue;
                    }
                    $context->forUser($user, $tenant);
                    $result = app(Engine::class)->closeIdle(Identity::web($user), $c);
                    $closed += $result->status === 'CLOSED' ? 1 : 0;
                } catch (AuthorizationException|HttpExceptionInterface) {
                    // Revoked access cannot generate a user-visible notification.
                } finally {
                    $context->apply($tenant);
                }
            }
        } finally {
            $context->apply();
        }

        return $closed;
    }
}
