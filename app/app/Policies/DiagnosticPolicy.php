<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Agent;
use App\Models\Diagnostic;
use App\Models\User;
use App\Tenancy\TenantContext;

class DiagnosticPolicy extends AgentPolicy
{
    public function view(User $u, Agent|Diagnostic $d): bool
    {
        return $this->viewAny($u) && $d->tenant_id === app(TenantContext::class)->tenantId;
    }

    public function create(User $u): bool
    {
        return $this->viewAny($u) && in_array($u->role, [Role::PlatformAdmin, Role::TenantAdmin, Role::Operator], true);
    }

    public function cancel(User $u, Diagnostic $d): bool
    {
        return $this->view($u, $d) && $this->create($u);
    }
}
