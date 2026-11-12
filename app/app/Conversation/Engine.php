<?php

namespace App\Conversation;

use App\Agent\Protocol;
use App\Diagnostics\Catalog;
use App\Diagnostics\Digest;
use App\Diagnostics\Engine as Tasks;
use App\Diagnostics\IntentParser;
use App\Diagnostics\IntentPlanning;
use App\Diagnostics\OperationalIntent;
use App\Diagnostics\PathReadiness;
use App\Diagnostics\Planner;
use App\Diagnostics\Preflight;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSetting;
use App\Models\Diagnostic;
use App\Models\DiagnosticConclusion;
use App\Models\DiagnosticInterpretation;
use App\Models\Profile;
use App\Rag\Answer;
use App\Services\Audit;
use App\Telegram\Access;
use App\Telegram\Bot;
use App\Telegram\Group;
use App\Telegram\GroupContext;
use App\Telegram\Link;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Engine
{
    public function enabled(): bool
    {
        return ConversationSetting::where('enabled', true)->exists();
    }

    private function allowed(Identity $i): void
    {
        abort_unless(in_array($i->channel, ['web', 'telegram'], true) && $i->tenantId === app(TenantContext::class)->tenantId, 403);
        if ($i->channel === 'telegram') {
            abort_unless(Link::whereKey($i->contactId)->where('user_id', $i->user->id)->where('status', 'ACTIVE')->exists() && Bot::where('enabled', true)->exists() && config('telegram.enabled'), 403);
            $link = Link::findOrFail($i->contactId);
            $context = $i->scopeId ? GroupContext::whereKey($i->scopeId)->where('link_id', $link->id)->firstOrFail() : null;
            $group = $context ? Group::whereKey($context->group_id)->sharedLock()->firstOrFail() : null;
            abort_unless(app(Access::class)->allows($link, $group), 403);
            abort_if($context && $context->generation !== $group->generation, 403);
        }
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
            $c->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $i->tenantId, 'user_id' => $i->user->id, 'channel' => $i->channel, 'contact_id' => $i->contactId, 'telegram_context_id' => $i->scopeId, 'status' => 'OPEN', 'state' => 'NEW', 'creation_key' => $key, 'context_json' => $ctx, 'started_at' => now(), 'last_activity_at' => now()])->save();
            $this->transition($c, State::Active, 'created');
            // Telegram is opened by an incoming message: its normal reply is
            // the greeting. Do not enqueue an additional institutional welcome.
            if ($i->channel === 'web') {
                $this->reply($c, 'Olá! Sou o assistente virtual do Agent Center. Como posso ajudar hoje?');
            }
            $c->save();

            return $c;
        });
    }

    public function send(Identity $i, Conversation $conversation, string $key, string $body): Conversation
    {
        return DB::transaction(function () use ($i, $conversation, $key, $body) {
            $this->allowed($i);
            Gate::forUser($i->user)->authorize('send', $conversation);
            abort_unless($conversation->channel === $i->channel && ($i->channel !== 'telegram' || ($conversation->contact_id === $i->contactId && $conversation->telegram_context_id === $i->scopeId)), 403);
            $this->userLock($i);
            $digest = hash_hmac('sha256', $body, (string) config('app.key'));
            $duplicate = ConversationMessage::where('request_user_id', $i->user->id)->where('request_key', $key)->first();
            if ($duplicate) {
                abort_unless(hash_equals($duplicate->metadata['digest'] ?? '', $digest), 409, 'Chave de mensagem reutilizada com conteúdo diferente.');

                return Conversation::findOrFail($duplicate->conversation_id);
            }
            $c = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            // A new user turn supersedes an in-flight question; never notify an old target.
            $c->context_json = array_diff_key($c->context_json, ['preflight_watch' => true]);
            $this->synchronize($c, $i);
            $det = app(IntentDetector::class);
            $intent = $det->detect($body, State::from($c->state));
            if (isset($c->context_json['objective_question']) && preg_match('/^(?:rota|caminho|conectividade|ping|dns|site|porta|\d{1,5})[.!? ]*$/i', trim(Str::ascii($body)))) {
                $intent = Intent::Start;
            }
            // Questions about an existing result are not requests for a new test.
            if ($c->current_diagnostic_id && in_array($c->state, ['DIAGNOSTIC_COMPLETED', 'FOLLOW_UP'], true)
                && preg_match('/^(?:quais (?:testes|verificacoes)|o que (?:voce )?(?:testou|verificou)|(?:por que|porque).*(?:normal|falha|inconclusivo|erro|resultado)|o que significa (?:esse |o )?(?:resultado|normal|falha|inconclusivo|erro))\b/', Str::lower(Str::ascii(trim($body))))
                && ! preg_match('/\b(outro|novo|execute|rode|confirmo|cancelar)\b/', Str::lower(Str::ascii($body)))) {
                $intent = Intent::Detail;
            }
            $idleRestart = $c->state === 'CLOSED' && ($c->context_json['closed_due_to_inactivity'] ?? false)
                && ! in_array($intent, [Intent::Confirm, Intent::Deny, Intent::Cancel, Intent::Close, Intent::Acknowledge], true);
            if ($idleRestart || (in_array($c->state, ['CLOSED', 'CANCELLED'], true) && in_array($intent, [Intent::Start, Intent::Another, Intent::Target, Intent::Path, Intent::Greeting, Intent::Social], true))) {
                $c = $this->create($i, (string) Str::uuid(), $c->context_json['agent_id'] ?? null);
            }
            abort_if($c->sequence >= config('conversation.max_messages'), 422, 'Limite desta conversa alcançado. Abra uma nova conversa.');
            $safe = Text::clean($body);
            $this->message($c, 'user', 'text', $safe, $intent->value, ['digest' => $digest], $key, $i->user->id);
            $c->last_intent = $intent->value;
            $c->last_activity_at = now();
            $recent = ConversationMessage::where('conversation_id', $c->id)->where('sender_type', 'user')->orderByDesc('sequence')->limit(4)->pluck('body')->reverse()->all();
            $toneDetector = app(ConversationToneDetector::class);
            if (($preference = $toneDetector->preference($safe)) !== null) {
                $c->context_json = [...$c->context_json, 'tone_preference' => $preference];
            }
            $tone = $toneDetector->detect($recent, $c->context_json['tone_preference'] ?? null);
            $c->context_json = [...$c->context_json, 'tone' => $tone];
            $prefix = app(ConversationToneDetector::class)->prefix($safe, $tone);
            $state = State::from($c->state);
            if (in_array($intent, [Intent::Topic, Intent::Knowledge, Intent::Start, Intent::Another, Intent::Target, Intent::Path, Intent::Confirm, Intent::Deny, Intent::Cancel, Intent::Close], true)) {
                $c->context_json = [...array_diff_key($c->context_json, ['pending_question' => true]), 'context_memory_version' => 1];
            } elseif (in_array($intent, [Intent::Greeting, Intent::Social, Intent::Unknown], true)) {
                app(ContextualTurn::class)->answer($c, $safe);
            }
            if ($state === State::Closed) {
                $this->reply($c, 'Conversa encerrada. Uma nova solicitação de teste abre outra conversa.');
            } elseif ($intent === Intent::Knowledge) {
                $answer = app(Answer::class)->run($i->user, $safe);
                $references = array_map(fn ($s) => array_intersect_key($s, array_flip(['id', 'document_id', 'version_id', 'version_number', 'position', 'offset_start', 'offset_end'])), $answer['sources']);
                $this->message($c, 'system', 'text', $answer['answer'], null, ['rag_status' => $answer['status'], 'rag_sources' => array_column($answer['sources'], 'id'), 'rag_references' => $references, 'rag_provenance' => $answer['provenance']]);
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
                    $this->reply($c, 'Por nada! O contexto está salvo; podemos continuar quando você quiser.');
                } else {
                    $this->transition($c, State::Closed, 'closed');
                    $this->reply($c, $intent === Intent::Acknowledge ? 'Por nada! Conversa encerrada, com o histórico preservado.' : 'Até mais! O histórico da conversa fica preservado.');
                }
            } elseif (preg_match('/\b(ssh|huawei|bgp|shell|nmap|iperf)\b|device_/i', $safe)) {
                $this->reply($c, 'Essa operação não está disponível. Nenhuma Skill ou tarefa foi executada.');
            } elseif (in_array($intent, [Intent::Greeting, Intent::Social, Intent::Topic, Intent::Unknown], true)) {
                if ($c->current_diagnostic_id && $state !== State::Running && preg_match('/\b(quais (?:testes|verificacoes)|o que (?:voce )?(?:testou|verificou)|mais simples)\b/', Str::lower(Str::ascii($safe)))) {
                    $this->details($c, $tone, $i);
                    $c->save();

                    return $c;
                }
                if ($intent === Intent::Topic) {
                    $c->context_json = array_diff_key($c->context_json, array_flip(['pending', 'confirm_cancel', 'provided_target']));
                    if ($state === State::Confirmation) {
                        $this->transition($c, State::FollowUp, 'topic_changed');
                    }
                }
                if ($intent !== Intent::Greeting) {
                    if (! isset($c->context_json['pending_question']) || (! app(ContextualTurn::class)->answer($c, $safe) && ! preg_match('/^(?:kk+|haha|rsrs|marcha|bora)[.! ]*$/i', $safe))) {
                        $c->context_json = [...$c->context_json, 'topic' => ['kind' => $intent->value, 'text' => mb_substr($safe, 0, 300)]];
                    }
                    if ($intent !== Intent::Topic && ($provided = $det->target($safe)) !== null) {
                        try {
                            app(Planner::class)->target($provided);
                            $c->context_json = [...$c->context_json, 'provided_target' => $provided];
                        } catch (ValidationException) {
                            // Unvalidated conversational data never becomes an execution target.
                        }
                    }
                }
                $response = app(Language::class)->reply($c, $i, $intent, $safe, $tone);
                if (isset($response['metadata']['context_answer'])) {
                    $answers = $c->context_json['conversation_answers'] ?? [];
                    $answers[] = $response['metadata']['context_answer'];
                    $c->context_json = [...array_diff_key($c->context_json, ['pending_question' => true]), 'conversation_answers' => array_slice($answers, -4)];
                }
                $this->message($c, 'system', 'text', $response['text'], null, $response['metadata']);
                if ($response['metadata']['language_status'] !== 'LOCAL') {
                    app(Audit::class)->record('conversation.language_rendered', $i->user, $c->tenant_id, 'conversation', metadata: ['conversation_id' => $c->id, ...$response['metadata']]);
                }
            } elseif ($intent === Intent::Path) {
                if ($state === State::Running) {
                    $this->reply($c, 'O diagnóstico atual continua. Ao concluir, solicite o MTR; as evidências serão preservadas.');
                } else {
                    $this->objectiveRequest($c, $i, $safe, $prefix, OperationalIntent::Route);
                }
            } elseif ($intent === Intent::Confirm && ($c->context_json['confirm_cancel'] ?? false)) {
                $this->cancel($c, $i);
            } elseif ($intent === Intent::Deny) {
                $c->context_json = array_diff_key($c->context_json, array_flip(['pending', 'confirm_cancel', 'objective_question', 'requested_objective', 'preflight_watch']));
                if ($state === State::Confirmation) {
                    $this->transition($c, State::FollowUp, 'denied');
                }$this->reply($c, 'Certo. Nenhum novo teste foi iniciado.');
            } elseif ($state === State::Running) {
                $this->reply($c, $prefix.'O diagnóstico atual continua. Você pode acompanhar o progresso ou solicitar cancelamento.');
            } elseif ($intent === Intent::Detail) {
                $this->details($c, $tone, $i);
            } elseif ($intent === Intent::Continue) {
                if (! preg_match('/\b(aprofundar|outro teste|outro diagnostico|investigue|investigar mais)\b/', Str::lower(Str::ascii($safe)))) {
                    $official = $c->current_diagnostic_id ? DiagnosticConclusion::where('diagnostic_id', $c->current_diagnostic_id)->first() : null;
                    $c->context_json = [...$c->context_json, 'topic' => ['kind' => 'DIAGNOSTIC_FOLLOW_UP', 'text' => mb_substr($safe, 0, 300)]];
                    $this->reply($c, ($official?->classification === 'NORMAL'
                        ? 'Entendi. Os testes do Agent não mostraram anormalidades no escopo verificado. '
                        : 'Entendi. As evidências e a conclusão anterior continuam preservadas. ').'A dificuldade ocorre em todos os clientes ou apenas em alguns? Nenhum novo teste foi iniciado.');
                    $c->save();

                    return $c;
                }
                $this->transition($c, State::FollowUp, 'continue');
                if ($c->current_diagnostic_id && ($c->context_json['last_profile'] ?? '') !== 'PERDA_LATENCIA' && $this->canDeepen($c)) {
                    $c->context_json = [...$c->context_json, 'pending' => ['issued_at' => now()->timestamp, 'profile' => 'PERDA_LATENCIA', 'target' => $c->current_target]];
                    $this->transition($c, State::Confirmation, 'deeper_scope');
                    $this->reply($c, $prefix.'As evidências anteriores estão preservadas. Posso investigar perda e latência no mesmo alvo, sem repetir o diagnóstico anterior. Confirma?');
                } else {
                    $this->reply($c, 'As evidências anteriores estão preservadas. Não há aprofundamento adicional comprovado disponível neste escopo. Consulte as etapas ou solicite explicitamente outro teste.');
                }
            } elseif ($intent === Intent::Confirm && $state === State::Confirmation && isset($c->context_json['pending'])) {
                $p = $c->context_json['pending'];
                // The row/user locks fence confirmation consumption and task creation
                // in the same transaction. A blocked attempt requires a new request.
                $c->context_json = array_diff_key($c->context_json, array_flip(['pending']));
                $this->transition($c, State::FollowUp, 'confirmation_consumed');
                try {
                    $planner = app(Planner::class);
                    if (! is_string($p['target'] ?? null) || ! $p['target'] || ! is_string($p['profile'] ?? null) || ! $p['profile']) {
                        $this->reply($c, 'Confirmação invalidada: destino ou perfil pendente ausente. Nenhuma tarefa foi criada; solicite um novo diagnóstico.');
                    } elseif (($mentioned = $det->confirmationTarget($safe)) !== null && $planner->target($mentioned) !== $planner->target($p['target'])) {
                        $this->reply($c, 'Confirmação invalidada: o destino informado difere do destino pendente. Nenhuma tarefa foi criada; solicite um novo diagnóstico para o destino desejado.');
                    } else {
                        $this->start($c, $i, $p['profile'], $p['target'], $prefix, ($p['mtr_requested'] ?? false) === true, $p['objective'] ?? []);
                    }
                } catch (ValidationException $e) {
                    $this->reply($c, 'Diagnóstico bloqueado: '.implode(' ', $e->validator->errors()->all()).' Nenhuma tarefa foi criada; solicite um novo diagnóstico.');
                }
            } elseif ($intent === Intent::Confirm) {
                if ($state === State::Confirmation) {
                    $this->transition($c, State::FollowUp, 'confirmation_missing');
                }
                $this->reply($c, 'Não há confirmação pendente válida. Nenhuma nova tarefa foi criada.');
            } elseif ($intent === Intent::Start || $intent === Intent::Another || $intent === Intent::Target) {
                if ($this->objectiveRequest($c, $i, $safe, $prefix)) {
                    $c->save();

                    return $c;
                }
                $target = $det->target($safe) ?? ($c->context_json['provided_target'] ?? null);
                $profile = $intent === Intent::Target && $state === State::Target ? ($c->context_json['requested_profile'] ?? 'SITE_LENTO') : $det->profile($safe);
                if ($state === State::Completed || $state === State::FollowUp || $intent === Intent::Another) {
                    $target ??= $c->current_target;
                }
                if (! $target) {
                    $c->context_json = [...$c->context_json, 'requested_profile' => $profile];
                    $this->transition($c, State::Target, 'target_requested');
                    $this->reply($c, $prefix.'Qual site você quer testar?');
                } elseif ($i->channel === 'telegram' || in_array($state, [State::Completed, State::FollowUp, State::Confirmation], true) || $intent === Intent::Another || ($intent === Intent::Target && $state !== State::Target)) {
                    $c->context_json = [...$c->context_json, 'pending' => ['issued_at' => now()->timestamp, 'profile' => $profile, 'target' => $target]];
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

    private function objectiveRequest(Conversation $c, Identity $i, string $body, string $prefix, ?OperationalIntent $force = null): bool
    {
        $request = app(IntentParser::class)->parse($body);
        $question = $c->context_json['objective_question'] ?? [];
        $short = trim(Str::lower(Str::ascii($body)), ' .!?');
        if ($question && isset(['rota' => 1, 'caminho' => 1, 'conectividade' => 1, 'ping' => 1, 'dns' => 1, 'site' => 1, 'porta' => 1][$short])) {
            $request['intent'] = match ($short) {
                'rota', 'caminho' => OperationalIntent::Route->value, 'conectividade' => OperationalIntent::Connectivity->value,
                'ping' => OperationalIntent::Latency->value, 'dns' => OperationalIntent::Dns->value,
                'porta' => OperationalIntent::Port->value, default => OperationalIntent::Web->value,
            };
            $request['ambiguous'] = false;
        } elseif (($question['kind'] ?? null) === 'port' && preg_match('/^\d{1,5}$/', $short)) {
            $request = [...$request, 'intent' => OperationalIntent::Port->value, 'port' => (int) $short, 'ambiguous' => false];
        }
        $target = app(IntentDetector::class)->target($body) ?? $question['target'] ?? $c->context_json['provided_target'] ?? null;
        if (! $request['intent'] && isset($c->context_json['requested_objective']) && $target) {
            $request = $c->context_json['requested_objective'];
        }
        $goal = $request['intent'] ? OperationalIntent::from($request['intent']) : $force;
        $bareIp = $target && filter_var($target, FILTER_VALIDATE_IP) && ! $goal;
        if (! $goal && ! $request['ambiguous'] && ! $bareIp) {
            return false;
        }
        // A changed objective invalidates any previous permission to execute.
        $c->context_json = array_diff_key($c->context_json, ['pending' => true, 'preflight_watch' => true]);
        if ($c->state === State::Confirmation->value) {
            $this->transition($c, State::FollowUp, 'objective_changed');
        }
        $target ??= $c->current_target;
        if ($request['ambiguous'] || ! $goal || $goal === OperationalIntent::Slowness && $target && filter_var($target, FILTER_VALIDATE_IP)) {
            $c->context_json = [...$c->context_json, 'objective_question' => ['kind' => 'intent', 'target' => $target]];
            $this->reply($c, 'Você quer analisar o caminho de rede, medir ping/perda no destino ou verificar um serviço? Um IP sozinho não define um site HTTPS. Nenhum teste foi iniciado.');

            return true;
        }
        $request['intent'] = $goal->value;
        if (! $target) {
            $c->context_json = [...$c->context_json, 'requested_objective' => $request];
            $this->transition($c, State::Target, 'target_requested');
            $this->reply($c, 'Qual IP ou hostname deseja verificar para '.$goal->label().'?');

            return true;
        }
        if ($goal === OperationalIntent::Port && $request['port'] === null) {
            $c->context_json = [...$c->context_json, 'objective_question' => ['kind' => 'port', 'target' => $target]];
            $this->reply($c, 'Qual porta TCP deseja verificar? O contrato atual suporta a porta 443; outras portas não serão substituídas silenciosamente.');

            return true;
        }
        $agents = Agent::where('status', 'active')->whereNull('revoked_at')->get()->filter(fn ($a) => ($a->metadata['task_protocol'] ?? 0) === 1);
        $agent = isset($c->context_json['agent_id']) ? $agents->firstWhere('id', $c->context_json['agent_id']) : ($agents->count() === 1 ? $agents->first() : null);
        if (! $agent) {
            $this->reply($c, 'Selecione um Agent autorizado para planejar este objetivo. Nenhum teste foi iniciado.');

            return true;
        }
        app(Catalog::class)->ensure();
        $planning = app(IntentPlanning::class);
        $key = $planning->profile($goal, $agent, $request);
        $profile = Profile::where('key', $key)->where('enabled', true)->first();
        if (! $profile) {
            $this->reply($c, 'O Profile deste objetivo está desabilitado. Nenhum teste substituto foi selecionado.');

            return true;
        }
        try {
            $objective = $planning->context($profile, [...$request, 'operational_intent' => $goal->value]);
            $plan = $planning->preview($profile, $agent, $target, $objective);
        } catch (ValidationException $e) {
            $this->reply($c, implode(' ', $e->validator->errors()->all()).' Nenhum teste foi iniciado.');

            return true;
        }
        $blocked = collect($plan['steps'])->first(fn ($s) => ! $s['optional'] && ! $s['available']);
        $planningMeta = [];
        if ($goal === OperationalIntent::Route) {
            $slot = $agent->fresh()->metadata['diagnostic_preflight_v1'] ?? [];
            $query = $slot['request'] ?? [];
            $protocol = $plan['steps'][0]['input']['protocol'] ?? 'icmp';
            $matching = ($query['destination'] ?? null) === $target && ($query['protocol'] ?? null) === $protocol;
            $reason = $blocked['blocked_reason'] ?? null;
            $authorization = $blocked ? ($reason === 'policy_denied' ? 'DENIED' : 'UNKNOWN') : 'ALLOWED';
            $planningMeta = ['diagnostic_planning' => ['version' => 1, 'objective' => $goal->value, 'profile' => $key,
                'agent_id' => $agent->id, 'target' => $target, 'protocol' => $protocol, 'authorization' => $authorization,
                'status' => match ($reason) {
                    'preflight_pending' => 'PENDING', 'preflight_stale' => 'STALE', 'preflight_timeout' => 'ERROR', 'preflight_busy', 'agent_unavailable', 'preflight_incompatible' => 'UNAVAILABLE', 'helper_unavailable' => 'HELPER_UNAVAILABLE', default => $authorization
                },
                'reason' => $reason, 'checked_at' => now()->toIso8601String(), 'executed' => false,
                'steps' => array_column($plan['steps'], 'key'), 'request_id' => $matching ? ($query['id'] ?? null) : null,
                'policy_revision' => $matching ? ($query['policy_revision'] ?? null) : null,
                'expires_at' => $matching ? ($query['expires_at'] ?? null) : null,
                'dispatched_at' => $matching ? ($slot['dispatched_at'] ?? null) : null,
                'received_at' => $matching ? ($slot['received_at'] ?? null) : null]];
        }
        if ($blocked) {
            $reason = $blocked['blocked_reason'] ?? 'capability_unavailable';
            $explanation = $goal === OperationalIntent::Route ? app(PathReadiness::class)->explanation($reason) : 'As Skills necessárias para este objetivo não estão disponíveis neste Agent/Profile.';
            $alternative = $goal === OperationalIntent::Route ? ' Alternativa: peça explicitamente conectividade básica, que não analisa a rota.' : '';
            $scope = $goal === OperationalIntent::Route ? $planning->scope($plan).' ' : '';
            $body = $c->channel === 'telegram' && $goal === OperationalIntent::Route
                ? app(PlanningReply::class)->blocked($target, $reason)
                : $scope.$explanation.' Objetivo '.$goal->label().': NÃO EXECUTADO; solicitação INCONCLUSIVA.'.$alternative.' Nenhuma tarefa foi criada.';
            if ($goal === OperationalIntent::Route && $matching && ! isset($slot['result']) && ($query['expires_at'] ?? 0) > now()->timestamp && ($query['policy_digest'] ?? null) === app(Preflight::class)->digest($agent->fresh())) {
                $c->context_json = [...$c->context_json, 'preflight_watch' => ['agent_id' => $agent->id, 'request' => $query, 'phase' => 'PENDING']];
                $planningMeta['diagnostic_planning']['status'] = 'PENDING';
                $body = 'A consulta de autorização para analisar a rota até '.$target.' está pendente. Avisarei aqui quando terminar. Nenhum teste foi executado.';
                ResolvePreflight::dispatch($c->tenant_id, $c->id)->delay(now()->setTimestamp($query['expires_at']))->afterCommit();
            }
            $this->reply($c, $body, $planningMeta);

            return true;
        }
        $c->context_json = [...array_diff_key($c->context_json, array_flip(['objective_question', 'requested_objective'])), 'agent_id' => $agent->id,
            'pending' => ['issued_at' => now()->timestamp, 'profile' => $key, 'target' => $target, 'objective' => [...$objective, 'plan_digest' => Digest::hash($plan)]]];
        if ($goal === OperationalIntent::Route && $matching) {
            $c->context_json = [...$c->context_json, 'preflight_watch' => ['agent_id' => $agent->id, 'request' => $query, 'phase' => 'ALLOWED']];
            ResolvePreflight::dispatch($c->tenant_id, $c->id)->delay(now()->setTimestamp(min($query['expires_at'], $slot['dispatched_at'] + 10, $slot['received_at'] + 10)))->afterCommit();
        }
        $this->transition($c, State::Confirmation, 'execution_confirmation');
        $confirmation = $c->channel === 'telegram' && $goal === OperationalIntent::Route ? app(PlanningReply::class)->confirmation($target) : $prefix.$planning->confirmation($plan);
        $this->reply($c, $confirmation, $planningMeta);

        return true;
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

    private function start(Conversation $c, Identity $i, string $profile, string $target, string $prefix, bool $mtr = false, array $objective = []): void
    {
        Gate::forUser($i->user)->authorize('create', Diagnostic::class);
        app(Catalog::class)->ensure();
        app(Planner::class)->target($target);
        $p = Profile::where('key', $profile)->where('enabled', true)->first();
        $agents = Agent::where('status', 'active')->whereNull('revoked_at')->get()->filter(fn ($a) => ($a->metadata['task_protocol'] ?? 0) === 1);
        $a = isset($c->context_json['agent_id']) ? $agents->firstWhere('id', $c->context_json['agent_id']) : ($agents->count() === 1 ? $agents->first() : null);
        if (! $p || ! $a) {
            $this->reply($c, ! $p ? 'Profile pendente indisponível ou desabilitado. Nenhuma tarefa foi criada.' : 'Agent indisponível ou seleção de Agent necessária. Nenhuma tarefa foi criada; abra uma conversa selecionando um Agent autorizado.');

            return;
        }
        $plan = $objective ? app(IntentPlanning::class)->preview($p, $a, $target, $objective) : app(Planner::class)->plan($p, $target, Protocol::executionCapabilities($a), ['mtr_requested' => $mtr]);
        if ($objective && ($objective['plan_digest'] ?? '') !== Digest::hash($plan)) {
            $this->reply($c, 'O escopo ou a disponibilidade mudou desde a confirmação. Nenhum teste foi iniciado. Solicite novamente para revisar o plano.');

            return;
        }
        if (collect($plan['steps'])->contains(fn ($s) => (! $s['optional'] || $mtr && $s['capability'] === 'mtr_path_analysis:v1') && ! $s['available'])) {
            $this->reply($c, 'As Skills necessárias não estão disponíveis neste Agent/Profile. Nenhuma execução foi criada.');

            return;
        }
        $d = app(Tasks::class)->create($p, $a, $target, $i->user, [...$objective, 'mtr_requested' => $mtr, 'related_diagnostic_id' => $c->current_diagnostic_id]);
        $history = $c->context_json['diagnostic_ids'] ?? [];
        $history[] = $d->id;
        $c->forceFill(['current_profile_id' => $p->id, 'current_diagnostic_id' => $d->id, 'current_target' => $target, 'context_json' => [...array_diff_key($c->context_json, array_flip(['pending', 'confirm_cancel', 'requested_profile', 'provided_target', 'objective_question', 'requested_objective'])), 'agent_id' => $a->id, 'last_profile' => $p->key, 'diagnostic_ids' => array_slice($history, -100)]]);
        $this->transition($c, State::Running, 'diagnostic_started');
        $phase = app(DiagnosticExperience::class)->phase($d, $i->user);
        $c->context_json = [...$c->context_json, 'diagnostic_phase' => ['id' => $d->id, 'key' => $phase['key'], 'count' => 1]];
        $this->message($c, 'system', 'text', $prefix.'Solicitação aceita para '.$target.'. '.$phase['text'].' Vou apresentar o resultado aqui quando terminar.', null,
            ['diagnostic_id' => $d->id, 'diagnostic_experience' => DiagnosticExperience::VERSION, 'tracking' => true, 'phase' => $phase['key']], eventKey: 'progress:'.$d->id.':accepted');
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
            abort_unless($conversation->channel === $i->channel && ($i->channel !== 'telegram' || ($conversation->contact_id === $i->contactId && $conversation->telegram_context_id === $i->scopeId)), 403);
            $c = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->synchronize($c, $i);
            $c->save();

            return $c;
        });
    }

    /** Accepted transport commands count as interaction; background polling never does. */
    public function touch(Identity $i, Conversation $conversation): void
    {
        DB::transaction(function () use ($i, $conversation) {
            $this->allowed($i);
            Gate::forUser($i->user)->authorize('view', $conversation);
            abort_unless($conversation->channel === $i->channel && $conversation->contact_id === $i->contactId && $conversation->telegram_context_id === $i->scopeId, 403);
            $this->userLock($i);
            Conversation::whereKey($conversation->id)->where('status', 'OPEN')->update(['last_activity_at' => now()]);
        });
    }

    public function closeIdle(Identity $i, Conversation $conversation): Conversation
    {
        return DB::transaction(function () use ($i, $conversation) {
            $this->allowed($i);
            Gate::forUser($i->user)->authorize('view', $conversation);
            abort_unless($conversation->channel === $i->channel && $conversation->contact_id === $i->contactId && $conversation->telegram_context_id === $i->scopeId, 403);
            $this->userLock($i);
            $c = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            if (! config('conversation.idle_enabled') || ! in_array($i->tenantId, config('conversation.idle_tenants', []), true) || $c->status !== 'OPEN') {
                return $c;
            }
            $this->synchronize($c, $i);
            $c->save();
            // An idle chat must never cancel a task or skip the official result.
            if ($c->state === State::Running->value || ($c->current_diagnostic_id && Diagnostic::whereKey($c->current_diagnostic_id)->whereIn('status', ['PENDING', 'RUNNING'])->exists())) {
                return $c;
            }
            $activity = $c->last_activity_at;
            $result = ConversationMessage::where('conversation_id', $c->id)->whereIn('message_type', ['result', 'interpretation'])->latest('created_at')->first();
            if ($result && $result->created_at->gt($activity)) {
                $activity = $result->created_at;
            }
            if ($activity->gt(now()->subMinutes(config('conversation.idle_minutes', 15)))) {
                return $c;
            }
            $this->transition($c, State::Closed, 'idle_timeout');
            $c->context_json = [...$c->context_json, 'closed_due_to_inactivity' => true];
            $relaxed = ($c->context_json['tone']['style'] ?? '') === 'RELAXED';
            $body = $relaxed
                ? 'Como ficamos 15 minutos sem conversar, vou encerrar este chat por aqui. Valeu pela conversa! O histórico está salvo. Quando precisar, é só mandar outra mensagem.'
                : 'Como não houve interação por 15 minutos, estou encerrando esta conversa. Agradeço pelo contato! O histórico está preservado. Envie uma nova mensagem quando precisar.';
            $this->message($c, 'system', 'text', $body, null, ['idle_notice' => true, 'idle_minutes' => 15], eventKey: 'idle_closed');
            $c->save();

            return $c;
        });
    }

    private function resolvePreflight(Conversation $c, Identity $identity): void
    {
        $watch = $c->context_json['preflight_watch'] ?? null;
        if (! $watch || $c->status !== 'OPEN') {
            return;
        }
        $agent = Agent::find($watch['agent_id']);
        $decision = $agent ? app(Preflight::class)->observation($agent, $watch['request']) : ['state' => 'UNKNOWN', 'reason' => 'agent_unavailable'];
        if ($decision['reason'] === 'preflight_pending') {
            return;
        }
        if ($watch['phase'] === 'ALLOWED' && $decision['state'] === 'ALLOWED') {
            return;
        }
        $c->context_json = array_diff_key($c->context_json, ['preflight_watch' => true]);
        $request = $watch['request'];
        if ($decision['state'] === 'ALLOWED') {
            // Reuse the existing planner and its checks. No task is created here.
            $this->objectiveRequest($c, $identity, 'Quero analisar a rota '.$request['protocol'].' para '.$request['destination'], '');

            return;
        }
        $c->context_json = array_diff_key($c->context_json, ['pending' => true]);
        if ($c->state === State::Confirmation->value) {
            $this->transition($c, State::FollowUp, 'preflight_invalidated');
        }
        $reason = $decision['reason'];
        $status = $decision['state'] === 'DENIED' ? 'DENIED' : match ($reason) {
            'preflight_stale' => 'STALE', 'preflight_timeout' => 'ERROR', 'helper_unavailable' => 'HELPER_UNAVAILABLE', 'agent_unavailable' => 'UNAVAILABLE', default => 'UNKNOWN'
        };
        $meta = ['diagnostic_planning' => ['version' => 1, 'objective' => OperationalIntent::Route->value, 'profile' => $request['protocol'] === 'tcp' ? 'MTR_TCP' : 'MTR_ICMP',
            'agent_id' => $watch['agent_id'], 'request_id' => $request['id'], 'target' => $request['destination'], 'protocol' => $request['protocol'], 'authorization' => $decision['state'], 'status' => $status,
            'reason' => $reason, 'checked_at' => now()->toIso8601String(), 'expires_at' => $request['expires_at'], 'policy_revision' => $request['policy_revision'], 'steps' => [$request['protocol'] === 'tcp' ? 'mtr_tcp' : 'mtr_icmp'], 'dispatched_at' => $agent?->metadata['diagnostic_preflight_v1']['dispatched_at'] ?? null, 'received_at' => $agent?->metadata['diagnostic_preflight_v1']['received_at'] ?? null, 'executed' => false]];
        $body = $reason === 'preflight_stale'
            ? 'A consulta de autorização venceu ou perdeu a validade. Para continuar, solicite uma nova análise de rota. Nenhum teste foi executado.'
            : app(PlanningReply::class)->blocked($request['destination'], $reason);
        $this->message($c, 'system', 'text', $body, null, $meta, eventKey: 'preflight:'.$request['id'].':'.$status);
    }

    private function synchronize(Conversation $c, Identity $identity): void
    {
        $this->resolvePreflight($c, $identity);
        if (! $c->current_diagnostic_id) {
            return;
        }
        $d = Diagnostic::findOrFail($c->current_diagnostic_id);
        if (in_array($d->status, ['PENDING', 'RUNNING'], true)) {
            $phase = app(DiagnosticExperience::class)->phase($d, $identity->user);
            $previous = $c->context_json['diagnostic_phase'] ?? [];
            if (($previous['id'] ?? null) !== $d->id || ($previous['key'] ?? null) !== $phase['key']) {
                $count = ($previous['id'] ?? null) === $d->id ? ($previous['count'] ?? 0) : 0;
                if ($count < 12) {
                    $this->message($c, 'system', 'text', 'Acompanhamento de '.Text::clean($d->target)."\n".$phase['text'], null,
                        ['diagnostic_id' => $d->id, 'diagnostic_experience' => DiagnosticExperience::VERSION, 'tracking' => true, 'phase' => $phase['key']], eventKey: 'progress:'.$d->id.':'.$c->sequence);
                    $count++;
                }
                $c->context_json = [...$c->context_json, 'diagnostic_phase' => ['id' => $d->id, 'key' => $phase['key'], 'count' => $count]];
            }
        }
        if (in_array($d->status, ['COMPLETED', 'FAILED', 'CANCELLED'], true)) {
            $official = DiagnosticConclusion::where('diagnostic_id', $d->id)->first();
            if ($official) {
                $key = 'result:'.$d->id;
                if (! ConversationMessage::where('conversation_id', $c->id)->where('event_key', $key)->exists()) {
                    if ($c->state === 'DIAGNOSTIC_RUNNING') {
                        $this->transition($c, $d->status === 'CANCELLED' ? State::Cancelled : State::Completed, 'diagnostic_finished');
                    }
                    $result = app(DiagnosticExperience::class)->result($c, $d, $identity);
                    $this->message($c, 'system', 'result', $result['text'], null, $result['metadata'], eventKey: $key);
                    app(Audit::class)->record('conversation.diagnostic_presented', $identity->user, $c->tenant_id, 'conversation', metadata: ['conversation_id' => $c->id, ...$result['metadata']]);
                }
            }
            $interp = DiagnosticInterpretation::where('diagnostic_id', $d->id)->where('status', 'ASSISTED')->latest('created_at')->first();
            if ($interp && ! ConversationMessage::where('conversation_id', $c->id)->where('event_key', 'interpretation:'.$interp->id)->exists()) {
                $this->message($c, 'system', 'interpretation', 'Interpretação assistida: '.$interp->summary."\n".$interp->explanation, null, ['interpretation_id' => $interp->id], eventKey: 'interpretation:'.$interp->id);
            }
        }
    }

    private function details(Conversation $c, array $tone, Identity $identity): void
    {
        $official = $c->current_diagnostic_id ? DiagnosticConclusion::where('diagnostic_id', $c->current_diagnostic_id)->first() : null;
        if (! $official) {
            $this->reply($c, 'Ainda não há conclusão disponível. Informe um alvo para começar.');

            return;
        }
        $d = Diagnostic::findOrFail($c->current_diagnostic_id);
        $snapshot = app(DiagnosticExperience::class)->snapshot($d, $identity->user);
        $coverage = $snapshot['coverage'];
        $text = 'Diagnóstico de '.$snapshot['target']."\nClassificação técnica: ".$official->classification."\n".$snapshot['summary']
            ."\n".$coverage['label'].': '.$coverage['status_label']."\nConclusão da solicitação: ".$coverage['request_classification']
            ."\n".implode("\n", array_column($snapshot['facts'], 'text'))."\nLimites: ".implode(' ', $snapshot['limitations'])."\n".implode("\n", $snapshot['next_steps']);
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
        if (in_array($next, [State::Closed, State::Cancelled], true)) {
            $c->context_json = array_diff_key($c->context_json, array_flip(['pending', 'confirm_cancel', 'pending_question', 'objective_question', 'requested_objective', 'preflight_watch']));
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
        if ($sender === 'system' && $type === 'text' && ! isset($meta['diagnostic_experience'])) {
            app(ContextualTurn::class)->question($c, $m->body, $c->sequence);
        }
    }
}
