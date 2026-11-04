<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use App\Tenancy\TenantContext;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->status === 'active' && app(TenantContext::class)->userId === $actor->id && in_array($actor->role, [Role::PlatformAdmin, Role::TenantAdmin], true) && app(TenantContext::class)->tenantId !== null && ($actor->role === Role::PlatformAdmin || $actor->tenant_id === app(TenantContext::class)->tenantId);
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->viewAny($actor) && $target->tenant_id === app(TenantContext::class)->tenantId && $target->id !== $actor->id && $target->role !== Role::PlatformAdmin && ($actor->role === Role::PlatformAdmin || in_array($target->role, [Role::Operator, Role::Viewer], true));
    }
}
