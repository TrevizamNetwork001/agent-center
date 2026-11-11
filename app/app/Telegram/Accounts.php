<?php

namespace App\Telegram;

use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Conversation\State;
use App\Models\Conversation;
use App\Models\Diagnostic;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class Accounts
{
    public function configure(User $user, string $token): Bot
    {
        Gate::forUser($user)->authorize('manage-intelligence');
        abort_unless(config('telegram.enabled') && app(TenantContext::class)->tenantId, 403);
        abort_unless(preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{30,100}$/D', $token), 422, 'Formato de credencial inválido.');
        $bot = new Bot;
        $bot->token_cipher = Crypt::encryptString($token);
        $me = app(Client::class)->call($bot, 'getMe');
        abort_unless($me['status'] === 'OK' && ($me['result']['is_bot'] ?? false) === true && preg_match('/^[A-Za-z0-9_]{5,64}$/D', $me['result']['username'] ?? ''), 422, 'Não foi possível validar a credencial do bot.');

        return DB::transaction(function () use ($user, $token, $me) {
            $tenant = app(TenantContext::class)->tenantId;
            DB::select('SELECT pg_advisory_xact_lock(7820, ?::integer)', [$tenant]);
            $bot = Bot::lockForUpdate()->first() ?? new Bot;
            if ($bot->exists) {
                foreach (Link::where('bot_id', $bot->id)->where('status', '!=', 'REVOKED')->lockForUpdate()->get() as $link) {
                    $this->revoke($link, $user);
                }
            }
            $bot->forceFill(['id' => $bot->id ?? (string) Str::uuid(), 'tenant_id' => $tenant, 'token_cipher' => Crypt::encryptString($token), 'token_fingerprint' => hash_hmac('sha256', $token, config('app.key')), 'secret_cipher' => Crypt::encryptString(Str::random(64)), 'username' => $me['result']['username'], 'telegram_bot_id' => $me['result']['id'], 'generation' => (string) Str::uuid(), 'enabled' => false, 'webhook_status' => 'NOT_REGISTERED', 'last_error' => null])->save();
            $this->audit('telegram.configured', $user);

            return $bot;
        });
    }

    public function webhookUrl(Bot $bot): string
    {
        $url = rtrim(config('app.url'), '/').'/api/telegram/'.$bot->tenant_id.'/'.$bot->id;
        abort_unless(parse_url($url, PHP_URL_SCHEME) === 'https' && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_QUERY), 422, 'APP_URL deve usar HTTPS.');

        return $url;
    }

    public function register(Bot $bot, User $user, bool $enabled): void
    {
        Gate::forUser($user)->authorize('manage-intelligence');
        abort_unless(config('telegram.enabled'), 403);
        DB::transaction(function () use ($bot, $user, $enabled) {
            $locked = Bot::whereKey($bot->id)->lockForUpdate()->firstOrFail();
            $r = app(Client::class)->call($locked, $enabled ? 'setWebhook' : 'deleteWebhook', $enabled ? ['url' => $this->webhookUrl($locked), 'secret_token' => Crypt::decryptString($locked->secret_cipher), 'allowed_updates' => ['message'], 'max_connections' => 1, 'drop_pending_updates' => false] : ['drop_pending_updates' => false]);
            $active = $enabled && $r['status'] === 'OK';
            if (! $active) {
                $locked->generation = (string) Str::uuid();
                foreach (Link::where('bot_id', $locked->id)->where('status', '!=', 'REVOKED')->lockForUpdate()->get() as $link) {
                    $this->revoke($link, $user);
                }
            }
            $locked->forceFill(['enabled' => $active, 'webhook_status' => $active ? 'REGISTERED' : 'DISABLED', 'last_error' => $r['status'] === 'OK' ? null : $r['status'], 'last_checked_at' => now()])->save();
            $this->audit('telegram.webhook_changed', $user);
        });
        $bot->refresh();
    }

    public function issue(User $user, Bot $bot): string
    {
        Gate::forUser($user)->authorize('create', Diagnostic::class);
        abort_unless(config('telegram.enabled') && $bot->enabled, 403);

        return DB::transaction(function () use ($user, $bot) {
            DB::select('SELECT pg_advisory_xact_lock(7821, ?::integer)', [$user->id]);
            foreach (Link::where('user_id', $user->id)->where('status', '!=', 'REVOKED')->lockForUpdate()->get() as $old) {
                $this->revoke($old, $user);
            }
            $code = Str::random(48);
            $link = new Link;
            $link->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $bot->tenant_id, 'user_id' => $user->id, 'bot_id' => $bot->id, 'status' => 'CODE', 'code_hash' => $this->hash($code), 'expires_at' => now()->addMinutes(10)])->save();
            $this->audit('telegram.link_code_created', $user);

            return $code;
        });
    }

    public function approve(Link $link, User $user): void
    {
        Gate::forUser($user)->authorize('create', Diagnostic::class);
        DB::transaction(function () use ($link, $user) {
            $link = Link::whereKey($link->id)->lockForUpdate()->firstOrFail();
            abort_unless($link->user_id === $user->id && $link->status === 'AWAITING_APPROVAL' && $link->expires_at->isFuture(), 403);
            $link->forceFill(['status' => 'ACTIVE', 'verified_at' => now(), 'code_hash' => null])->save();
            $this->audit('telegram.link_verified', $user);
        });
    }

    public function revoke(Link $link, User $actor): void
    {
        abort_unless($link->user_id === $actor->id || Gate::forUser($actor)->allows('manage-intelligence'), 403);
        DB::transaction(function () use ($link, $actor) {
            $link = Link::whereKey($link->id)->lockForUpdate()->firstOrFail();
            if ($link->conversation_id && ($c = Conversation::whereKey($link->conversation_id)->lockForUpdate()->first()) && $c->state === 'WAITING_FOR_CONFIRMATION') {
                app(Engine::class)->transition($c, State::Cancelled, 'channel_revoked');
                $c->save();
            }
            $link->forceFill(['status' => 'REVOKED', 'code_hash' => null, 'revoked_at' => now()])->save();
            Outbound::where('link_id', $link->id)->where('status', 'PENDING')->update(['status' => 'SUPPRESSED', 'body_cipher' => null, 'last_error' => 'LINK_REVOKED']);
            $this->audit('telegram.link_revoked', $actor);
        });
    }

    public function identity(Link $link): Identity
    {
        abort_unless($link->status === 'ACTIVE' && Tenant::whereKey($link->tenant_id)->where('status', 'active')->exists(), 403);
        $ctx = app(TenantContext::class);
        $ctx->apply($link->tenant_id, $link->user_id);
        $user = User::findOrFail($link->user_id);
        abort_unless($user->tenant_id === $link->tenant_id || $user->tenant_id === null, 403);
        $ctx->forUser($user, $link->tenant_id);
        Gate::forUser($user)->authorize('create', Diagnostic::class);
        abort_if($user->must_change_password, 403);

        return new Identity($link->tenant_id, $user, 'telegram', $link->id);
    }

    public function hash(string $code): string
    {
        return hash_hmac('sha256', $code, config('app.key'));
    }

    public function audit(string $event, ?User $user = null): void
    {
        app(Audit::class)->record($event, $user, app(TenantContext::class)->tenantId, 'telegram');
    }
}
