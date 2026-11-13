<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_lab_accesses', function (Blueprint $table) {
            $table->jsonb('protocol_approval')->nullable();
        });
    }

    public function down(): void
    {
        // Refuse loss of approval/audit evidence; restore source with the column retained.
        DB::statement('ALTER TABLE device_lab_accesses ADD CONSTRAINT routeros_rollback_empty CHECK (protocol_approval IS NULL)');
        Schema::table('device_lab_accesses', fn (Blueprint $table) => $table->dropColumn('protocol_approval'));
    }
};
