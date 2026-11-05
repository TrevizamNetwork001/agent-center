<?php

namespace App\Tenancy;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TenantContext
{
    public ?int $tenantId = null;

    public ?int $userId = null;

    public bool $platform = false;

    public function apply(?int $tenantId = null, ?int $userId = null, bool $platform = false, string $email = ''): void
    {
        $this->tenantId = $tenantId;
        $this->userId = $userId;
        $this->platform = $platform;
        DB::select("SELECT set_config('app.tenant_id', ?, false), set_config('app.user_id', ?, false), set_config('app.platform_admin', ?, false), set_config('app.auth_email', ?, false)", [(string) $tenantId, (string) $userId, $platform ? 'true' : 'false', $email]);
    }

    public function forUser(User $user, ?int $selectedTenant = null): void
    {
        $platform = $user->role === Role::PlatformAdmin;
        $this->apply($platform ? $selectedTenant : $user->tenant_id, $user->id, $platform);
    }
}
