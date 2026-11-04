<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\IdentityTestCase;

class TenantIsolationTest extends IdentityTestCase
{
    public function test_scopes_and_rls_block_cross_tenant_reads_even_without_eloquent_scopes(): void
    {
        $this->context()->apply(platform: true);
        app(Audit::class)->record('user.created', $this->admin, $this->a->id);
        app(Audit::class)->record('user.created', $this->other, $this->b->id);
        $this->context()->forUser($this->admin);
        $this->assertNull(User::find($this->other->id));
        $this->assertNull(User::withoutGlobalScopes()->find($this->other->id));
        $this->assertNull(DB::table('users')->where('id', $this->other->id)->first());
        $this->assertNull(DB::table('tenants')->where('id', $this->b->id)->first());
        $this->assertSame(1, AuditEvent::count());
        $this->assertSame(0, DB::table('audit_events')->where('tenant_id', $this->b->id)->count());
    }

    public function test_no_context_denies_reads_and_context_does_not_leak_after_requests(): void
    {
        $this->assertSame(0, User::count());
        $this->assertSame(0, DB::table('users')->count());
        $this->actingAs($this->admin)->get('/dashboard')->assertOk();
        $this->assertNull($this->context()->tenantId);
        $this->assertFalse($this->context()->platform);
        $this->assertSame(0, DB::table('users')->count());
        $this->flushSession();
        $this->actingAs($this->other)->get('/users')->assertOk()->assertDontSee($this->admin->email);
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_rls_prevents_writes_into_another_tenant(): void
    {
        $this->context()->forUser($this->admin);
        try {
            DB::transaction(fn () => DB::table('audit_events')->insert(['tenant_id' => $this->b->id, 'user_id' => $this->admin->id, 'event_type' => 'user.created', 'created_at' => now()]));
            $this->fail('RLS deveria negar o insert.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode());
        }
        $this->assertSame(0, DB::table('users')->where('id', $this->other->id)->update(['status' => 'disabled']));
    }

    public function test_rls_prevents_self_promotion_to_global_platform_identity(): void
    {
        $this->context()->forUser($this->admin);
        try {
            DB::transaction(fn () => DB::table('users')->where('id', $this->admin->id)->update(['role' => 'platform_admin', 'tenant_id' => null]));
            $this->fail('RLS deveria negar a promoção.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->getCode());
        }
    }

    public function test_audit_is_append_only_and_metadata_has_no_secrets(): void
    {
        $this->context()->forUser($this->admin);
        app(Audit::class)->record('user.updated', $this->admin, $this->a->id, metadata: ['password' => 'do-not-log-secret', 'token' => 'token-secret', 'fields' => ['name']]);
        $event = AuditEvent::sole();
        $this->assertSame(['fields' => ['name']], $event->metadata);
        foreach (['update', 'delete'] as $operation) {
            try {
                DB::transaction(fn () => $operation === 'update' ? DB::table('audit_events')->where('id', $event->id)->update(['event_type' => 'modified']) : DB::table('audit_events')->where('id', $event->id)->delete());
                $this->fail('Grant deveria negar mutação de auditoria.');
            } catch (QueryException $exception) {
                $this->assertSame('42501', $exception->getCode());
            }
        }
        $this->assertSame('user.updated', $event->fresh()->event_type);
    }

    public function test_query_exceptions_are_logged_without_sql_or_bindings(): void
    {
        Log::spy();
        report(new QueryException('pgsql', 'insert into users values (?)', ['do-not-log-secret'], new \PDOException('Sensitive underlying data', 23505)));
        Log::shouldHaveReceived('error')->once()->with('Database operation failed', ['sqlstate' => '23505']);
    }

    public function test_sensitive_attributes_cannot_be_mass_assigned(): void
    {
        $user = new User;
        $user->fill(['name' => 'Safe', 'email' => 'safe@example.test', 'tenant_id' => $this->b->id, 'role' => 'platform_admin', 'status' => 'active', 'password' => 'do-not-log-secret', 'must_change_password' => false]);
        $this->assertSame(['name' => 'Safe', 'email' => 'safe@example.test'], $user->getAttributes());
    }

    public function test_runtime_role_is_not_superuser_and_rls_is_forced(): void
    {
        $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname=current_user');
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);
        $tables = DB::select("SELECT relname, relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname IN ('users','tenants','audit_events')");
        $this->assertCount(3, $tables);
        foreach ($tables as $table) {
            $this->assertTrue($table->relrowsecurity);
            $this->assertTrue($table->relforcerowsecurity);
        }
    }
}
