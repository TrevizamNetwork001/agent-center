<?php

use App\Agent\Protocol;
use App\Conversation\Engine as Conversations;
use App\Conversation\Identity;
use App\Diagnostics\Catalog;
use App\Diagnostics\Classifier;
use App\Diagnostics\Engine;
use App\Diagnostics\OperationalCapabilities;
use App\Models\Agent;
use App\Models\ConversationSetting;
use App\Models\Diagnostic;
use App\Models\DiagnosticConclusion;
use App\Models\Profile;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\MtrFixture;
use Tests\Support\PathReadinessFixture;

if (getenv('INTENT_LAB_FIXTURE') !== '1') {
    throw new RuntimeException('LAB only');
}
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! in_array(DB::connection()->getDatabaseName(), ['intent_planning_v1_lab', 'intent_planning_compat_lab'], true)) {
    throw new RuntimeException('Wrong database');
}
$ctx = app(TenantContext::class);
$ctx->apply(platform: true);
$users = [];
foreach (['a', 'b'] as $suffix) {
    $tenant = Tenant::create(['name' => 'Intent LAB '.strtoupper($suffix), 'slug' => 'intent-lab-'.$suffix, 'status' => 'active']);
    $u = new User;
    $u->forceFill(['name' => 'Administrador LAB', 'email' => 'admin-'.$suffix.'@intent.test', 'password' => Hash::make(trim(file_get_contents('/lab/password'))), 'role' => 'tenant_admin', 'tenant_id' => $tenant->id, 'status' => 'active', 'must_change_password' => false])->save();
    $users[] = $u;
}
$u = $users[0];
$ctx->forUser($u);
(new ConversationSetting)->forceFill(['tenant_id' => $u->tenant_id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $u->id])->save();
app(Catalog::class)->ensure();
$agent = new Agent;
$agent->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $u->tenant_id, 'name' => 'Agent sintético · laboratório', 'hostname' => 'intent-fixture', 'version' => '0.6.2', 'status' => 'active', 'token_hash' => hash('sha256', random_bytes(32)),
    'capabilities' => Protocol::CAPABILITIES, 'metadata' => ['task_protocol' => 1, 'mtr_capability_confirmed_at' => now()->toIso8601String()], 'enrolled_at' => now()])->save();
$fixture = new class
{
    use PathReadinessFixture;

    public function ready(Agent $agent): void
    {
        $this->pathReady($agent);
    }
};
$fixture->ready($agent);
$identity = Identity::web($u);
$conversations = app(Conversations::class);
$chat = $conversations->create($identity, (string) Str::uuid(), $agent->id);
$chat = $conversations->send($identity, $chat, (string) Str::uuid(), 'Queria avaliar essa rota aqui 8.8.8.8, ver se está boa.');
$chat = $conversations->send($identity, $chat, (string) Str::uuid(), 'Sim, confirmo');
$engine = app(Engine::class);
$engine->tick();
$task = $engine->claim($agent);
if (! $task || $task['capability'] !== 'mtr_path_analysis:v1') {
    throw new RuntimeException('Wrong plan');
}
$envelope = array_intersect_key($task, array_flip(['task_id', 'execution_id', 'generation', 'lease_token']));
$engine->operation($agent, 'start', $envelope);
$engine->operation($agent, 'result', $envelope + ['result' => ['ok' => true, 'data' => MtrFixture::data('isolated-loss')]]);
$chat = $conversations->sync($identity, $chat);
$ambiguous = $conversations->create($identity, (string) Str::uuid(), $agent->id);
$ambiguous = $conversations->send($identity, $ambiguous, (string) Str::uuid(), '8.8.8.8');
app(OperationalCapabilities::class)->set($agent, 'mtr_path_analysis:v1', '8.8.8.8', 'icmp', ['state' => 'ALLOWED', 'helper_healthy' => false]);
$blocked = $conversations->create($identity, (string) Str::uuid(), $agent->id);
$blocked = $conversations->send($identity, $blocked, (string) Str::uuid(), 'Analise a rota até 8.8.8.8');
// Historical mismatch fixture proves that NORMAL connectivity never covers a route objective.
$old = (new Diagnostic)->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $u->tenant_id, 'profile_id' => Profile::where('key', 'SITE_LENTO')->sole()->id, 'agent_id' => $agent->id, 'created_by' => $u->id,
    'target' => '8.8.8.8', 'status' => 'COMPLETED', 'context' => ['operational_intent' => 'ROUTE_PATH_ANALYSIS'], 'started_at' => now(), 'finished_at' => now()]);
$old->save();
(new DiagnosticConclusion)->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $u->tenant_id, 'diagnostic_id' => $old->id, 'classification' => 'NORMAL', 'summary' => Classifier::NORMAL,
    'evidence' => ['tcp' => ['ok' => true, 'data' => ['connected' => true, 'duration_ms' => 1]], 'https' => ['ok' => true, 'data' => ['status' => 200, 'tls' => true, 'resolved_ip' => '8.8.8.8', 'duration_ms' => 2]]], 'next_steps' => ['Solicite análise de rota com Profile de caminho.'], 'created_at' => now()])->save();
file_put_contents('/lab/demo.json', json_encode(['route' => $chat->id, 'diagnostic' => $chat->current_diagnostic_id, 'ambiguous' => $ambiguous->id, 'blocked' => $blocked->id, 'mismatch' => $old->id], JSON_PRETTY_PRINT));
echo "Synthetic LAB fixtures ready. No Agent runtime or network probes.\n";
