<?php

namespace App\Telegram;

use App\Conversation\ResolvePreflight;
use App\Diagnostics\Preflight;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Diagnostic;
use App\Models\NocDevice;
use App\Models\NocSimulation;
use App\Models\Tenant;
use App\Noc\Analysis\Contracts;
use App\Noc\Analysis\SimulationScope;
use App\Noc\ReadOnlyLab\RouterOs7\Integration;
use App\Rag\Retrieval;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class Deliver implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $tenant, public string $outboundId) {}

    public function handle(Client $client): void
    {
        app(TenantContext::class)->apply($this->tenant);
        try {
            // Durable send claim before HTTP. A crash/timeout never automatically resends an ambiguous delivery.
            $claimed = DB::transaction(function () {
                $o = Outbound::whereKey($this->outboundId)->lockForUpdate()->first();
                if (! $o || $o->status !== 'PENDING' || $o->next_attempt_at->isFuture()) {
                    return false;
                }
                $o->forceFill(['status' => 'SENDING', 'attempts' => $o->attempts + 1])->save();

                return true;
            });
            if (! $claimed) {
                return;
            }
            DB::transaction(function () use ($client) {
                $o = Outbound::whereKey($this->outboundId)->lockForUpdate()->firstOrFail();
                $bot = Bot::whereKey($o->bot_id)->sharedLock()->firstOrFail();
                $allowed = Tenant::whereKey($bot->tenant_id)->where('status', 'active')->exists() && config('telegram.enabled') && $bot->enabled && $bot->generation === $o->generation;
                $group = $o->group_id ? Group::whereKey($o->group_id)->sharedLock()->first() : null;
                if ($o->group_id) {
                    $allowed = $allowed && $group && $group->generation === $o->group_generation && $group->bot_generation === $bot->generation && ($group->status === 'ACTIVE' || ($group->status === 'AWAITING_APPROVAL' && ! $o->link_id && ! $o->message_id));
                }
                $body = $o->body_cipher ? Crypt::decryptString($o->body_cipher) : '';
                $tracking = false;
                $diagnostic = null;
                if ($o->link_id) {
                    $link = Link::whereKey($o->link_id)->lockForUpdate()->firstOrFail();
                    try {
                        $identity = app(Accounts::class)->identity($link);
                        $allowed = $allowed && app(Access::class)->allows($link, $group);
                        if ($group) {
                            $context = GroupContext::whereKey($o->context_id)->where('group_id', $group->id)->where('link_id', $link->id)->first();
                            $destination = $group->reply_mode === 'group' ? $group->chat_id : $link->chat_id;
                            $allowed = $allowed && $context && $context->generation === $group->generation && (string) $destination === (string) $o->chat_id && ($group->reply_mode !== 'group' || $group->shared_ack) && $context->thread_id === $o->thread_id;
                        } else {
                            $allowed = $allowed && ! $o->context_id && (string) $o->chat_id === (string) $link->chat_id;
                        }

                    } catch (\Throwable) {
                        $allowed = false;
                    }
                    if ($allowed && $o->message_id) {
                        $m = ConversationMessage::where('conversation_id', $o->conversation_id)->whereKey($o->message_id)->firstOrFail();
                        $conversation = Conversation::findOrFail($o->conversation_id);
                        $allowed = $allowed && $conversation->user_id === $link->user_id && $conversation->contact_id === $link->id && $conversation->telegram_context_id === ($o->context_id ?: null);
                        $body = mb_substr($m->body, 0, 2300);
                        if (($m->metadata['diagnostic_planning']['status'] ?? null) === 'PENDING') {
                            $watch = $conversation->context_json['preflight_watch'] ?? [];
                            $agent = Agent::find($watch['agent_id'] ?? null);
                            $proof = $agent ? app(Preflight::class)->observation($agent, $watch['request'] ?? []) : [];
                            if (($watch['request']['id'] ?? null) !== ($m->metadata['diagnostic_planning']['request_id'] ?? null) || ($proof['reason'] ?? null) !== 'preflight_pending') {
                                $o->forceFill(['status' => 'SUPPRESSED', 'body_cipher' => null, 'last_error' => 'SUPERSEDED_PREFLIGHT_PENDING'])->save();
                                ResolvePreflight::dispatch($this->tenant, $conversation->id)->afterCommit();

                                return;
                            }
                        }
                        if (($m->metadata['diagnostic_planning']['authorization'] ?? null) === 'ALLOWED' && isset($m->metadata['diagnostic_planning']['request_id'])) {
                            $watch = $conversation->context_json['preflight_watch'] ?? [];
                            $agent = Agent::find($watch['agent_id'] ?? null);
                            $proof = $agent ? app(Preflight::class)->observation($agent, $watch['request'] ?? []) : [];
                            $current = ($proof['state'] ?? null) === 'ALLOWED' && ($watch['request']['id'] ?? null) === ($m->metadata['diagnostic_planning']['request_id'] ?? null)
                                && $conversation->state === 'WAITING_FOR_CONFIRMATION' && ($group ? $context->conversation_id : $link->conversation_id) === $conversation->id;
                            if (! $current) {
                                $o->forceFill(['status' => 'SUPPRESSED', 'body_cipher' => null, 'last_error' => 'PREFLIGHT_NO_LONGER_VALID'])->save();
                                ResolvePreflight::dispatch($this->tenant, $conversation->id)->afterCommit();

                                return;
                            }
                        }
                        if (($m->metadata['simulation'] ?? false) === true && (isset($m->metadata['routeros_binding_digest']) || isset($m->metadata['diagnostic_id']))) {
                            $allowed = $allowed && Integration::enabled();
                            if (isset($m->metadata['routeros_binding_digest'])) {
                                $input = $conversation->context_json['pending']['routeros_input'] ?? [];
                                $agent = Agent::find($input['binding']['agent_id'] ?? '');
                                $allowed = $allowed && $agent && ($input['binding_digest'] ?? '') === $m->metadata['routeros_binding_digest'] && app(Preflight::class)->deviceTask($agent, $input) === null;
                            } else {
                                $lab = Diagnostic::find($m->metadata['diagnostic_id']);
                                $allowed = $allowed && $lab && ($lab->context['routeros_lab'] ?? false) === true && $lab->created_by === $identity->user->id;
                                if ($allowed) {
                                    Gate::forUser($identity->user)->authorize('view', $lab);
                                }
                            }
                        }
                        if (isset($m->metadata['device_simulation_id'])) {
                            $sim = NocSimulation::whereKey($m->metadata['device_simulation_id'])->where('actor_id', $identity->user->id)->first();
                            $device = $sim ? NocDevice::find($sim->device_id) : null;
                            $allowed = $allowed && app(Contracts::class)->labEnabled() && $device && ($sim->result['mode'] ?? null) === 'SIMULATED' && ($sim->result['simulation_scope'] ?? null) !== SimulationScope::AdministrativeDemo->value;
                            if (isset($sim->result['transport'])) {
                                $allowed = $allowed && app(\App\Noc\ReadOnlyLab\Access::class)->enabled();
                            }
                            if ($allowed) {
                                Gate::forUser($identity->user)->authorize('simulate', $device);
                            }
                        }
                        $tracking = ($m->metadata['diagnostic_experience'] ?? '') === 'v2' && ($m->metadata['tracking'] ?? false) === true;
                        if ($tracking) {
                            $diagnostic = Diagnostic::find($m->metadata['diagnostic_id'] ?? null);
                            $allowed = $allowed && $diagnostic && $diagnostic->created_by === $conversation->user_id
                                && ($conversation->current_diagnostic_id === $diagnostic->id || in_array($diagnostic->id, $conversation->context_json['diagnostic_ids'] ?? [], true));
                            if ($allowed) {
                                Gate::forUser($identity->user)->authorize('view', $diagnostic);
                                $superseded = ConversationMessage::where('conversation_id', $conversation->id)->where('sequence', '>', $m->sequence)
                                    ->where('metadata->diagnostic_experience', 'v2')->where('metadata->tracking', true)->where('metadata->diagnostic_id', $diagnostic->id)->exists();
                                if ($superseded) {
                                    $o->forceFill(['status' => 'SUPPRESSED', 'body_cipher' => null, 'last_error' => 'SUPERSEDED_PROGRESS'])->save();

                                    return;
                                }
                            }
                        }
                        $sources = [];
                        foreach ($m->metadata['rag_sources'] ?? [] as $sourceId) {
                            $source = app(Retrieval::class)->source($identity->user, $sourceId);
                            if (! $source) {
                                $allowed = false;
                                break;
                            }
                            $sources[] = 'Fonte: versão '.$source['version_number'].', trecho '.($source['position'] + 1).' — '.mb_substr($source['title'], 0, 80).' — '.route('rag.source', $sourceId);
                        }
                        if ($sources) {
                            $body .= "\n\n".implode("\n", $sources);
                        }
                        if ($group) {
                            $heading = 'Grupo: '.mb_substr($group->chat_title ?: 'Grupo da empresa', 0, 150).($o->thread_id > 0 ? ' · tópico '.$o->thread_id : '');
                            if ($group->reply_mode === 'private') {
                                $heading .= "\nPara continuar este atendimento, envie /perguntar@".$bot->username.' sua mensagem no mesmo grupo'.($o->thread_id > 0 ? ' e tópico' : '').'.';
                            }
                            $body = $heading."\n\n".$body;
                        }
                        if ($tracking && mb_strlen($m->body) > 2300) {
                            $body .= "\n\nResumo reduzido. Abra os detalhes pelo botão.";
                        } elseif (! $tracking && (mb_strlen($m->body) > 2300 || in_array($m->message_type, ['result', 'interpretation'], true) || isset($m->metadata['diagnostic_id']))) {
                            $body .= "\n\nHistórico: ".route('conversations.show', $o->conversation_id);
                        }
                    }
                }
                if (! $allowed) {
                    $o->forceFill(['status' => 'SUPPRESSED', 'body_cipher' => null, 'last_error' => 'ACCESS_OR_SOURCE_REVOKED'])->save();

                    return;
                }
                $data = ['chat_id' => $o->chat_id, 'text' => mb_substr(preg_replace('/\b[0-9]{5,20}:[A-Za-z0-9_-]{30,100}\b/', '[CREDENCIAL_REMOVIDA]', $body), 0, 4000), 'link_preview_options' => ['is_disabled' => true]];
                $editId = null;
                if ($tracking && $diagnostic) {
                    // Link/context locks above serialize this family. Only already-sent,
                    // earlier messages for this same tenant/user/chat/topic/diagnostic may be edited.
                    $previous = Outbound::where('conversation_id', $o->conversation_id)->where('link_id', $o->link_id)
                        ->where('bot_id', $o->bot_id)->where('generation', $o->generation)->where('chat_id', $o->chat_id)
                        ->where('context_id', $o->context_id)->where('thread_id', $o->thread_id)->where('status', 'SENT')->whereNotNull('telegram_message_id')
                        ->whereIn('message_id', ConversationMessage::where('conversation_id', $o->conversation_id)->where('sequence', '<', $m->sequence)
                            ->where('metadata->diagnostic_experience', 'v2')->where('metadata->tracking', true)->where('metadata->diagnostic_id', $diagnostic->id)->select('id'))
                        ->orderByDesc(ConversationMessage::select('sequence')->whereColumn('conversation_messages.id', 'telegram_outbox.message_id')->limit(1))->first();
                    $editId = $previous?->telegram_message_id;
                    $url = rtrim(config('app.url'), '/').route('diagnostics.show', $diagnostic->id, false);
                    abort_unless(parse_url($url, PHP_URL_SCHEME) === 'https' && ! parse_url($url, PHP_URL_QUERY) && ! parse_url($url, PHP_URL_USER), 422);
                    $data['reply_markup'] = ['inline_keyboard' => [[['text' => 'Ver diagnóstico completo', 'url' => $url]]]];
                }
                if ($editId) {
                    $o->forceFill(['telegram_message_id' => $editId])->save();
                    $data['message_id'] = $editId;
                    $r = $client->call($bot, 'editMessageText', $data);
                    if ($r['status'] === 'EDIT_UNAVAILABLE') {
                        unset($data['message_id']);
                        $editId = null;
                        $o->forceFill(['telegram_message_id' => null])->save();
                        $r = $client->call($bot, 'sendMessage', [...$data, 'protect_content' => true, ...($group && $group->reply_mode === 'group' && $o->thread_id > 0 ? ['message_thread_id' => $o->thread_id] : [])]);
                    }
                } else {
                    $r = $client->call($bot, 'sendMessage', [...$data, 'protect_content' => true, ...($group && $group->reply_mode === 'group' && $o->thread_id > 0 ? ['message_thread_id' => $o->thread_id] : [])]);
                }
                $status = match ($r['status']) {
                    'OK' => 'SENT', 'RATE_LIMITED' => $o->attempts < 5 ? 'PENDING' : 'FAILED', 'DELIVERY_UNKNOWN' => $editId && $o->attempts < 5 ? 'PENDING' : 'UNKNOWN', default => 'FAILED'
                };
                $o->forceFill(['status' => $status, 'telegram_message_id' => $editId ?? $r['result']['message_id'] ?? null, 'last_error' => $r['status'] === 'OK' ? null : $r['status'], 'next_attempt_at' => now()->addSeconds($r['retry_after'] ?? 60), 'body_cipher' => $status === 'SENT' ? null : $o->body_cipher])->save();
                if ($status !== 'SENT') {
                    app(Accounts::class)->audit('telegram.delivery_failed');
                }
                if ($r['status'] === 'BOT_BLOCKED' && isset($link)) {
                    app(Accounts::class)->revoke($link, $identity->user);
                }
            });
        } catch (\Throwable) {
            // SENDING is converted to UNKNOWN by the recovery sweep, never blindly retried.
        } finally {
            app(TenantContext::class)->apply();
        }
    }
}
