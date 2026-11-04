<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name', 120);
            $t->string('hostname', 253);
            $t->string('status')->default('active');
            $t->string('version', 30);
            $t->char('token_hash', 64);
            $t->timestampTz('enrolled_at');
            $t->timestampTz('last_heartbeat_at')->nullable();
            $t->timestampTz('last_poll_at')->nullable();
            $t->ipAddress('last_seen_ip')->nullable();
            $t->jsonb('capabilities');
            $t->jsonb('metadata')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status']);
        });
        Schema::create('agent_enrollments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('name', 120);
            $t->char('secret_hash', 64);
            $t->timestampTz('expires_at');
            $t->timestampTz('consumed_at')->nullable();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE agents ADD CONSTRAINT agents_status_check CHECK (status IN ('active','revoked'))");
        foreach (['agents', 'agent_enrollments'] as $table) {
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE $table FORCE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_access ON $table FOR ALL USING (tenant_id::text = nullif(current_setting('app.tenant_id',true),'')) WITH CHECK (tenant_id::text = nullif(current_setting('app.tenant_id',true),''))");
        }
        DB::statement("CREATE POLICY agent_lookup ON agents FOR SELECT USING (id::text = nullif(current_setting('app.agent_id',true),'') AND token_hash = nullif(current_setting('app.agent_token_hash',true),''))");
        DB::statement("CREATE POLICY enrollment_lookup ON agent_enrollments FOR SELECT USING (id::text = nullif(current_setting('app.enrollment_id',true),'') AND secret_hash = nullif(current_setting('app.enrollment_hash',true),''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_enrollments');
        Schema::dropIfExists('agents');
    }
};
