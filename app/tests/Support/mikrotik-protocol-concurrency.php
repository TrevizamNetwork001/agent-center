<?php

// Standalone contention check. Never use a production database or operational tasks.
use App\Enums\Role;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Diagnostic;
use App\Models\NocDevice;
use App\Models\NocSimulation;
use App\Models\Tenant;
use App\Models\User;
use App\Noc\Inventory;
use App\Noc\ReadOnlyLab\Access;
use App\Noc\ReadOnlyLab\Failure;
use App\Noc\ReadOnlyLab\RouterOs7\Approval;
use App\Noc\ReadOnlyLab\RouterOs7\Operation;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('DEVICE_PROTOCOL_LAB') !== '1' || ! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'agent_center_test') {
    throw new RuntimeException('Isolated device readonly protocol lab required.');
}
$context = app(TenantContext::class);
if (isset($argv[1])) {
    try {
        $context->apply(userId: (int) $argv[2]);
        $u = User::findOrFail((int) $argv[2]);
        $context->forUser($u);
        config(['device_analysis.lab_enabled' => true, 'device_analysis.lab_tenants' => [$u->tenant_id], 'device_readonly_lab.routeros_protocol' => true, 'device_readonly_lab.enabled' => true, 'device_readonly_lab.tenants' => [$u->tenant_id], 'llm.key_file' => '/lab/vault']);
        $d = NocDevice::findOrFail($argv[3]);
        while (microtime(true) < (float) $argv[5]) {
            usleep(1000);
        }
        $r = app(Inventory::class)->simulate($u, $d, 'ssh-ros7-DEVICE_BGP_PEERS_READ', $argv[4], [], 1);
        echo json_encode(['id' => $r->id]);
        $context->apply();
        exit;
    } catch (Throwable $e) {
        $reason = $e instanceof Failure ? $e->reason : class_basename($e);
        echo json_encode(['error' => $reason]);
        exit(1);
    }
}
$context->apply(platform: true);
$t = Tenant::create(['name' => 'Mikrotik concurrency LAB', 'slug' => 'device-race-'.Str::uuid(), 'status' => 'active']);
$u = (new User)->forceFill(['name' => 'LAB', 'email' => Str::uuid().'@fixture.test', 'password' => bin2hex(random_bytes(32)), 'role' => Role::TenantAdmin, 'tenant_id' => $t->id, 'status' => 'active', 'must_change_password' => false]);
$u->save();
$context->forUser($u);
$d = app(Inventory::class)->save($u, ['name' => 'CCR', 'hostname' => 'race.lab.example', 'management_ip' => '192.0.2.99', 'manufacturer' => 'mikrotik', 'platform' => 'routeros7', 'model' => 'CCR2004-16G-2S+', 'os_version' => '7.16.2', 'category' => 'router', 'network_role' => 'Borda', 'pop' => 'LAB', 'environment' => 'lab', 'admin_status' => 'active']);
config(['device_analysis.lab_enabled' => true, 'device_analysis.lab_tenants' => [$t->id], 'device_readonly_lab.routeros_protocol' => true, 'device_readonly_lab.enabled' => true, 'device_readonly_lab.tenants' => [$t->id], 'llm.key_file' => '/lab/vault']);
app(Access::class)->approve($u, $d, file_get_contents('/lab/host.pub'), file_get_contents('/lab/client'));
$agent = (new Agent)->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'name' => 'SIMULATED binding', 'hostname' => 'agent.lab.example', 'status' => 'active', 'version' => '0.6.5', 'token_hash' => hash('sha256', random_bytes(32)), 'enrolled_at' => now(), 'capabilities' => [], 'metadata' => ['simulation' => true]]);
$agent->save();
$approval = app(Approval::class);
$approval->attest($u, $d, $agent, Operation::Bgp, [], ['read', 'ssh'], $approval->fingerprint(file_get_contents('/lab/host.pub')));
file_put_contents('/lab/scenario', 'ros-slow');
file_put_contents('/lab/rollback-tenant', (string) $t->id);
file_put_contents('/lab/query-intervals.jsonl', '');
$busyRejections = 0;
$wave = function (array $users, array $keys) use ($d, $u, &$busyRejections): array {
    $when = microtime(true) + 1;
    $children = [];
    foreach ($users as $index => $userId) {
        $process = proc_open([PHP_BINARY, __FILE__, 'run', (string) $userId, $d->id, $keys[$index], (string) $when], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, options: ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('Child failed');
        }
        fclose($pipes[0]);
        $children[] = [$process, $pipes];
    }
    $ids = [];
    foreach ($children as [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        // Do not print child exceptions: this tooling must not expose credentials.
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            $error = json_decode($out, true)['error'] ?? 'UNKNOWN_CHILD_FAILURE';
            if ($error === 'LAB_BUSY') {
                $busyRejections++;
                $ids[] = null;

                continue;
            }
            throw new RuntimeException('Concurrent lab query failed: '.(preg_match('/^[A-Za-z_]{1,80}$/D', $error) ? $error : 'INVALID_CHILD_RESULT'));
        }
        $ids[] = json_decode($out, true, flags: JSON_THROW_ON_ERROR)['id'];
    }

    // Busy is a bounded fail-closed result, not permission to bypass the device lock.
    // Once the wave has ended, retry the same typed request keys sequentially.
    foreach ($ids as $index => $id) {
        if ($id === null) {
            if ($users[$index] !== $u->id) {
                throw new RuntimeException('Unexpected retry actor');
            }
            $ids[$index] = app(Inventory::class)->simulate($u, $d, 'ssh-ros7-DEVICE_BGP_PEERS_READ', $keys[$index], [], 1)->id;
        }
    }

    return $ids;
};
$key = (string) Str::uuid();
$ids = $wave(array_fill(0, 4, $u->id), array_fill(0, 4, $key));
if (count(array_unique($ids)) !== 1 || NocSimulation::count() !== 1) {
    throw new RuntimeException('Duplicate idempotent simulation');
}
$users = array_fill(0, 4, $u->id);
$keys = array_map(fn () => (string) Str::uuid(), range(1, 4));
$ids = $wave($users, $keys);
if (count(array_unique($ids)) !== 4 || NocSimulation::count() !== 5 || DB::table('audit_events')->where('event_type', 'noc.simulation_created')->count() !== 5 || AgentTask::count() !== 0 || Diagnostic::count() !== 0) {
    throw new RuntimeException('Lost simulation, missing audit or unintended execution.');
}
$intervals = array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file('/lab/query-intervals.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
usort($intervals, fn ($a, $b) => $a['start'] <=> $b['start']);
if (count($intervals) !== 5) {
    throw new RuntimeException('Missing protocol interval evidence');
}
foreach ($intervals as $index => $interval) {
    if ($index > 0 && $interval['start'] < $intervals[$index - 1]['end']) {
        throw new RuntimeException('Concurrent device queries overlapped');
    }
}
file_put_contents('/lab/mikrotik-concurrency-result.json', json_encode(['passed' => true, 'duplicate_requests' => 4, 'duplicate_results' => 1, 'distinct_requests' => 4, 'serialized_protocol_queries' => 5, 'overlap' => false, 'busy_rejections' => $busyRejections, 'agent_tasks' => 0], JSON_PRETTY_PRINT)."\n");
$context->apply();
echo "Mikrotik SSH concurrency PASS: duplicate requests deduplicated; four distinct requests serialized without overlapping protocol queries; zero operational tasks.\n";
