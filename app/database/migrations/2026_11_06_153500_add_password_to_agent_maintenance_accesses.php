<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_maintenance_accesses', function (Blueprint $t) {
            $t->text('password')->nullable();
            $t->text('private_key')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Password-only rows have no compatible key credential; preserve them on rollback.
        if (DB::table('agent_maintenance_accesses')->whereNull('private_key')->exists()) {
            throw new RuntimeException('Password-only maintenance accesses must be removed before schema rollback.');
        }
        Schema::table('agent_maintenance_accesses', function (Blueprint $t) {
            $t->dropColumn('password');
            $t->text('private_key')->nullable(false)->change();
        });
    }
};
