<?php

use App\Agent\Protocol;
use App\Conversation\DiagnosticExperience;
use App\Diagnostics\IntentPlanning;
use App\Diagnostics\Planner;
use App\Models\ConversationMessage;
use App\Models\Diagnostic;
use App\Models\DiagnosticPlan;
use App\Models\Profile;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (getenv('INTENT_LAB_FIXTURE') !== '1') {
    throw new RuntimeException('LAB only');
}
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'intent_planning_v1_lab') {
    throw new RuntimeException('Wrong database');
}
$ctx = app(TenantContext::class);
$ctx->apply(platform: true);
$user = User::withoutGlobalScopes()->where('email', 'admin-a@intent.test')->sole();
$ctx->forUser($user);
$demo = json_decode(file_get_contents('/lab/demo.json'), true, flags: JSON_THROW_ON_ERROR);
$plan = DiagnosticPlan::where('diagnostic_id', $demo['diagnostic'])->sole()->document;
$old = app(Planner::class)->plan(Profile::where('key', 'SITE_LENTO')->sole(), '8.8.8.8', Protocol::CAPABILITIES);
$proof = ['status' => 'PASS', 'fixtures_only' => true, 'production_probes' => 0,
    'before' => ['profile' => $old['profile'], 'steps' => array_column($old['steps'], 'key')],
    'after' => ['profile' => $plan['profile'], 'objective' => $plan['objective'], 'steps' => array_map(fn ($step) => array_intersect_key($step, array_flip(['key', 'skill', 'capability', 'input', 'optional', 'condition'])), $plan['steps'])],
    'confirmation' => app(IntentPlanning::class)->confirmation($plan),
    'result' => app(DiagnosticExperience::class)->snapshot(Diagnostic::findOrFail($demo['diagnostic']), $user),
    'historical_mismatch' => app(DiagnosticExperience::class)->snapshot(Diagnostic::findOrFail($demo['mismatch']), $user)];
foreach (['route', 'ambiguous', 'blocked'] as $key) {
    $proof['web'][$key] = ConversationMessage::where('conversation_id', $demo[$key])->orderBy('sequence')->get()->map(fn ($message) => ['sender' => $message->sender_type, 'type' => $message->message_type, 'body' => $message->body])->all();
}
file_put_contents('/lab/proof.json', json_encode($proof, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo "Intent planning proof exported: synthetic evidence only.\n";
