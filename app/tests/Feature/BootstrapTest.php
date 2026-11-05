<?php

namespace Tests\Feature;

use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BootstrapTest extends TestCase
{
    use DatabaseTransactions;

    public function test_bootstrap_creates_only_first_admin_with_hidden_password_and_audit(): void
    {
        $password = 'Bootstrap-Fixture-Only-2!';
        $this->artisan('agent-center:create-admin', ['--email' => 'bootstrap@example.test', '--name' => 'Bootstrap', '--tenant' => 'Initial', '--slug' => 'initial'])
            ->expectsQuestion('Senha temporária', $password)->expectsOutput('Administrador criado. Troca de senha obrigatória no primeiro login.')->assertSuccessful();
        app(TenantContext::class)->apply(platform: true);
        $admin = User::withoutGlobalScopes()->sole();
        $this->assertTrue($admin->must_change_password);
        $this->assertNull($admin->tenant_id);
        $this->assertSame('platform_admin', $admin->role->value);
        $this->assertStringStartsWith('$argon2id$', $admin->password);
        $this->assertSame(2, DB::table('audit_events')->count());
        app(TenantContext::class)->apply();
        $this->artisan('agent-center:create-admin')->expectsOutput('Bootstrap já concluído.')->assertFailed();
    }

    public function test_bootstrap_refuses_noninteractive_password_transport(): void
    {
        $this->artisan('agent-center:create-admin', ['--no-interaction' => true])->assertFailed();
    }
}
