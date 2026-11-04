<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use App\Tenancy\TenantContext;

class ConversationPolicy
{
    public function view(User $u, Conversation $c): bool
    {
        return (new DiagnosticPolicy)->viewAny($u) && $c->tenant_id === app(TenantContext::class)->tenantId && $c->user_id === $u->id;
    }

    public function send(User $u, Conversation $c): bool
    {
        return $this->view($u, $c) && (new DiagnosticPolicy)->create($u);
    }
}
