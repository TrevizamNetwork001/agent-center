<?php

namespace App\Telegram;

use App\Conversation\Engine;
use App\Conversation\Intent;
use App\Conversation\IntentDetector;
use App\Conversation\State;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
                DB::select('SELECT pg_advisory_xact_lock(7823, ?::integer)', [(int) sprintf('%u', crc32($bot->id.':'.$row->sender_id)) % 2147483647]);
                $text = Crypt::decryptString($row->payload_cipher);
                $accounts = app(Accounts::class);
                $link = Link::where('bot_id', $bot->id)->where('telegram_user_id', $row->sender_id)->where('status', 'ACTIVE')->lockForUpdate()->first();
                if (preg_match('/^\/start ([A-Za-z0-9]{48})$/D', $text, $matches)) {
                    $pending = Link::where('bot_id', $bot->id)->where('status', 'CODE')->where('code_hash', $accounts->hash($matches[1]))->where('expires_at', '>', now())->lockForUpdate()->first();
                    $occupied = Link::where('bot_id', $bot->id)->where('telegram_user_id', $row->sender_id)->whereIn('status', ['ACTIVE', 'AWAITING_APPROVAL'])->exists();
                    if ($pending && ! $occupied) {
                        $pending->forceFill(['status' => 'AWAITING_APPROVAL', 'telegram_user_id' => $row->sender_id, 'chat_id' => $row->chat_id, 'code_hash' => null])->save();
                        $accounts->audit('telegram.link_claimed');
                        $this->notice($bot, $row->chat_id, 'Código consumido. Confirme o ID Telegram no painel autenticado do Agent Center para concluir o vínculo.');
                    } else {
                        $this->notice($bot, $row->chat_id, 'Código inválido, expirado ou conta já vinculada. Gere um novo vínculo no painel.');
                    }
                } elseif (! $link) {
                    $this->notice($bot, $row->chat_id, 'Vincule sua conta no painel autenticado do Agent Center. Usuários não vinculados não têm acesso a Knowledge ou diagnósticos.');
                } else {
                    $request = Request::create('/telegram-internal');
                    $request->attributes->add(['telegram_verified_link' => $link, 'telegram_request_key' => $row->id, 'telegram_text' => $text]);
                    $incoming = app(TelegramAdapter::class)->message($request);
                    $identity = $incoming->identity;
                    $text = $incoming->body;
                    if ($text === '/unlink confirm' || $text === '/desvincular confirmar') {
                        $accounts->revoke($link, $identity->user);
                        $this->notice($bot, $row->chat_id, 'Vínculo revogado. Para acessar novamente, faça um novo vínculo pelo painel.');
                    } elseif (in_array($text, ['/unlink', '/desvincular'], true)) {
                        $this->notice($bot, $row->chat_id, 'Para revogar seu vínculo, envie /desvincular confirmar. Confirmações pendentes serão invalidadas.');
                    } elseif (in_array($text, ['/help', '/ajuda', '/start'], true)) {
                        $this->notice($bot, $row->chat_id, 'Sou o assistente virtual do Agent Center. Converse normalmente ou use /knowledge pergunta, /status, /cancelar, /agents, /nova [UUID do Agent] e /desvincular. Diagnósticos exigem autorização e confirmação. Nunca envie senhas ou tokens.');
                    } elseif ($text === '/agents') {
                        $agents = Agent::where('status', 'active')->whereNull('revoked_at')->get()->filter(fn ($a) => ($a->metadata['task_protocol'] ?? 0) === 1);
                        $this->notice($bot, $row->chat_id, $agents->isEmpty() ? 'Nenhum Agent autorizado disponível.' : 'Agents autorizados (use /nova UUID para selecionar):'."\n".$agents->take(20)->map(fn ($a) => $a->name.' — '.$a->id)->implode("\n"), $link);
                    } elseif (str_starts_with($text, '/') && ! preg_match('~^/(?:nova(?: [a-f0-9-]{36})?|status|resultado|cancel|cancelar|knowledge .+)$~Di', $text)) {
                        $this->notice($bot, $row->chat_id, 'Comando não reconhecido. Use /help para consultar os comandos disponíveis.', $link);
                    } else {
                        $engine = app(Engine::class);
                        $c = $link->conversation_id ? Conversation::findOrFail($link->conversation_id) : null;
                        $selectedAgent = null;
                        if (preg_match('~^/nova(?: ([a-f0-9-]{36}))?$~Di', $text, $selection)) {
                            $selectedAgent = $selection[1] ?? null;
                            if ($selectedAgent) {
                                abort_unless(Str::isUuid($selectedAgent) && Agent::whereKey($selectedAgent)->where('status', 'active')->whereNull('revoked_at')->exists(), 422);
                            }
                            if ($c && ! in_array($c->state, ['CLOSED', 'CANCELLED'], true)) {
                                $c = $engine->send($identity, $c, (string) Str::uuid(), 'Cancela');
                                $this->collect($bot, $link, $c);
                            }
                            $link->conversation_id = null;
                            $link->cursor = 0;
                            $c = null;
                            $text = 'Olá!';
                        }
                        if (! $c) {
                            $c = $engine->create($identity, $row->id, $selectedAgent);
                            $link->conversation_id = $c->id;
                            $link->save();
                        }
                        if (in_array($text, ['/status', '/resultado'], true)) {
                            $c = $engine->sync($identity, $c);
                            $this->notice($bot, $row->chat_id, 'Estado da conversa: '.$c->state.'. Novos resultados serão enviados quando disponíveis.', $link);
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
                                $this->notice($bot, $row->chat_id, 'Confirmação antiga invalidada. Solicite novamente o diagnóstico para confirmar um contexto atual.', $link);
                            } else {
                                $c = $engine->send($identity, $c, $row->id, $text);
                            }
                        }
                        $this->collect($bot, $link, $c);
                    }
                }
                $row->forceFill(['status' => 'PROCESSED', 'payload_cipher' => null, 'last_error' => null])->save();
            });
        } finally {
            app(TenantContext::class)->apply();
        }
    }

    public function collect(Bot $bot, Link $link, Conversation $c): void
    {
        if ($link->conversation_id !== $c->id) {
            $link->conversation_id = $c->id;
            $link->cursor = 0;
        }
        foreach (ConversationMessage::where('conversation_id', $c->id)->where('sequence', '>', $link->cursor)->where('sender_type', 'system')->orderBy('sequence')->get() as $m) {
            if (! Outbound::where('link_id', $link->id)->where('message_id', $m->id)->exists()) {
                $out = new Outbound;
                $out->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $bot->tenant_id, 'bot_id' => $bot->id, 'generation' => $bot->generation, 'link_id' => $link->id, 'conversation_id' => $c->id, 'message_id' => $m->id, 'chat_id' => $link->chat_id, 'next_attempt_at' => now()])->save();
                Deliver::dispatch($bot->tenant_id, $out->id)->afterCommit();
            }
        }
        $link->cursor = $c->sequence;
        $link->save();
    }

    private function notice(Bot $bot, int $chat, string $body, ?Link $link = null): void
    {
        $out = new Outbound;
        $out->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $bot->tenant_id, 'bot_id' => $bot->id, 'generation' => $bot->generation, 'link_id' => $link?->id, 'chat_id' => $chat, 'body_cipher' => Crypt::encryptString($body), 'next_attempt_at' => now()])->save();
        Deliver::dispatch($bot->tenant_id, $out->id)->afterCommit();
    }
}
