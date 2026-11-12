<?php

use App\Agent\Protocol;
use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Conversation\ResolvePreflight;
use App\Diagnostics\Catalog;
use App\Diagnostics\Preflight;
use App\Enums\Role;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\ConversationMessage;
use App\Models\ConversationSetting;
use App\Models\Diagnostic;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'agent_center_test') {
    throw new RuntimeException('Disposable test database required.');
}
Queue::fake();
if (isset($argv[1])) {
    while (microtime(true) < (float) $argv[4]) {
        usleep(1000);
    }
    (new ResolvePreflight((int) $argv[2], $argv[3]))->handle(app(Engine::class));
    echo 'PASS';
    exit;
}
$ctx = app(TenantContext::class);
$ctx->apply(platform: true);
$t = Tenant::create(['name' => 'Preflight concurrency fixture', 'slug' => 'preflight-'.Str::uuid(), 'status' => 'active']);
$u = (new User)->forceFill(['name' => 'Fixture', 'email' => Str::uuid().'@fixture.test', 'password' => 'Fixture-Only-Passw0rd!', 'tenant_id' => $t->id, 'role' => Role::Operator, 'status' => 'active', 'must_change_password' => false]);
$u->save();
$ctx->forUser($u);
(new ConversationSetting)->forceFill(['tenant_id' => $t->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $u->id])->save();
$a = (new Agent)->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'name' => 'Fixture', 'hostname' => 'lab', 'version' => '0.6.5', 'status' => 'active', 'token_hash' => hash('sha256', Str::random(64)), 'capabilities' => Protocol::CAPABILITIES, 'last_heartbeat_at' => now(), 'enrolled_at' => now(), 'metadata' => ['task_protocol' => 1, 'mtr_capability_confirmed_at' => now()->toIso8601String()]]);
$a->save();
app(Catalog::class)->ensure();
$engine = app(Engine::class);
$c = $engine->create(Identity::web($u), (string) Str::uuid(), $a->id);
$c = $engine->send(Identity::web($u), $c, (string) Str::uuid(), 'Quero analisar a rota para 8.8.8.8.');
$r = app(Preflight::class)->exchange($a, null);
app(Preflight::class)->exchange($a, ['schema_version' => 1, 'id' => $r['id'], 'state' => 'DENIED', 'reason' => 'policy_denied', 'helper_healthy' => null, 'agent_version' => '0.6.5', 'policy_digest' => $r['policy_digest']]);
$children = [];
$when = microtime(true) + 0.5;
for ($n = 0; $n < 2; $n++) {
    $p = proc_open([PHP_BINARY, __FILE__, 'resolve', (string) $t->id, $c->id, (string) $when], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, options: ['bypass_shell' => true]);
    if (! is_resource($p)) {
        throw new RuntimeException('Child failed to start');
    }
    fclose($pipes[0]);
    $children[] = [$p, $pipes];
}
foreach ($children as [$p, $pipes]) {
    $out = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($p) !== 0 || $out !== 'PASS') {
        throw new RuntimeException('Concurrent preflight resolution failed: '.$error);
    }
}
if (ConversationMessage::where('conversation_id', $c->id)->where('metadata->diagnostic_planning->status', 'DENIED')->count() !== 1 || isset($c->fresh()->context_json['pending']) || AgentTask::count() !== 0 || Diagnostic::count() !== 0) {
    throw new RuntimeException('Duplicate notification, confirmation or task');
}
$ctx->apply();
echo "Preflight concurrency: PASS (two terminal callbacks; exactly one notice; no confirmation or task)\n";
