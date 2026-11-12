<?php

use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Enums\Role;
use App\Models\AgentTask;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationSetting;
use App\Models\Diagnostic;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('testing') || DB::connection()->getDatabaseName() !== 'agent_center_test') {
    throw new RuntimeException('Disposable test database required.');
}
$context = app(TenantContext::class);
if (isset($argv[1])) {
    $tenant = (int) $argv[2];
    $context->apply(userId: (int) $argv[3]);
    $user = User::findOrFail((int) $argv[3]);
    $context->forUser($user, $tenant);
    config(['conversation.idle_enabled' => true, 'conversation.idle_tenants' => [$tenant]]);
    $c = Conversation::findOrFail($argv[4]);
    while (microtime(true) < (float) $argv[5]) {
        usleep(1000);
    }
    $engine = app(Engine::class);
    $identity = Identity::web($user);
    $result = $argv[1] === 'close' ? $engine->closeIdle($identity, $c) : $engine->send($identity, $c, $argv[6], 'Boa noite!');
    echo json_encode(['conversation' => $result->id, 'state' => $result->state]);
    $context->apply();
    exit;
}
function idleCheck(bool $condition, string $reason): void
{
    if (! $condition) {
        throw new RuntimeException($reason);
    }
}
function idleSpawn(string $mode, int $tenant, int $user, string $conversation, float $when, string $key): array
{
    $process = proc_open([PHP_BINARY, __FILE__, $mode, (string) $tenant, (string) $user, $conversation, (string) $when, $key], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, options: ['bypass_shell' => true]);
    idleCheck(is_resource($process), 'Child failed to start.');
    fclose($pipes[0]);

    return [$process, $pipes];
}
function idleCollect(array $child): array
{
    [$process, $pipes] = $child;
    $json = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    idleCheck(proc_close($process) === 0, 'Concurrent operation failed.');

    return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
}
$context->apply(platform: true);
$tenant = Tenant::create(['name' => 'Idle concurrency fixture', 'slug' => 'idle-'.Str::uuid(), 'status' => 'active']);
$user = (new User)->forceFill(['name' => 'Fixture', 'email' => Str::uuid().'@fixture.test', 'password' => 'Fixture-Only-Passw0rd!', 'tenant_id' => $tenant->id, 'role' => Role::Operator, 'status' => 'active', 'must_change_password' => false]);
$user->save();
$context->forUser($user);
(new ConversationSetting)->forceFill(['tenant_id' => $tenant->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $user->id])->save();
$before = [AgentTask::count(), Diagnostic::count()];
foreach (['close', 'send', 'send'] as $mode) {
    $c = app(Engine::class)->create(Identity::web($user), (string) Str::uuid());
    $c->forceFill(['last_activity_at' => now()->subMinutes(16)])->save();
    $when = microtime(true) + 0.5;
    $key = (string) Str::uuid();
    $first = idleSpawn('close', $tenant->id, $user->id, $c->id, $when, $key);
    $second = idleSpawn($mode, $tenant->id, $user->id, $c->id, $when, $key);
    idleCollect($first);
    $result = idleCollect($second);
    $notices = ConversationMessage::where('conversation_id', $c->id)->where('event_key', 'idle_closed')->count();
    idleCheck($notices <= 1, 'Duplicate inactivity notice.');
    if ($mode === 'close') {
        idleCheck($notices === 1 && $c->fresh()->state === 'CLOSED', 'Two closers did not converge.');
    } else {
        $current = Conversation::findOrFail($result['conversation']);
        idleCheck($current->status === 'OPEN', 'Real interaction lost to sweep.');
        idleCheck(ConversationMessage::where('request_key', $key)->count() === 1, 'User message duplicated or lost.');
        idleCheck($current->id === $c->id || $c->fresh()->state === 'CLOSED', 'Wrong restart state.');
    }
}
idleCheck($before === [AgentTask::count(), Diagnostic::count()], 'Unexpected operational task.');
$context->apply();
echo "Conversation idle concurrency: PASS (two closers; message versus closer; no tasks)\n";
