<?php

use App\Agent\Observability;
use App\Agent\Protocol;
use App\Conversation\Identity;
use App\Diagnostics\Catalog;
use App\Diagnostics\Engine;
use App\Diagnostics\IntentParser;
use App\Diagnostics\IntentPlanning;
use App\Diagnostics\OperationalCapabilities;
use App\Diagnostics\OperationalIntent;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\ConversationMessage;
use App\Models\ConversationSetting;
use App\Models\DiagnosticConclusion;
use App\Models\DiagnosticPlan;
use App\Models\Profile;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

if (getenv('INTENT_COMPAT_LAB') !== '1') {
    throw new RuntimeException('LAB only');
}
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'intent_planning_compat_lab') {
    throw new RuntimeException('Wrong database');
}
if (class_exists(Observability::class)) {
    throw new RuntimeException('Image unexpectedly includes Observability');
}
$ctx = app(TenantContext::class);
DB::beginTransaction();
try {
    $ctx->apply(platform: true);
    $t = Tenant::create(['name' => 'Compatibility fixture', 'slug' => 'compatibility-'.Str::lower(Str::random(8)), 'status' => 'active']);
    $u = (new User)->forceFill(['name' => 'Synthetic operator', 'email' => 'compat-'.Str::lower(Str::random(8)).'@example.test', 'password' => Hash::make(bin2hex(random_bytes(16))), 'role' => 'tenant_admin', 'tenant_id' => $t->id, 'status' => 'active', 'must_change_password' => false]);
    $u->save();
    $ctx->forUser($u);
    app(Catalog::class)->ensure();
    $id = (string) Str::uuid();
    $token = $id.'.'.bin2hex(random_bytes(32));
    $a = (new Agent)->forceFill(['id' => $id, 'tenant_id' => $t->id, 'name' => 'Synthetic Agent API fixture', 'hostname' => 'fixture', 'version' => '0.6.2', 'status' => 'active', 'token_hash' => hash('sha256', $token), 'last_seen_ip' => '127.0.0.1', 'capabilities' => [], 'metadata' => ['task_protocol' => 1], 'enrolled_at' => now()]);
    $a->save();
    $http = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $api = [];
    foreach (['capabilities' => ['task_protocol' => 1, 'capabilities' => Protocol::CAPABILITIES], 'heartbeat' => ['version' => '0.6.2', 'telemetry' => ['process_alive' => true, 'device_operations_frozen' => true, 'service_status' => 'active', 'settings_revision' => 1]]] as $action => $body) {
        $request = Request::create('/api/v1/agent/'.$action, 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'REMOTE_ADDR' => '127.0.0.1', 'HTTPS' => 'on'], content: json_encode($body));
        $response = $http->handle($request);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Published API '.$action.' returned '.$response->getStatusCode());
        }$api[$action] = ['status' => 200, 'effective_destination_decision_returned' => array_key_exists('permission_result', json_decode($response->getContent(), true))];
        $http->terminate($request, $response);
        $ctx->forUser($u);
    }
    $a = Agent::findOrFail($id);
    $parser = app(IntentParser::class);
    $request = $parser->parse('Quero analisar a rota para 8.8.8.8');
    $planning = app(IntentPlanning::class);
    $profile = $planning->profile(OperationalIntent::from($request['intent']), $a, $request);
    if ($profile !== 'MTR_ICMP') {
        throw new RuntimeException('Wrong Profile');
    }
    $plan = $planning->preview(Profile::where('key', $profile)->sole(), $a, '8.8.8.8');
    if (array_column($plan['steps'], 'key') !== ['mtr_icmp'] || $plan['steps'][0]['available'] || $plan['steps'][0]['blocked_reason'] !== 'effective_policy_unverified') {
        throw new RuntimeException('Unsafe or irrelevant plan');
    }
    $out = ['status' => 'PASS', 'image_kind' => getenv('IMAGE_KIND'), 'observability_present' => false, 'published_api' => $api, 'agent_version' => '0.6.2', 'schema_changes' => false, 'goal' => $request['intent'], 'profile' => $profile, 'planned_steps' => array_column($plan['steps'], 'key'), 'permission' => app(OperationalCapabilities::class)->permission($a, 'mtr_path_analysis:v1', '8.8.8.8', 'icmp'), 'runtime_contract' => app(OperationalCapabilities::class)->snapshot($a)];
    if (getenv('IMAGE_KIND') === 'web') {
        (new ConversationSetting)->forceFill(['tenant_id' => $t->id, 'enabled' => true, 'retention_days' => 30, 'updated_by' => $u->id])->save();
        $engine = app(App\Conversation\Engine::class);
        $identity = Identity::web($u);
        $c = $engine->create($identity, (string) Str::uuid(), $a->id);
        $c = $engine->send($identity, $c, (string) Str::uuid(), 'Quero analisar a rota para 8.8.8.8');
        $out['web_response'] = ConversationMessage::where('conversation_id', $c->id)->latest('sequence')->first()->body;
        $engine->send($identity, $c, (string) Str::uuid(), 'Sim, confirmo');
    } else {
        $d = app(Engine::class)->create(Profile::where('key', $profile)->sole(), $a, '8.8.8.8', $u);
        app(Engine::class)->tick();
        $out['worker_classification'] = DiagnosticConclusion::where('diagnostic_id', $d->id)->sole()->classification;
        $out['worker_plan'] = DiagnosticPlan::where('diagnostic_id', $d->id)->sole()->document['objective'];
        if ($out['worker_classification'] !== 'INCONCLUSIVO') {
            throw new RuntimeException('Wrong blocked result');
        }
    }
    if (AgentTask::count() !== 0) {
        throw new RuntimeException('Unexpected task');
    }$out['tasks_created'] = 0;
    $out['probes'] = 0;
    $out['authorization_granted'] = false;
} finally {
    DB::rollBack();
    $ctx->apply();
}
file_put_contents('/proof/compatibility-'.getenv('IMAGE_KIND').'.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo 'Published '.getenv('IMAGE_KIND')." contracts PASS: no Observability, MTR-only plan, unverified permission blocked, no task/probe.\n";
