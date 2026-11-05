<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_operations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->uuid('agent_id')->references('id')->on('agents')->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('kind', 20);
            $t->string('status', 30);
            $t->string('from_version', 30);
            $t->jsonb('release');
            $t->string('reason', 40);
            $t->string('error', 60)->nullable();
            $t->jsonb('evidence')->nullable();
            $t->timestampTz('claimed_at')->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'agent_id', 'created_at']);
        });
        DB::statement('ALTER TABLE agent_operations ADD CONSTRAINT operation_agent_tenant FOREIGN KEY(agent_id,tenant_id) REFERENCES agents(id,tenant_id)');
        DB::statement("ALTER TABLE agent_operations ADD CONSTRAINT operation_kind CHECK (kind IN ('update','rollback'))");
        DB::statement("ALTER TABLE agent_operations ADD CONSTRAINT operation_status CHECK (status IN ('PRECHECK_REQUESTED','PRECHECKING','PLANNED','APPROVED','RUNNING','COMPLETED','FAILED','UNCERTAIN'))");
        DB::statement("CREATE UNIQUE INDEX agent_one_live_operation ON agent_operations(agent_id) WHERE status NOT IN ('COMPLETED','FAILED')");
        DB::statement('ALTER TABLE agent_operations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE agent_operations FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_access ON agent_operations FOR ALL USING (tenant_id::text=nullif(current_setting('app.tenant_id',true),'')) WITH CHECK (tenant_id::text=nullif(current_setting('app.tenant_id',true),''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_operations');
    }
};
