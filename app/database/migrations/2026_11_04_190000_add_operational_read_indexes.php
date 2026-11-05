<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX diagnostics_history ON diagnostics(tenant_id,created_at DESC,id)');
        DB::statement('CREATE INDEX diagnostics_profile_history ON diagnostics(tenant_id,profile_id,created_at DESC)');
        DB::statement('CREATE INDEX diagnostics_agent_history ON diagnostics(tenant_id,agent_id,created_at DESC)');
        DB::statement('CREATE INDEX conclusions_classification ON diagnostic_conclusions(tenant_id,classification,diagnostic_id)');
    }

    public function down(): void
    {
        foreach (['diagnostics_history', 'diagnostics_profile_history', 'diagnostics_agent_history', 'conclusions_classification'] as $i) {
            DB::statement("DROP INDEX $i");
        }
    }
};
