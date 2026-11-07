<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Agent;
use App\Models\User;
use App\Tenancy\TenantContext;

class AgentPolicy
{
    public function viewAny(User $u): bool
    {
        $c = app(TenantContext::class);

        return $u->status === 'active' && $c->userId === $u->id && $c->tenantId !== null && ($c->platform || $u->tenant_id === $c->tenantId);
    }

    public function view(User $u, Agent $a): bool
    {
        return $this->viewAny($u) && $a->tenant_id === app(TenantContext::class)->tenantId;
    }

    public function create(User $u): bool
    {
        return $this->viewAny($u) && in_array($u->role, [Role::PlatformAdmin, Role::TenantAdmin], true);
    }

    public function revoke(User $u, Agent $a): bool
    {
        return $this->view($u, $a) && $this->create($u);
    }

    public function observe(User $u, Agent $a): bool
    {
        return $this->revoke($u, $a);
    }

    public function delete(User $u, Agent $a): bool
    {
        return $this->revoke($u, $a) && $a->status === 'revoked' && $a->revoked_at !== null;
    }
}
