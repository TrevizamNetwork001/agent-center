<?php

namespace App\Conversation;

use App\Agent\Protocol;
use App\Diagnostics\Catalog;
use App\Diagnostics\Engine as Tasks;
use App\Diagnostics\Planner;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSetting;
use App\Models\Diagnostic;
use App\Models\DiagnosticConclusion;
use App\Models\DiagnosticInterpretation;
use App\Models\Profile;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class Engine
{
    public function enabled(): bool
    {
        return ConversationSetting::where('enabled', true)->exists();
    }

    private function allowed(Identity $i): void
    {
        abort_unless($i->channel === 'web' && $i->tenantId === app(TenantContext::class)->tenantId, 403);
        abort_unless(ConversationSetting::where('enabled', true)->sharedLock()->first() !== null, 403, 'Conversa desabilitada neste tenant.');
    }

    private function userLock(Identity $i): void
    {
        DB::select('SELECT pg_advisory_xact_lock(7816, ?::integer)', [$i->user->id]);
    }

    public function create(Identity $i, string $key, ?string $agent = null): Conversation
    {
        return DB::transaction(function () use ($i, $key, $agent) {
            $this->allowed($i);
            Gate::forUser($i->user)->authorize('create', Diagnostic::class);
            $this->userLock($i);
            $old = Conversation::where('user_id', $i->user->id)->where('creation_key', $key)->first();
            if ($old) {
                return $old;
            }
            $ctx = [];
            if ($agent) {
                $a = Agent::findOrFail($agent);
                $ctx['agent_id'] = $a->id;
            }
            $c = new Conversation;
            $c->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $i->tenantId, 'user_id' => $i->user->id, 'channel' => 'web', 'status' => 'OPEN', 'state' => 'NEW', 'creation_key' => $key, 'context_json' => $ctx, 'started_at' => now(), 'last_activity_at' => now()])->save();
            $this->transition($c, State::Active, 'created');
            $this->reply($c, 'Qual conectividade você quer verificar? Informe o site ou peça um diagnóstico.');
            $c->save();

            return $c;
        });
    }

    public function send(Identity $i, Conversation $conversation, string $key, string $body): Conversation
    {
        return DB::transaction(function () use ($i, $conversation, $key, $body) {
            $this->allowed($i);
            Gate::forUser($i->user)->authorize('send', $conversation);
            $this->userLock($i);
            $digest = hash_hmac('sha256', $body, (string) config('app.key'));
            $duplicate = ConversationMessage::where('request_user_id', $i->user->id)->where('request_key', $key)->first();
            if ($duplicate) {
                abort_unless(hash_equals($duplicate->metadata['digest'] ?? '', $digest), 409, 'Chave de mensagem reutilizada com conteúdo diferente.');

                return Conversation::findOrFail($duplicate->conversation_id);
            }
            $c = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->synchronize($c);
            $det = app(IntentDetector::class);
            $intent = $det->detect($body, State::from($c->state));
            if (in_array($c->state, ['CLOSED', 'CANCELLED'], true) && in_array($intent, [Intent::Start, Intent::Another, Intent::Target, Intent::Path], true)) {
                $c = $this->create($i, (string) Str::uuid(), $c->context_json['agent_id'] ?? null);
            }
            abort_if($c->sequence >= config('conversation.max_messages'), 422, 'Limite desta conversa alcançado. Abra uma nova conversa.');
            $safe = Text::clean($body);
            $this->message($c, 'user', 'text', $safe, $intent->value, ['digest' => $digest], $key, $i->user->id);
            $c->last_intent = $intent->value;
            $c->last_activity_at = now();
            $recent = ConversationMessage::where('conversation_id', $c->id)->where('sender_type', 'user')->orderByDesc('sequence')->limit(4)->pluck('body')->reverse()->all();
            $tone = app(ConversationToneDetector::class)->detect($recent);
            $prefix = app(ConversationToneDetector::class)->prefix($safe, $tone);
            $state = State::from($c->state);
            if ($state === State::Closed) {
                $this->reply($c, 'Conversa encerrada. Uma nova solicitação de teste abre outra conversa.');
            } elseif ($intent === Intent::Cancel) {
                $this->cancel($c, $i);
            } elseif ($intent === Intent::Close || $intent === Intent::Acknowledge) {
                if ($state === State::Running) {
                    if ($intent === Intent::Close) {
                        $c->context_json = [...$c->context_json, 'confirm_cancel' => true];
                        $this->reply($c, 'Há um diagnóstico em execução. Confirma o cancelamento?');
                    } else {
                        $this->reply($c, $prefix.'O diagnóstico continua em execução.');
                    }
                } elseif (in_array($state, [State::Target, State::Confirmation, State::Active], true) && $intent === Intent::Acknowledge) {
                    $this->reply($c, $prefix.'Continuo aguardando a informação ou confirmação pendente.');
                } else {
                    $this->transition($c, State::Closed, 'closed');
                    $this->reply($c, $prefix.'Por nada. Conversa encerrada.');
                }
            } elseif ($intent === Intent::Path) {
                $target = $det->target($safe) ?? $c->current_target;
                $agent = Agent::where('status', 'active')->whereNull('revoked_at')->find($c->context_json['agent_id'] ?? null);
                if (! $target || ! $agent || ! Protocol::mtrAvailable($agent)) {
                    $this->reply($c, 'MTR indisponível neste Agent ou alvo ainda não informado. Nenhuma coleta de caminho foi iniciada.');
                } elseif ($state === State::Running) {
                    $this->reply($c, 'O diagnóstico atual continua. Ao concluir, solicite o MTR; as evidências serão preservadas.');
                } else {
                    $profile = $c->context_json['last_profile'] ?? 'SITE_LENTO';
                    if ($profile === 'PROBLEMA_DNS') {
                        $this->reply($c, 'O Profile DNS não inclui MTR. Selecione Caminho MTR para outro diagnóstico; as evidências DNS permanecem preservadas.');
                        $c->save();

                        return $c;
                    }
                    $c->context_json = [...$c->context_json, 'pending' => ['profile' => $profile, 'target' => $target, 'mtr_requested' => true]];
                    $this->transition($c, State::Confirmation, 'execution_confirmation');
                    $this->reply($c, 'Confirma coleta MTR ICMP e TCP/443 no alvo '.$target.'? Profile e evidências anteriores serão preservados. A política local deve autorizar o destino.');
                }
            } elseif (preg_match('/\b(ssh|huawei|bgp|shell|nmap|iperf)\b|device_/i', $safe)) {
                $this->reply($c, 'Essa operação não está disponível. Nenhuma Skill ou tarefa foi executada.');
            } elseif ($intent === Intent::Confirm && ($c->context_json['confirm_cancel'] ?? false)) {
                $this->cancel($c, $i);
            } elseif ($intent === Intent::Deny) {
                $c->context_json = array_diff_key($c->context_json, array_flip(['pending', 'confirm_cancel']));
                if ($state === State::Confirmation) {
                    $this->transition($c, State::FollowUp, 'denied');
                }$this->reply($c, 'Certo. Nenhum novo teste foi iniciado.');
            } elseif ($state === State::Running) {
                $this->reply($c, $prefix.'O diagnóstico atual continua. Você pode acompanhar o progresso ou solicitar cancelamento.');
            } elseif ($intent === Intent::Detail) {
                $this->details($c, $tone);
            } elseif ($intent === Intent::Continue) {
                $this->transition($c, State::FollowUp, 'continue');
                if ($c->current_diagnostic_id && ($c->context_json['last_profile'] ?? '') !== 'PERDA_LATENCIA' && $this->canDeepen($c)) {
                    $c->context_json = [...$c->context_json, 'pending' => ['profile' => 'PERDA_LATENCIA', 'target' => $c->current_target]];
                    $this->transition($c, State::Confirmation, 'deeper_scope');
                    $this->reply($c, $prefix.'As evidências anteriores estão preservadas. Posso investigar perda e latência no mesmo alvo, sem repetir o diagnóstico anterior. Confirma?');
                } else {
                    $this->reply($c, 'As evidências anteriores estão preservadas. Não há aprofundamento adicional comprovado disponível neste escopo. Consulte as etapas ou solicite explicitamente outro teste.');
                }
            } elseif ($intent === Intent::Confirm && $state === State::Confirmation && isset($c->context_json['pending'])) {
                $p = $c->context_json['pending'];
                $this->start($c, $i, $p['profile'], $p['target'], $prefix, ($p['mtr_requested'] ?? false) === true);
            } elseif ($intent === Intent::Start || $intent === Intent::Another || $intent === Intent::Target) {
                $target = $det->target($safe);
                $profile = $intent === Intent::Target && $state === State::Target ? ($c->context_json['requested_profile'] ?? 'SITE_LENTO') : $det->profile($safe);
                if ($state === State::Completed || $state === State::FollowUp || $intent === Intent::Another) {
                    $target ??= $c->current_target;
                }
                if (! $target) {
                    $c->context_json = [...$c->context_json, 'requested_profile' => $profile];
                    $this->transition($c, State::Target, 'target_requested');
                    $this->reply($c, $prefix.'Qual site você quer testar?');
                } elseif (in_array($state, [State::Completed, State::FollowUp, State::Confirmation], true) || $intent === Intent::Another || ($intent === Intent::Target && $state !== State::Target)) {
                    $c->context_json = [...$c->context_json, 'pending' => ['profile' => $profile, 'target' => $target]];
                    $this->transition($c, State::Confirmation, 'execution_confirmation');
                    $this->reply($c, $prefix.'Confirma um novo diagnóstico para '.$target.'? As evidências anteriores serão preservadas.');
                } else {
                    $this->start($c, $i, $profile, $target, $prefix);
                }
            } elseif ($intent === Intent::Help) {
                $this->reply($c, 'Peça um teste de site, DNS ou perda/latência. Você pode pedir detalhes, cancelar ou encerrar. Somente os Profiles e Skills habilitados podem executar.');
            } else {
                $this->reply($c, $prefix.(str_contains(Str::ascii(Str::lower($safe)), 'trem') ? 'Me conta o que está agarrando e qual site quer verificar.' : 'Informe o site e o que deseja verificar. Para comandos não disponíveis, nenhum teste será executado.'));
            }
            $c->save();

            return $c;
        });
    }

    private function canDeepen(Conversation $c): bool
    {
        $p = Profile::where('key', 'PERDA_LATENCIA')->where('enabled', true)->first();
        $a = Agent::where('status', 'active')->whereNull('revoked_at')->find($c->context_json['agent_id'] ?? null);
        if (! $p || ! $a || ($a->metadata['task_protocol'] ?? 0) !== 1) {
            return false;
        }
        $plan = app(Planner::class)->plan($p, $c->current_target, Protocol::executionCapabilities($a));

        return ! collect($plan['steps'])->contains(fn ($s) => ! $s['optional'] && ! $s['available']);
    }

    private function start(Conversation $c, Identity $i, string $profile, string $target, string $prefix, bool $mtr = false): void
    {
        Gate::forUser($i->user)->authorize('create', Diagnostic::class);
        app(Catalog::class)->ensure();
        app(Planner::class)->target($target);
        $p = Profile::where('key', $profile)->where('enabled', true)->first();
        $agents = Agent::where('status', 'active')->whereNull('revoked_at')->get()->filter(fn ($a) => ($a->metadata['task_protocol'] ?? 0) === 1);
        $a = isset($c->context_json['agent_id']) ? $agents->firstWhere('id', $c->context_json['agent_id']) : ($agents->count() === 1 ? $agents->first() : null);
        if (! $p || ! $a) {
            $this->reply($c, 'Não há Profile/Agent disponível ou a seleção de Agent é necessária. Nenhuma tarefa foi criada; abra uma conversa selecionando um Agent autorizado.');

            return;
        }
        $plan = app(Planner::class)->plan($p, $target, Protocol::executionCapabilities($a), ['mtr_requested' => $mtr]);
        if (collect($plan['steps'])->contains(fn ($s) => (! $s['optional'] || $mtr && $s['capability'] === 'mtr_path_analysis:v1') && ! $s['available'])) {
            $this->reply($c, 'As Skills necessárias não estão disponíveis neste Agent/Profile. Nenhuma execução foi criada.');

            return;
        }
        $d = app(Tasks::class)->create($p, $a, $target, $i->user, ['mtr_requested' => $mtr, 'related_diagnostic_id' => $c->current_diagnostic_id]);
        $history = $c->context_json['diagnostic_ids'] ?? [];
        $history[] = $d->id;
        $c->forceFill(['current_profile_id' => $p->id, 'current_diagnostic_id' => $d->id, 'current_target' => $target, 'context_json' => ['agent_id' => $a->id, 'last_profile' => $p->key, 'diagnostic_ids' => array_slice($history, -100)]]);
        $this->transition($c, State::Running, 'diagnostic_started');
        $this->reply($c, $prefix.'Vou verificar a conectividade de '.$target.' e apresentar os resultados.', ['diagnostic_id' => $d->id]);
    }

    private function cancel(Conversation $c, Identity $i): void
    {
        if (in_array($c->state, ['CLOSED', 'CANCELLED'], true)) {
            $this->reply($c, 'A conversa já foi encerrada ou cancelada.');

            return;
        }
        if ($c->current_diagnostic_id) {
            $d = Diagnostic::findOrFail($c->current_diagnostic_id);
            Gate::forUser($i->user)->authorize('cancel', $d);
            app(Tasks::class)->cancel($d, $i->user);
        }
        $this->transition($c, State::Cancelled, 'cancel_requested');
        $this->reply($c, 'Cancelamento solicitado. Evidências preservadas; tarefas ativas encerram pelo protocolo nativo de cancelamento.');
    }

    public function sync(Identity $i, Conversation $conversation): Conversation
    {
        return DB::transaction(function () use ($i, $conversation) {
            $this->allowed($i);
            Gate::forUser($i->user)->authorize('view', $conversation);
            $c = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->synchronize($c);
            $c->save();

            return $c;
        });
    }

    private function synchronize(Conversation $c): void
    {
        if (! $c->current_diagnostic_id) {
            return;
        }
        $d = Diagnostic::findOrFail($c->current_diagnostic_id);
        if (in_array($d->status, ['COMPLETED', 'FAILED', 'CANCELLED'], true)) {
            $official = DiagnosticConclusion::where('diagnostic_id', $d->id)->first();
            if ($official) {
                $key = 'result:'.$d->id;
                if (! ConversationMessage::where('conversation_id', $c->id)->where('event_key', $key)->exists()) {
                    if ($c->state === 'DIAGNOSTIC_RUNNING') {
                        $this->transition($c, $d->status === 'CANCELLED' ? State::Cancelled : State::Completed, 'diagnostic_finished');
                    }
                    $this->message($c, 'system', 'result', $official->classification."\n".$official->summary, null, ['diagnostic_id' => $d->id, 'classification' => $official->classification], eventKey: $key);
                }
            }
            $interp = DiagnosticInterpretation::where('diagnostic_id', $d->id)->where('status', 'ASSISTED')->latest('created_at')->first();
            if ($interp && ! ConversationMessage::where('conversation_id', $c->id)->where('event_key', 'interpretation:'.$interp->id)->exists()) {
                $this->message($c, 'system', 'interpretation', 'Interpretação assistida: '.$interp->summary."\n".$interp->explanation, null, ['interpretation_id' => $interp->id], eventKey: 'interpretation:'.$interp->id);
            }
        }
    }

    private function details(Conversation $c, array $tone): void
    {
        $official = $c->current_diagnostic_id ? DiagnosticConclusion::where('diagnostic_id', $c->current_diagnostic_id)->first() : null;
        if (! $official) {
            $this->reply($c, 'Ainda não há conclusão disponível. Informe um alvo para começar.');

            return;
        }
        $text = $official->classification."\n".$official->summary."\n".implode("\n", $official->next_steps ?? []);
        if ($tone['verbosity'] === 'DETAILED' || $tone['technical_level'] === 'TECHNICAL') {
            $text .= "\nAs evidências e os tempos de cada etapa estão no diagnóstico vinculado.";
        }
        $this->reply($c, $text, ['diagnostic_id' => $c->current_diagnostic_id]);
    }

    public function transition(Conversation $c, State $next, string $event): void
    {
        $previous = State::from($c->state);
        abort_unless($previous->permits($next), 409, 'Transição de conversa inválida.');
        if ($previous === $next) {
            return;
        }
        $c->state = $next->value;
        $c->status = match ($next) {
            State::Closed => 'CLOSED',State::Cancelled => 'CANCELLED',default => 'OPEN'
        };
        if (in_array($next, [State::Closed, State::Cancelled], true)) {
            $c->closed_at = now();
        }
        $e = new ConversationEvent;
        $e->forceFill(['tenant_id' => $c->tenant_id, 'conversation_id' => $c->id, 'event_type' => $event, 'previous_state' => $previous->value, 'next_state' => $next->value, 'metadata' => [], 'created_at' => now()])->save();
        app(Audit::class)->record('conversation.transitioned', tenantId: $c->tenant_id, entity: 'conversation', metadata: ['conversation_id' => $c->id]);
    }

    private function reply(Conversation $c, string $body, array $meta = []): void
    {
        $this->message($c, 'system', 'text', $body, null, $meta);
    }

    private function message(Conversation $c, string $sender, string $type, string $body, ?string $intent = null, array $meta = [], ?string $requestKey = null, ?int $user = null, ?string $eventKey = null): void
    {
        $c->sequence++;
        $m = new ConversationMessage;
        $m->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $c->tenant_id, 'conversation_id' => $c->id, 'sender_type' => $sender, 'message_type' => $type, 'body' => Text::clean(mb_strcut($body, 0, 23000, 'UTF-8')), 'detected_intent' => $intent, 'metadata' => $meta, 'request_key' => $requestKey, 'request_user_id' => $user, 'event_key' => $eventKey, 'sequence' => $c->sequence, 'created_at' => now()])->save();
    }
}
