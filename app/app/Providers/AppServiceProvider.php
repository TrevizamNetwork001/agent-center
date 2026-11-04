<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Diagnostic;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\AgentPolicy;
use App\Policies\ConversationPolicy;
use App\Policies\DiagnosticPolicy;
use App\Policies\TenantPolicy;
use App\Policies\UserPolicy;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        Auth::provider('tenant-eloquent', function ($app, array $config) {
            return (new EloquentUserProvider($app['hash'], $config['model']))
                ->withQuery(fn ($query) => $query->withoutGlobalScope(TenantScope::class));
        });
        Gate::define('manage-platform-backups', fn (User $user) => $user->role === Role::PlatformAdmin && $user->status === 'active');
        RateLimiter::for('platform-backups', fn ($request) => Limit::perMinute(6)->by('platform-backups:'.$request->user()->id));
        Gate::policy(Conversation::class, ConversationPolicy::class);
        RateLimiter::for('conversation-message', fn ($r) => [Limit::perMinute(20)->by('conversation-user:'.$r->user()->id), Limit::perMinute(120)->by('conversation-tenant:'.app(TenantContext::class)->tenantId)]);
        RateLimiter::for('conversation-sync', fn ($r) => Limit::perMinute(60)->by('conversation-sync:'.$r->user()->id));
        Gate::policy(Agent::class, AgentPolicy::class);
        Gate::define('manage-intelligence', fn (User $u) => (new AgentPolicy)->create($u));
        Gate::define('read-knowledge', fn (User $u) => (new AgentPolicy)->viewAny($u));
        RateLimiter::for('llm-call', fn ($r) => [Limit::perMinute(6)->by('user:'.$r->user()->id), Limit::perMinute(30)->by('tenant:'.app(TenantContext::class)->tenantId)]);
        Gate::policy(Diagnostic::class, DiagnosticPolicy::class);
        Gate::define('manage-diagnostic-catalog', fn (User $u) => (new AgentPolicy)->create($u));
        RateLimiter::for('agent-origin', fn ($r) => Limit::perMinute(1200)->by(hash('sha256', (string) $r->ip())));
        RateLimiter::for('agent-enrollment', fn ($r) => Limit::perMinute(10)->by(hash('sha256', (string) $r->ip())));
        RateLimiter::for('agent-api', fn ($r) => Limit::perMinute(120)->by($r->attributes->get('agent')->id));
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::define('view-audit', fn (User $user) => in_array($user->role, [Role::PlatformAdmin, Role::TenantAdmin], true));
        if ($this->app->runningInConsole()) {
            Queue::looping(function (): void {
                touch(storage_path('framework/worker-heartbeat'));
            });
        }
    }
}
