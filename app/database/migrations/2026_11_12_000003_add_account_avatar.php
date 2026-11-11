<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ADD COLUMN avatar_path varchar(255), ADD COLUMN avatar_mime varchar(32)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users ADD CONSTRAINT account_avatar_rollback_empty CHECK(avatar_path IS NULL)');
        DB::statement('ALTER TABLE users DROP CONSTRAINT account_avatar_rollback_empty, DROP COLUMN avatar_path, DROP COLUMN avatar_mime');
    }
};
