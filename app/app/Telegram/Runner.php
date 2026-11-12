<?php

namespace App\Telegram;

use App\Conversation\Engine;
use App\Conversation\Identity;
use App\Conversation\Intent;
use App\Conversation\IntentDetector;
use App\Conversation\State;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class Runner
{
    public function process(int $tenant, string $id): void
    {
        app(TenantContext::class)->apply($tenant);
        try {
            DB::transaction(function () use ($id) {
                $row = Update::whereKey($id)->lockForUpdate()->first();
                if (! $row || $row->status !== 'PENDING' || $row->next_attempt_at->isFuture()) {
                    return;
                }
                $bot = Bot::whereKey($row->bot_id)->sharedLock()->firstOrFail();
                if (! config('telegram.enabled') || ! $bot->enabled || $row->generation !== $bot->generation || $row->message_date->lt(now()->subMinutes(15))) {
                    $row->forceFill(['status' => 'IGNORED', 'payload_cipher' => null, 'last_error' => 'STALE_OR_DISABLED'])->save();

                    return;
                }
                $group = $row->group_id ? Group::whereKey($row->group_id)->sharedLock()->first() : null;
                if ($row->chat_type !== 'private' && $row->group_id && (! $group || $group->status !== 'ACTIVE' || $group->generation !== $row->group_generation || $group->bot_generation !== $bot->generation)) {
                    $row->forceFill(['status' => 'IGNORED', 'payload_cipher' => null, 'last_error' => 'GROUP_REVOKED'])->save();

                    return;
                }
                DB::select('SELECT pg_advisory_xact_lock(7823, ?::integer)', [(int) sprintf('%u', crc32($bot->id.':'.$row->sender_id)) % 2147483647]);
                $text = Crypt::decryptString($row->payload_cipher);
                if ($row->chat_type !== 'private' && ! $group && ! preg_match('~^/vincular_grupo [A-Za-z0-9]{48}$~D', $text)) {
                    $row->forceFill(['status' => 'IGNORED', 'payload_cipher' => null, 'last_error' => 'GROUP_NOT_APPROVED'])->save();

                    return;
                }
                $accounts = app(Accounts::class);
                $link = Link::where('bot_id', $bot->id)->where('telegram_user_id', $row->sender_id)->where('status', 'ACTIVE')->lockForUpdate()->first();
                if ($row->chat_type !== 'private' && preg_match('~^/vincular_grupo ([A-Za-z0-9]{48})$~D', $text, $matches)) {
                    $claimed = app(Groups::class)->claim($bot, $row, $matches[1]);
                    if ($claimed) {
                        $this->notice($bot, $row->chat_id, 'Grupo identificado. Volte ao painel do Agent Center e confirme o grupo da empresa. O atendimento ainda não está liberado.', group: $claimed);
                    }
                } elseif ($row->chat_type === 'private' && preg_match('/^\/start ([A-Za-z0-9]{48})$/D', $text, $matches)) {
                    $pending = Link::where('bot_id', $bot->id)->where('status', 'CODE')->where('code_hash', $accounts->hash($matches[1]))->where('expires_at', '>', now())->lockForUpdate()->first();
                    $occupied = Link::where('bot_id', $bot->id)->where('telegram_user_id', $row->sender_id)->whereIn('status', ['ACTIVE', 'AWAITING_APPROVAL'])->exists();
                    if ($pending && ! $occupied) {
                        $pending->forceFill(['status' => 'AWAITING_APPROVAL', 'telegram_user_id' => $row->sender_id, 'chat_id' => $row->chat_id, 'code_hash' => null])->save();
                        $accounts->audit('telegram.link_claimed');
                        $this->notice($bot, $row->chat_id, 'Código consumido. Confirme o ID Telegram no painel autenticado do Agent Center para concluir o vínculo.');
                    } else {
                        $this->notice($bot, $row->chat_id, 'Código inválido, expirado ou conta já vinculada. Gere um novo vínculo no painel.');
                    }
                } elseif (! $link || ! $this->identified($link, $bot) || ! app(Access::class)->allows($link, $group)) {
                    $rejected = $link !== null || $group !== null;
                    $key = 'telegram:denied:'.$bot->id.':'.$row->chat_id.':'.$row->sender_id;
                    if (! RateLimiter::tooManyAttempts($key, 1)) {
                        RateLimiter::hit($key, 60);
                        $this->notice($bot, $row->chat_id, Access::DENIED, group: $group);
                    }
                } else {
                    $request = Request::create('/telegram-internal');
                    $request->attributes->add(['telegram_verified_link' => $link, 'telegram_request_key' => $row->id, 'telegram_text' => $text]);
                    $incoming = app(TelegramAdapter::class)->message($request);
                    $context = $group ? GroupContext::where('group_id', $group->id)->where('link_id', $link->id)->where('thread_id', $row->thread_id)->lockForUpdate()->first() : $link;
                    if ($group && ! $context) {
                        $context = new GroupContext;
                        $context->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $bot->tenant_id, 'group_id' => $group->id, 'link_id' => $link->id, 'generation' => $group->generation, 'thread_id' => $row->thread_id, 'cursor' => 0])->save();
                    }
                    abort_if($group && $context->generation !== $group->generation, 403);
                    $base = $incoming->identity;
                    $identity = new Identity($base->tenantId, $base->user, $base->channel, $base->contactId, $group ? $context->id : null);
                    $chat = $group && $group->reply_mode === 'private' ? $link->chat_id : $row->chat_id;
                    $text = $incoming->body;
                    if ($context->conversation_id && in_array($text, ['/help', '/ajuda', '/start', '/historico', '/history', '/agents', '/status', '/resultado', '/unlink', '/desvincular'], true)) {
                        app(Engine::class)->touch($identity, Conversation::findOrFail($context->conversation_id));
                    }
                    if ($text === '/unlink confirm' || $text === '/desvincular confirmar') {
                        $accounts->revoke($link, $identity->user);
                        $this->notice($bot, $chat, 'Vínculo revogado. Para acessar novamente, faça um novo vínculo pelo painel.', group: $group);
                    } elseif (in_array($text, ['/unlink', '/desvincular'], true)) {
                        $this->notice($bot, $chat, 'Para revogar seu vínculo, envie /desvincular confirmar. Confirmações pendentes serão invalidadas.', $link, $group, $group ? $context : null);
                    } elseif (in_array($text, ['/help', '/ajuda', '/start'], true)) {
                        $this->notice($bot, $chat, 'Sou o assistente virtual do Agent Center. Converse normalmente ou use /historico, /knowledge pergunta, /status, /cancelar, /agents, /nova [UUID do Agent] e /desvincular. Diagnósticos exigem autorização e confirmação. Nunca envie senhas ou tokens.', $link, $group, $group ? $context : null);
                    } elseif (in_array($text, ['/historico', '/history'], true)) {
                        $c = $context->conversation_id ? Conversation::findOrFail($context->conversation_id) : null;
                        if ($c) {
                            Gate::forUser($identity->user)->authorize('view', $c);
                            abort_unless($c->channel === $identity->channel && $c->contact_id === $identity->contactId && $c->telegram_context_id === $identity->scopeId, 403);
                        }
                        $this->notice($bot, $chat, $c ? 'Histórico: '.route('conversations.show', $c->id) : 'Seu histórico aparecerá depois da primeira mensagem nesta conversa.', $link, $group, $group ? $context : null);
                    } elseif ($text === '/agents') {
                        $agents = Agent::where('status', 'active')->whereNull('revoked_at')->get()->filter(fn ($a) => ($a->metadata['task_protocol'] ?? 0) === 1);
                        $this->notice($bot, $chat, $agents->isEmpty() ? 'Nenhum Agent autorizado disponível.' : 'Agents autorizados (use /nova UUID para selecionar):'."\n".$agents->take(20)->map(fn ($a) => $a->name.' — '.$a->id)->implode("\n"), $link, $group, $group ? $context : null);
                    } elseif (str_starts_with($text, '/') && ! preg_match('~^/(?:nova(?: [a-f0-9-]{36})?|status|resultado|cancel|cancelar|knowledge .+)$~Di', $text)) {
                        $this->notice($bot, $chat, 'Comando não reconhecido. Use /help para consultar os comandos disponíveis.', $link, $group, $group ? $context : null);
                    } else {
                        $engine = app(Engine::class);
                        $c = $context->conversation_id ? Conversation::findOrFail($context->conversation_id) : null;
                        $selectedAgent = null;
                        if (preg_match('~^/nova(?: ([a-f0-9-]{36}))?$~Di', $text, $selection)) {
                            $selectedAgent = $selection[1] ?? null;
                            if ($selectedAgent) {
                                abort_unless(Str::isUuid($selectedAgent) && Agent::whereKey($selectedAgent)->where('status', 'active')->whereNull('revoked_at')->exists(), 422);
                            }
                            if ($c && ! in_array($c->state, ['CLOSED', 'CANCELLED'], true)) {
                                $c = $engine->send($identity, $c, (string) Str::uuid(), 'Cancela');
                                $this->collect($bot, $link, $c, $group, $context);
                            }
                            $context->conversation_id = null;
                            $context->cursor = 0;
                            $c = null;
                            $text = 'Olá!';
                        }
                        if (! $c) {
                            $c = $engine->create($identity, $row->id, $selectedAgent);
                            $context->conversation_id = $c->id;
                            $context->save();
                        }
                        if (in_array($text, ['/status', '/resultado'], true)) {
                            $c = $engine->sync($identity, $c);
                            $this->notice($bot, $chat, 'Estado da conversa: '.$c->state.'. Novos resultados serão enviados quando disponíveis.', $link, $group, $group ? $context : null);
                        } else {
                            $text = match (true) {
                                in_array($text, ['/cancel', '/cancelar'], true) => 'Cancela',
                                str_starts_with($text, '/knowledge ') => 'Consulte a Knowledge: '.substr($text, 11),
                                default => $text,
                            };
                            $intent = app(IntentDetector::class)->detect($text, State::from($c->state));
                            // Transport freshness is additional to the engine's target/policy/idempotency checks.
                            if ($intent === Intent::Confirm && $c->state === 'WAITING_FOR_CONFIRMATION' && ((int) ($c->context_json['pending']['issued_at'] ?? $c->last_activity_at->timestamp) < now()->timestamp - config('telegram.confirmation_seconds') || $row->message_date->timestamp < (int) ($c->context_json['pending']['issued_at'] ?? $c->last_activity_at->timestamp) - 2)) {
                                $c = $engine->send($identity, $c, (string) Str::uuid(), 'Cancela');
                                $this->notice($bot, $chat, 'Confirmação antiga invalidada. Solicite novamente o diagnóstico para confirmar um contexto atual.', $link, $group, $group ? $context : null);
                            } else {
                                $c = $engine->send($identity, $c, $row->id, $text);
                            }
                        }
                        $this->collect($bot, $link, $c, $group, $context);
                    }
                }
                $row->forceFill(['status' => ($rejected ?? false) ? 'DENIED' : 'PROCESSED', 'payload_cipher' => null, 'last_error' => ($rejected ?? false) ? 'AUTHORIZATION_OR_INPUT_DENIED' : null])->save();
            });
        } finally {
            app(TenantContext::class)->apply();
        }
    }

    public function collect(Bot $bot, Link $link, Conversation $c, ?Group $group = null, Link|GroupContext|null $context = null): void
    {
        $context ??= $link;
        if ($context->conversation_id !== $c->id) {
            $context->conversation_id = $c->id;
            $context->cursor = 0;
        }
        foreach (ConversationMessage::where('conversation_id', $c->id)->where('sequence', '>', $context->cursor)->where('sender_type', 'system')->orderBy('sequence')->get() as $m) {
            if (! Outbound::where('link_id', $link->id)->where('message_id', $m->id)->exists()) {
                $out = new Outbound;
                $out->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $bot->tenant_id, 'bot_id' => $bot->id, 'generation' => $bot->generation, 'link_id' => $link->id, 'conversation_id' => $c->id, 'message_id' => $m->id, 'chat_id' => $group && $group->reply_mode === 'group' ? $group->chat_id : $link->chat_id, 'group_id' => $group?->id, 'group_generation' => $group?->generation, 'context_id' => $group ? $context->id : null, 'thread_id' => $group ? $context->thread_id : 0, 'next_attempt_at' => now()])->save();
                Deliver::dispatch($bot->tenant_id, $out->id)->afterCommit();
            }
        }
        $context->cursor = $c->sequence;
        $context->save();
    }

    private function identified(Link $link, Bot $bot): bool
    {
        try {
            app(Accounts::class)->identity($link);

            return true;
        } catch (AuthorizationException|HttpExceptionInterface|ModelNotFoundException) {
            return false;
        } finally {
            app(TenantContext::class)->apply($bot->tenant_id);
        }
    }

    private function notice(Bot $bot, int $chat, string $body, ?Link $link = null, ?Group $group = null, ?GroupContext $context = null): void
    {
        $out = new Outbound;
        $out->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $bot->tenant_id, 'bot_id' => $bot->id, 'generation' => $bot->generation, 'link_id' => $link?->id, 'group_id' => $group?->id, 'group_generation' => $group?->generation, 'context_id' => $context?->id, 'thread_id' => $context?->thread_id ?? 0, 'chat_id' => $chat, 'body_cipher' => Crypt::encryptString($body), 'next_attempt_at' => now()])->save();
        Deliver::dispatch($bot->tenant_id, $out->id)->afterCommit();
    }
}
