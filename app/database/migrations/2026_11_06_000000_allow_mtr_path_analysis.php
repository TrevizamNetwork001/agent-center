<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE skills DROP CONSTRAINT skills_capability_check');
        DB::statement("ALTER TABLE skills ADD CONSTRAINT skills_capability_check CHECK(capability IN ('agent_info','dns_lookup:v1','tcp_connect:v1','http_probe:v1','icmp_ping:v1','traceroute:v1','mtr:v1','packet_capture:v1','port_scan:v1','mtr_path_analysis:v1'))");
    }

    public function down(): void
    { /* Preserve additive allowlist and tenant records. */
    }
};
