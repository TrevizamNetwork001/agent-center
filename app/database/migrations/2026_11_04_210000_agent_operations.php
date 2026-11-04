<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE agents ALTER COLUMN enrolled_at DROP NOT NULL');
        DB::statement('ALTER TABLE agents DROP CONSTRAINT agents_status_check');
        DB::statement("ALTER TABLE agents ADD CONSTRAINT agents_status_check CHECK (status IN ('registered','pending','active','revoked'))");
        Schema::table('agent_enrollments', function (Blueprint $t) {
            $t->uuid('agent_id')->nullable();
            $t->char('receipt_hash', 64)->nullable();
            $t->timestampTz('requested_at')->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampTz('credential_issued_at')->nullable();
        });
        DB::statement('ALTER TABLE agent_enrollments ADD CONSTRAINT enrollment_agent_tenant FOREIGN KEY(agent_id,tenant_id) REFERENCES agents(id,tenant_id)');
        DB::statement("CREATE POLICY enrollment_receipt_lookup ON agent_enrollments FOR SELECT USING (id::text = nullif(current_setting('app.enrollment_id',true),'') AND receipt_hash = nullif(current_setting('app.enrollment_hash',true),''))");
    }

    public function down(): void
    {
        // Pending registrations cannot be silently promoted or destroyed during rollback.
        if (DB::table('agents')->whereIn('status', ['registered', 'pending'])->exists()) {
            throw new RuntimeException('Resolve pending registrations before schema rollback');
        }
        DB::statement('ALTER TABLE agent_enrollments DROP CONSTRAINT enrollment_agent_tenant');
        DB::statement('DROP POLICY enrollment_receipt_lookup ON agent_enrollments');
        Schema::table('agent_enrollments', function (Blueprint $t) {
            $t->dropForeign(['approved_by']);
            $t->dropColumn(['agent_id', 'receipt_hash', 'requested_at', 'approved_at', 'approved_by', 'credential_issued_at']);
        });
        DB::statement('ALTER TABLE agents ALTER COLUMN enrolled_at SET NOT NULL');
        DB::statement('ALTER TABLE agents DROP CONSTRAINT agents_status_check');
        DB::statement("ALTER TABLE agents ADD CONSTRAINT agents_status_check CHECK (status IN ('active','revoked'))");
    }
};
