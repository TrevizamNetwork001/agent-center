<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;
use Tests\IdentityTestCase;

class AuthorizationTest extends IdentityTestCase
{
    public function test_platform_admin_requires_explicit_tenant_context_to_manage_users(): void
    {
        $this->actingAs($this->platform)->get('/tenants')->assertOk()->assertSee('Tenant A')->assertSee('Tenant B');
        $this->get('/users')->assertForbidden();
        $this->post('/tenant-context', ['tenant_id' => $this->a->id])->assertRedirect('/dashboard');
        $this->get('/users')->assertOk()->assertSee($this->admin->email)->assertDontSee($this->other->email);
        $this->post('/users', $this->userData(['role' => 'tenant_admin']))->assertRedirect('/users');
        $this->context()->apply(platform: true);
        $this->assertSame(Role::TenantAdmin, User::withoutGlobalScopes()->where('email', 'new@example.test')->sole()->role);
    }

    public function test_tenant_admin_manages_only_operator_and_viewer_in_own_tenant(): void
    {
        $this->actingAs($this->admin)->get('/users')->assertOk()->assertDontSee($this->other->email);
        $this->post('/users', $this->userData())->assertRedirect('/users');
        $this->context()->forUser($this->admin);
        $created = User::where('email', 'new@example.test')->sole();
        $this->assertSame($this->a->id, $created->tenant_id);
        $this->assertTrue($created->must_change_password);
        $this->get('/users/'.$this->operator->id.'/edit')->assertOk();
        $this->get('/tenants')->assertForbidden();
        $this->post('/tenant-context', ['tenant_id' => $this->b->id])->assertForbidden();
    }

    public function test_operator_and_viewer_have_dashboard_but_no_management_or_audit_access(): void
    {
        foreach ([$this->operator, $this->viewer] as $actor) {
            $this->flushSession();
            $this->actingAs($actor)->get('/dashboard')->assertOk()->assertSee('Tenant A')->assertDontSee($this->other->email);
            foreach (['/users', '/tenants', '/audit'] as $url) {
                $this->get($url)->assertForbidden();
            }
            $this->post('/users', $this->userData())->assertForbidden();
        }
    }

    public function test_role_escalation_and_tenant_injection_are_rejected(): void
    {
        $this->actingAs($this->admin);
        $this->post('/users', $this->userData(['role' => 'platform_admin']))->assertSessionHasErrors('role');
        $this->post('/users', $this->userData(['role' => 'tenant_admin']))->assertSessionHasErrors('role');
        $this->post('/users', $this->userData(['tenant_id' => $this->b->id]))->assertSessionHasErrors('tenant_id');
        $this->put('/users/'.$this->admin->id, ['name' => 'changed', 'email' => $this->admin->email, 'role' => 'viewer', 'status' => 'disabled'])->assertForbidden();
        $this->context()->forUser($this->admin);
        $anotherAdmin = $this->makeUser('second-admin@example.test', Role::TenantAdmin, $this->a->id);
        $this->get('/users/'.$anotherAdmin->id.'/edit')->assertForbidden();
    }

    public function test_cross_tenant_binding_is_not_found_and_cannot_be_updated(): void
    {
        $this->actingAs($this->admin)->get('/users/'.$this->other->id.'/edit')->assertNotFound();
        $this->put('/users/'.$this->other->id, ['name' => 'changed', 'email' => $this->other->email, 'role' => 'viewer', 'status' => 'disabled'])->assertNotFound();
        $this->assertSame('active', $this->inspect($this->other)->status);
    }

    public function test_user_updates_and_disable_are_audited_and_revoke_sessions(): void
    {
        DB::table('sessions')->insert(['id' => 'operator-device', 'user_id' => $this->operator->id, 'payload' => 'fixture', 'last_activity' => time()]);
        $this->actingAs($this->admin)->put('/users/'.$this->operator->id, ['name' => 'Updated Operator', 'email' => $this->operator->email, 'role' => 'viewer', 'status' => 'disabled'])->assertRedirect('/users');
        $this->assertSame('disabled', $this->inspect($this->operator)->status);
        $this->assertSame(0, DB::table('sessions')->where('id', 'operator-device')->count());
        $this->assertSame(1, AuditEvent::withoutGlobalScopes()->where('event_type', 'user.updated')->count());
        $this->assertSame(1, AuditEvent::withoutGlobalScopes()->where('event_type', 'user.disabled')->count());
    }

    public function test_tenant_create_update_and_disable_are_authorized_and_audited(): void
    {
        $this->actingAs($this->platform)->post('/tenants', ['name' => 'Tenant C', 'slug' => 'tenant-c', 'status' => 'active'])->assertRedirect('/tenants');
        $this->put('/tenants/'.$this->a->id, ['name' => 'Tenant A disabled', 'slug' => 'tenant-a', 'status' => 'disabled'])->assertRedirect('/tenants');
        $this->context()->apply(platform: true);
        $this->assertSame('disabled', Tenant::findOrFail($this->a->id)->status);
        $this->assertSame(1, AuditEvent::withoutGlobalScopes()->where('event_type', 'tenant.created')->count());
        $this->assertSame(1, AuditEvent::withoutGlobalScopes()->where('event_type', 'tenant.updated')->count());
    }

    public function test_other_tenant_email_collision_is_generic_and_transactional(): void
    {
        $this->actingAs($this->admin)->post('/users', $this->userData(['email' => $this->other->email]))->assertSessionHasErrors('email');
        $this->context()->apply(platform: true);
        $this->assertSame(5, User::withoutGlobalScopes()->count());
        $this->assertSame(0, AuditEvent::withoutGlobalScopes()->where('event_type', 'user.created')->count());
    }

    public function test_forged_tenant_session_is_ignored_for_tenant_roles(): void
    {
        $this->actingAs($this->admin)->withSession(['active_tenant_id' => $this->b->id])->get('/users')->assertOk()->assertSee($this->admin->email)->assertDontSee($this->other->email);
    }

    public function test_tenant_admin_audit_page_is_scoped(): void
    {
        $this->context()->apply(platform: true);
        app(Audit::class)->record('user.created', $this->admin, $this->a->id, 'user', $this->operator->id);
        app(Audit::class)->record('tenant.updated', $this->other, $this->b->id, 'tenant', $this->b->id);
        $this->actingAs($this->admin)->get('/audit')->assertOk()->assertSee('user.created')->assertDontSee('tenant.updated');
    }
}
