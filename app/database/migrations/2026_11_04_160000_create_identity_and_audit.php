<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status')->default('disabled');
            $table->string('role')->default('viewer');
            $table->boolean('must_change_password')->default(true);
            $table->timestampTz('last_login_at')->nullable();
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('active','disabled'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('active','disabled'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('platform_admin','tenant_admin','operator','viewer'))");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_tenant_role_check CHECK ((role = 'platform_admin' AND tenant_id IS NULL) OR (role <> 'platform_admin' AND tenant_id IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_type', 80);
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'created_at']);
        });
        $platform = "current_setting('app.platform_admin', true) = 'true'";
        $tenant = "tenant_id::text = nullif(current_setting('app.tenant_id', true), '')";
        $self = "id::text = nullif(current_setting('app.user_id', true), '')";
        foreach (['users', 'tenants', 'audit_events'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
        }
        DB::statement("CREATE POLICY tenant_read ON tenants FOR SELECT USING ($platform OR id::text = nullif(current_setting('app.tenant_id', true), ''))");
        DB::statement("CREATE POLICY tenant_insert ON tenants FOR INSERT WITH CHECK ($platform)");
        DB::statement("CREATE POLICY tenant_update ON tenants FOR UPDATE USING ($platform) WITH CHECK ($platform)");
        DB::statement("CREATE POLICY user_read ON users FOR SELECT USING ($platform OR $tenant OR $self OR lower(email) = nullif(current_setting('app.auth_email', true), ''))");
        DB::statement("CREATE POLICY user_insert ON users FOR INSERT WITH CHECK ($platform OR ($tenant AND role <> 'platform_admin'))");
        DB::statement("CREATE POLICY user_update ON users FOR UPDATE USING ($platform OR $tenant) WITH CHECK ($platform OR ($tenant AND role <> 'platform_admin'))");
        DB::statement("CREATE POLICY audit_read ON audit_events FOR SELECT USING ($platform OR $tenant)");
        DB::statement("CREATE POLICY audit_insert ON audit_events FOR INSERT WITH CHECK ($platform OR $tenant OR (tenant_id IS NULL AND user_id IS NULL) OR (tenant_id IS NULL AND user_id::text = nullif(current_setting('app.user_id', true), '')))");
        DB::unprepared("CREATE FUNCTION prevent_audit_mutation() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Audit events are append only'; END; $$; CREATE TRIGGER audit_immutable BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION prevent_audit_mutation();");
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        DB::statement('DROP FUNCTION IF EXISTS prevent_audit_mutation()');
        DB::statement('ALTER TABLE users DISABLE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS user_read ON users');
        DB::statement('DROP POLICY IF EXISTS user_insert ON users');
        DB::statement('DROP POLICY IF EXISTS user_update ON users');
        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_tenant_role_check, DROP CONSTRAINT users_role_check, DROP CONSTRAINT users_status_check');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn(['status', 'role', 'must_change_password', 'last_login_at']);
        });
        Schema::dropIfExists('tenants');
    }
};
