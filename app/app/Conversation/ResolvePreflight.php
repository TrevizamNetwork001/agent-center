<?php

namespace App\Conversation;

use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Telegram\Accounts;
use App\Telegram\Bot;
use App\Telegram\Group;
use App\Telegram\GroupContext;
use App\Telegram\Link;
use App\Telegram\Runner;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Existing conversation synchronization and durable Telegram outbox, scoped to one conversation. */
class ResolvePreflight implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $backoff = 2;

    public function __construct(public int $tenant, public string $conversationId)
    {
        // Deadlines require a durable delayed queue, including callers configured with sync.
        $this->onConnection('database');
    }

    public function handle(Engine $engine): void
    {
        $ctx = app(TenantContext::class);
        $ctx->apply($this->tenant);
        try {
            if (! Tenant::whereKey($this->tenant)->where('status', 'active')->exists()) {
                return;
            }
            DB::transaction(function () use ($ctx, $engine) {
                $c = Conversation::find($this->conversationId);
                if (! $c || $c->status !== 'OPEN' || ! isset($c->context_json['preflight_watch'])) {
                    return;
                }
                if ($c->channel === 'telegram') {
                    // Same transport lock order as the existing sweep. Never rebind a switched chat.
                    $scope = $c->telegram_context_id ? GroupContext::whereKey($c->telegram_context_id)->lockForUpdate()->firstOrFail() : null;
                    $group = $scope ? Group::whereKey($scope->group_id)->sharedLock()->firstOrFail() : null;
                    $link = Link::whereKey($c->contact_id)->lockForUpdate()->firstOrFail();
                    if (($scope ?? $link)->conversation_id !== $c->id) {
                        return;
                    }
                    $base = app(Accounts::class)->identity($link);
                    $identity = new Identity($base->tenantId, $base->user, 'telegram', $link->id, $scope?->id);
                    Gate::forUser($identity->user)->authorize('send', $c);
                    $c = $engine->sync($identity, $c);
                    app(Runner::class)->collect(Bot::findOrFail($link->bot_id), $link, $c, $group, $scope);
                } else {
                    $ctx->apply(userId: $c->user_id);
                    $user = User::findOrFail($c->user_id);
                    abort_unless($user->tenant_id === $this->tenant || $user->tenant_id === null, 403);
                    $ctx->forUser($user, $this->tenant);
                    Gate::forUser($user)->authorize('send', $c);
                    $engine->sync(Identity::web($user), $c);
                }
            });
        } catch (AuthorizationException|HttpExceptionInterface $e) {
            // Revoked identities cannot receive private notifications. Other failures retry normally.
            if (! $e instanceof AuthorizationException && ! in_array($e->getStatusCode(), [403, 404], true)) {
                throw $e;
            }
        } finally {
            $ctx->apply();
        }
    }
}
