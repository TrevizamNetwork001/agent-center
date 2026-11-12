<?php

// Standalone contention check. Never use a production database or operational tasks.
use App\Enums\Role;
use App\Models\AgentTask;
use App\Models\Diagnostic;
use App\Models\NocDevice;
use App\Models\NocSimulation;
use App\Models\Tenant;
use App\Models\User;
use App\Noc\Inventory;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'agent_center_device_analysis_lab') {
    throw new RuntimeException('Isolated device analysis lab database required.');
}
$context = app(TenantContext::class);
if (isset($argv[1])) {
    $context->apply(userId: (int) $argv[2]);
    $u = User::findOrFail((int) $argv[2]);
    $context->forUser($u);
    config(['device_analysis.lab_enabled' => true, 'device_analysis.lab_tenants' => [$u->tenant_id]]);
    $d = NocDevice::findOrFail($argv[3]);
    while (microtime(true) < (float) $argv[5]) {
        usleep(1000);
    }
    $r = app(Inventory::class)->simulate($u, $d, 'da-ne8000-bgp-idle', $argv[4], [], 1);
    echo json_encode(['id' => $r->id]);
    $context->apply();
    exit;
}
$context->apply(platform: true);
$t = Tenant::create(['name' => 'Device concurrency LAB', 'slug' => 'device-race-'.Str::uuid(), 'status' => 'active']);
$u = (new User)->forceFill(['name' => 'LAB', 'email' => Str::uuid().'@fixture.test', 'password' => bin2hex(random_bytes(32)), 'role' => Role::TenantAdmin, 'tenant_id' => $t->id, 'status' => 'active', 'must_change_password' => false]);
$u->save();
$context->forUser($u);
$d = app(Inventory::class)->save($u, ['name' => 'NE8000', 'hostname' => 'race.lab.example', 'management_ip' => '192.0.2.99', 'manufacturer' => 'huawei', 'platform' => 'huawei-vrp', 'model' => 'NE8000 M4', 'os_version' => 'V800R023C00SPC500', 'category' => 'router', 'network_role' => 'Borda', 'pop' => 'LAB', 'environment' => 'lab', 'admin_status' => 'active']);
$key = (string) Str::uuid();
$when = microtime(true) + 1;
$children = [];
foreach (range(1, 4) as $n) {
    $process = proc_open([PHP_BINARY, __FILE__, 'run', (string) $u->id, $d->id, $key, (string) $when], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, options: ['bypass_shell' => true]);
    if (! is_resource($process)) {
        throw new RuntimeException('Child failed');
    }fclose($pipes[0]);
    $children[] = [$process, $pipes];
}
$ids = [];
foreach ($children as [$process,$pipes]) {
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Concurrent lab simulation failed.');
    }$ids[] = json_decode($out, true, flags: JSON_THROW_ON_ERROR)['id'];
}
if (count(array_unique($ids)) !== 1 || NocSimulation::count() !== 1 || DB::table('audit_events')->where('event_type', 'noc.simulation_created')->count() !== 1 || AgentTask::count() !== 0 || Diagnostic::count() !== 0) {
    throw new RuntimeException('Duplicate simulation or unintended execution.');
}
$context->apply();
echo "Device Analysis concurrency PASS: 4 processes, one immutable simulation, one audit, zero operational tasks.\n";
