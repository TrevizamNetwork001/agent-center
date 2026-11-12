<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\NocDevice;
use App\Models\User;
use App\Tenancy\TenantContext;

class NocDevicePolicy
{
    public function viewAny(User $u): bool
    {
        return (new AgentPolicy)->viewAny($u);
    }

    public function view(User $u, NocDevice $d): bool
    {
        return $this->viewAny($u) && $d->tenant_id === app(TenantContext::class)->tenantId;
    }

    public function create(User $u): bool
    {
        return $this->viewAny($u) && in_array($u->role, [Role::PlatformAdmin, Role::TenantAdmin], true);
    }

    public function update(User $u, NocDevice $d): bool
    {
        return $this->view($u, $d) && $this->create($u);
    }

    public function demonstrate(User $u, NocDevice $d): bool
    {
        return $this->update($u, $d);
    }

    public function simulate(User $u, NocDevice $d): bool
    {
        return $this->view($u, $d) && in_array($u->role, [Role::PlatformAdmin, Role::TenantAdmin, Role::Operator], true);
    }
}
