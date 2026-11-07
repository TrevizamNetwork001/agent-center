<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_maintenance_accesses', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('agent_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('host', 45);
            $t->unsignedInteger('port');
            $t->string('username', 32);
            $t->string('host_fingerprint', 64);
            $t->text('private_key');
            $t->unsignedInteger('revision')->default(1);
            $t->timestampTz('expires_at');
            $t->string('check_status', 40)->nullable();
            $t->timestampTz('checked_at')->nullable();
            $t->timestampsTz();
            $t->unique(['tenant_id', 'agent_id']);
        });
        DB::statement('ALTER TABLE agent_maintenance_accesses ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE agent_maintenance_accesses FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_access ON agent_maintenance_accesses USING (tenant_id::text=nullif(current_setting('app.tenant_id',true),'')) WITH CHECK (tenant_id::text=nullif(current_setting('app.tenant_id',true),''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_maintenance_accesses');
    }
};
