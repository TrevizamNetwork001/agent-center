<?php

use App\Agent\Protocol;
use App\Diagnostics\Catalog;
use App\Diagnostics\Engine;
use App\Enums\Role;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\AgentTaskAttempt;
use App\Models\Profile;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$c = app(TenantContext::class);
if (isset($argv[1])) {
    $c->apply(tenantId: (int) $argv[2]);
    $a = Agent::findOrFail($argv[3]);
    while (microtime(true) < (float) $argv[4]) {
        usleep(1000);
    }
    if ($argv[1] === 'tick') {
        for ($i = 0; $i < 15; $i++) {
            app(Engine::class)->tick();
            usleep(10000);
        }echo '{}';
    } else {
        $t = app(Engine::class)->claim($a);
        echo json_encode(['claimed' => $t !== null]);
    }
    $c->apply();
    exit;
}
function check(bool $v, string $why): void
{
    if (! $v) {
        throw new RuntimeException($why);
    }
}
function spawn(string $mode, int $tid, string $aid, float $when): array
{
    $p = proc_open([PHP_BINARY, __FILE__, $mode, (string) $tid, $aid, (string) $when], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
    check(is_resource($p), 'child_start');
    fclose($pipes[0]);

    return [$p, $pipes];
}
function collect(array $child): array
{
    [$p,$pipes] = $child;
    $s = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check(proc_close($p) === 0, 'child_failed');

    return json_decode($s, true, flags: JSON_THROW_ON_ERROR);
}
$c->apply(platform: true);
$tenant = Tenant::create(['name' => 'Concurrency fixture', 'slug' => 'perf-'.Str::uuid(), 'status' => 'active']);
$u = new User;
$u->forceFill(['name' => 'Fixture', 'email' => Str::uuid().'@fixture.test', 'password' => 'Fixture-Only-Passw0rd!', 'tenant_id' => $tenant->id, 'role' => Role::Operator, 'status' => 'active', 'must_change_password' => false])->save();
$c->forUser($u);
app(Catalog::class)->ensure();
$a = new Agent;
$a->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Concurrency fixture', 'hostname' => 'fixture', 'version' => '0.6.0', 'status' => 'active', 'token_hash' => hash('sha256', random_bytes(32)), 'capabilities' => Protocol::CAPABILITIES, 'enrolled_at' => now(), 'metadata' => ['task_protocol' => 1]])->save();
$e = app(Engine::class);
$d = $e->create(Profile::where('key', 'SITE_NAO_ABRE')->sole(), $a, 'example.com', $u);
$e->tick();
$when = microtime(true) + 1;
$one = spawn('claim', $tenant->id, $a->id, $when);
$two = spawn('claim', $tenant->id, $a->id, $when);
$r1 = collect($one);
$r2 = collect($two);
check((int) $r1['claimed'] + (int) $r2['claimed'] === 1, 'concurrent_claims_not_exclusive');
check(AgentTaskAttempt::whereIn('status', ['CLAIMED', 'RUNNING'])->count() === 1, 'multiple_active_attempts');
AgentTaskAttempt::where('status', 'CLAIMED')->update(['lease_expires_at' => now()->subSecond()]);
$e->tick();
$t = $e->claim($a);
check($t !== null, 'reclaim_failed');
$env = array_intersect_key($t, array_flip(['task_id', 'execution_id', 'generation', 'lease_token']));
$ticker = spawn('tick', $tenant->id, $a->id, microtime(true));
$e->operation($a, 'start', $env);
$result = ['ok' => true, 'data' => ['answers' => $t['input']['record_type'] === 'A' ? ['8.8.8.8'] : [], 'record_type' => $t['input']['record_type'], 'duration_ms' => 1]];
$e->operation($a, 'result', $env + ['result' => $result]);
collect($ticker);
$dup = $e->operation($a, 'result', $env + ['result' => $result]);
check(($dup['duplicate'] ?? false) === true, 'duplicate_result_not_idempotent');
check(AgentTask::where('diagnostic_id', $d->id)->count() === 2, 'duplicate_enqueue');
check(! AgentTask::where('diagnostic_id', $d->id)->where('capability', 'tcp_connect:v1')->exists(), 'dependency_bypassed');
$c->apply();
echo "PASS: separate-process concurrent claims; worker/result race; exclusive execution lane; expired-claim fencing; duplicate idempotence; DNS prerequisites preserved\n";
