<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;

class TenantPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->status === 'active' && $actor->role === Role::PlatformAdmin && app(TenantContext::class)->platform && app(TenantContext::class)->userId === $actor->id;
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function update(User $actor, Tenant $tenant): bool
    {
        return $this->viewAny($actor);
    }
}
