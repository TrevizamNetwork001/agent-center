<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_releases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignId('published_by')->constrained('users')->restrictOnDelete();
            $t->string('version', 32);
            $t->string('platform', 32);
            $t->bigInteger('sequence');
            $t->char('sha256', 64);
            $t->text('manifest');
            $t->text('signature');
            $t->text('artifact_base64');
            $t->timestampTz('published_at');
            $t->unique(['tenant_id', 'sha256']);
            $t->unique(['tenant_id', 'sequence']);
        });
        DB::statement('ALTER TABLE agent_releases ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE agent_releases FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY tenant_access ON agent_releases USING (tenant_id::text=nullif(current_setting('app.tenant_id',true),'')) WITH CHECK (tenant_id::text=nullif(current_setting('app.tenant_id',true),''))");
        DB::unprepared("CREATE FUNCTION immutable_agent_release() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Published releases are immutable'; END $$; CREATE TRIGGER immutable_agent_release BEFORE UPDATE OR DELETE ON agent_releases FOR EACH ROW EXECUTE FUNCTION immutable_agent_release();");
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_releases');
        DB::statement('DROP FUNCTION IF EXISTS immutable_agent_release()');
    }
};
