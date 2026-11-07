<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE skills DROP CONSTRAINT skills_capability_check');
        DB::statement("ALTER TABLE skills ADD CONSTRAINT skills_capability_check CHECK(capability IN ('agent_info','dns_lookup:v1','tcp_connect:v1','http_probe:v1','icmp_ping:v1','traceroute:v1','mtr:v1','packet_capture:v1','port_scan:v1'))");

        foreach (['diagnostics', 'diagnostic_steps', 'agent_task_attempts'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->jsonb('performance')->nullable();
            });
        }
        Schema::table('diagnostics', function (Blueprint $table) {
            $table->timestampTz('last_dispatched_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        // Keep the additive skill capability allowlist on rollback: prepared tenant
        // records must not be deleted, and forced RLS must not be bypassed to inspect them.
        Schema::table('diagnostics', fn (Blueprint $table) => $table->dropColumn('last_dispatched_at'));
        foreach (['agent_task_attempts', 'diagnostic_steps', 'diagnostics'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('performance'));
        }
    }
};
