<?php

namespace Tests;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

abstract class IdentityTestCase extends TestCase
{
    use DatabaseTransactions;

    protected const PASSWORD = 'Fixture-Only-Passw0rd!';

    protected Tenant $a;

    protected Tenant $b;

    protected User $platform;

    protected User $admin;

    protected User $operator;

    protected User $viewer;

    protected User $other;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->context()->apply(platform: true);
        $this->a = Tenant::create(['name' => 'Tenant A', 'slug' => 'tenant-a', 'status' => 'active']);
        $this->b = Tenant::create(['name' => 'Tenant B', 'slug' => 'tenant-b', 'status' => 'active']);
        $this->platform = $this->makeUser('platform@example.test', Role::PlatformAdmin, null);
        $this->admin = $this->makeUser('admin-a@example.test', Role::TenantAdmin, $this->a->id);
        $this->operator = $this->makeUser('operator-a@example.test', Role::Operator, $this->a->id);
        $this->viewer = $this->makeUser('viewer-a@example.test', Role::Viewer, $this->a->id);
        $this->other = $this->makeUser('private-b@example.test', Role::TenantAdmin, $this->b->id);
        $this->context()->apply();
    }

    protected function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    protected function makeUser(string $email, Role $role, ?int $tenantId, array $extra = []): User
    {
        $user = new User;
        $user->forceFill(['name' => $email, 'email' => $email, 'password' => Hash::make(self::PASSWORD), 'role' => $role, 'tenant_id' => $tenantId, 'status' => 'active', 'must_change_password' => false, ...$extra])->save();

        return $user;
    }

    protected function inspect(User $user): User
    {
        $this->context()->apply(platform: true);

        return User::withoutGlobalScopes()->findOrFail($user->id);
    }

    protected function userData(array $extra = []): array
    {
        return ['name' => 'New User', 'email' => 'new@example.test', 'role' => 'viewer', 'status' => 'active', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, ...$extra];
    }
}
