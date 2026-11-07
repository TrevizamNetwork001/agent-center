<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_release_quarantines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('release_id')->unique()->constrained('agent_releases')->restrictOnDelete();
            $t->char('sha256', 64);
            $t->foreignId('quarantined_by')->constrained('users')->restrictOnDelete();
            $t->string('reason', 64);
            $t->timestampTz('quarantined_at');
            $t->unique(['tenant_id', 'sha256']);
        });
        DB::statement('ALTER TABLE agent_release_quarantines ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE agent_release_quarantines FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_access ON agent_release_quarantines USING (tenant_id::text=nullif(current_setting('app.tenant_id',true),'')) WITH CHECK (tenant_id::text=nullif(current_setting('app.tenant_id',true),''))");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION validate_agent_release_quarantine() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.reason <> 'controlled_failure_test' OR NOT EXISTS (
        SELECT 1 FROM agent_releases r WHERE r.id=NEW.release_id
        AND r.tenant_id=NEW.tenant_id AND r.sha256=NEW.sha256
    ) THEN RAISE EXCEPTION 'Invalid release quarantine'; END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER validate_agent_release_quarantine BEFORE INSERT ON agent_release_quarantines
FOR EACH ROW EXECUTE FUNCTION validate_agent_release_quarantine();
CREATE FUNCTION immutable_agent_release_quarantine() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Release quarantines are immutable'; END $$;
CREATE TRIGGER immutable_agent_release_quarantine BEFORE UPDATE OR DELETE ON agent_release_quarantines
FOR EACH ROW EXECUTE FUNCTION immutable_agent_release_quarantine();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_release_quarantines');
        DB::statement('DROP FUNCTION IF EXISTS validate_agent_release_quarantine()');
        DB::statement('DROP FUNCTION IF EXISTS immutable_agent_release_quarantine()');
    }
};
