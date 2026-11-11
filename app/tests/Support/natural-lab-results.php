<?php

use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (getenv('NATURAL_LAB_FIXTURE') !== '1') {
    throw new RuntimeException('Disposable lab only');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'natural_conversation_lab') {
    throw new RuntimeException('Wrong laboratory database');
}
$ctx = app(TenantContext::class);
$ctx->apply(userId: 1);
$user = User::findOrFail(1);
$ctx->forUser($user);
$agent = Agent::sole();
if ($agent->hostname !== 'synthetic-lab') {
    throw new RuntimeException('Synthetic Agent required');
}
$tasks = app(App\Diagnostics\Engine::class);
$tasks->tick();
for ($n = 0; $n < 30; $n++) {
    $task = $tasks->claim($agent);
    if (! $task) {
        break;
    }
    $tasks->operation($agent, 'start', $task);
    $tasks->operation($agent, 'result', $task + ['result' => ['ok' => false, 'error' => 'policy_denied']]);
}
$role = DB::selectOne('SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolname=current_user');
$rls = DB::select("SELECT relname, relrowsecurity, relforcerowsecurity FROM pg_class WHERE relnamespace='public'::regnamespace AND relname IN ('conversations','conversation_messages','conversation_events','llm_bindings','llm_providers','prompts','rag_documents','rag_chunks') ORDER BY relname");
$out = ['runtime_role' => $role, 'rls' => $rls, 'environment' => 'synthetic transport/Agent fixture, no probes or real device', 'conversations' => []];
$engine = app(Engine::class);
foreach (json_decode(file_get_contents('/lab/demo.json'), true) as $key => $id) {
    $c = $engine->sync(Identity::web($user), Conversation::findOrFail($id));
    $out['conversations'][$key] = ['state' => $c->state, 'messages' => ConversationMessage::where('conversation_id', $id)->orderBy('sequence')->get()->map(fn ($m) => ['sender' => $m->sender_type, 'type' => $m->message_type, 'body' => $m->body, 'intent' => $m->detected_intent, 'language_status' => $m->metadata['language_status'] ?? null])->all()];
}
$out['status'] = ! $role->rolsuper && ! $role->rolbypassrls && count($rls) === 8 && collect($rls)->every(fn ($table) => $table->relrowsecurity && $table->relforcerowsecurity) && $out['conversations']['confirmation']['state'] === 'DIAGNOSTIC_COMPLETED' ? 'PASS' : 'BLOCKED';
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
exit($out['status'] === 'PASS' ? 0 : 1);
