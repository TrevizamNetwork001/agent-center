<?php

// The populated-table rollback must refuse to destroy credentials/history.
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('DEVICE_PROTOCOL_LAB') !== '1' || ! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'agent_center_test') {
    throw new RuntimeException('Isolated protocol lab database required.');
}
app(TenantContext::class)->apply((int) file_get_contents('/lab/rollback-tenant'));
$before = DB::table('device_lab_accesses')->count();
if ($before < 1) {
    throw new RuntimeException('Populated fixture required.');
}
DB::beginTransaction();
try {
    $migration = require database_path('migrations/2026_11_13_000002_create_device_lab_accesses.php');
    $migration->down();
    throw new RuntimeException('Destructive rollback accepted.');
} catch (QueryException $e) {
    if (($e->errorInfo[0] ?? null) !== '23514') {
        throw $e;
    }
} finally {
    DB::rollBack();
}
if (DB::table('device_lab_accesses')->count() !== $before) {
    throw new RuntimeException('Credential data lost.');
}
echo "Device Read-Only populated rollback guard PASS: data preserved.\n";
