<?php

namespace App\Conversation;

use App\Models\Diagnostic;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Gate;

// Future adapters must establish a verified identity and authorization, never just a phone number.
final readonly class Identity
{
    public function __construct(public int $tenantId, public User $user, public string $channel = 'web', public ?string $contactId = null) {}

    public static function web(User $user): self
    {
        Gate::forUser($user)->authorize('viewAny', Diagnostic::class);
        $id = app(TenantContext::class)->tenantId;
        abort_unless($id, 403);

        return new self($id, $user);
    }
}
