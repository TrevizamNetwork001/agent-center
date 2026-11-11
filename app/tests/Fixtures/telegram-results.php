<?php

use App\Models\AgentTask;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Diagnostic;
use App\Models\User;
use App\Telegram\Link;
use App\Telegram\Outbound;
use App\Telegram\Update;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

if (getenv('TELEGRAM_LAB_FIXTURE') !== '1' || DB::connection()->getDatabaseName() !== 'agent_center_telegram_lab') {
    throw new RuntimeException('Telegram disposable LAB only');
}
$demo = json_decode(file_get_contents('/lab/demo.json'), true, flags: JSON_THROW_ON_ERROR);
$ctx = app(TenantContext::class);
$ctx->apply($demo['a']['tenant'], $demo['a']['user']);
$user = User::findOrFail($demo['a']['user']);
$ctx->forUser($user);
$link = Link::where('user_id', $user->id)->latest('created_at')->first();
if ($link?->conversation_id) {
    $conversation = Conversation::findOrFail($link->conversation_id);
    $flow = json_decode(file_get_contents('/lab/flow.json'), true, flags: JSON_THROW_ON_ERROR);
    $flow['conversation'] = $conversation->id;
    file_put_contents('/lab/flow.json', json_encode($flow));
    $out = ['status' => 'PASS', 'transport' => 'Telegram HTTP API fixture, real database queue', 'link_status' => $link->status, 'state' => $conversation->state, 'tone' => $conversation->context_json['tone'] ?? null, 'target' => $conversation->context_json['provided_target'] ?? null, 'pending' => isset($conversation->context_json['pending']), 'tasks' => AgentTask::count(), 'diagnostics' => Diagnostic::count(), 'messages' => ConversationMessage::where('conversation_id', $conversation->id)->orderBy('sequence')->get(['sender_type', 'body'])->toArray(), 'updates' => Update::select('status')->get()->groupBy('status')->map->count(), 'outbox' => Outbound::select('status')->get()->groupBy('status')->map->count()];
    if ($out['tasks'] || $out['diagnostics'] || DB::table('failed_jobs')->count()) {
        throw new RuntimeException('Unexpected LAB task or failed queue job');
    }
    $suffix = $link->status === 'REVOKED' ? '-revoked' : '';
    file_put_contents('/lab/evidence/functional'.$suffix.'.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
}
$ctx->apply();
