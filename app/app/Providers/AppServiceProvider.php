<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\TenantPolicy;
use App\Policies\UserPolicy;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
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
