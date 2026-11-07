<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_maintenance_accesses', function (Blueprint $table) {
            // Existing expired credentials remain expired until a fresh explicit save.
            $table->boolean('until_revoked')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('agent_maintenance_accesses', function (Blueprint $table) {
            $table->dropColumn('until_revoked');
        });
    }
};
