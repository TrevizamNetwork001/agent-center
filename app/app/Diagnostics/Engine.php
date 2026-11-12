<?php

namespace App\Diagnostics;

use App\Agent\Protocol;
use App\Models\Agent;
use App\Models\AgentOperation;
use App\Models\AgentTask;
use App\Models\AgentTaskAttempt;
use App\Models\Diagnostic;
use App\Models\DiagnosticConclusion;
use App\Models\DiagnosticPlan;
use App\Models\DiagnosticStep;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Engine
{
    public const LEASE_SECONDS = 45;

    private function stamp(): string
    {
        return $this->clock()->utc()->toISOString();
    }

    private function mark(Model $model, array $values): void
    {
        $model->forceFill(['performance' => [...($model->performance ?? []), ...$values]])->save();
    }

    public function clock(): CarbonImmutable
    {
        return CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp() AS t')->t);
    }

    public function audit(string $event, Diagnostic $d, ?User $user = null): void
    {
        app(Audit::class)->record($event, $user, $d->tenant_id, 'diagnostic', metadata: ['diagnostic_id' => $d->id, 'agent_id' => $d->agent_id]);
    }

    public function create(Profile $p, Agent $a, string $target, User $u, array $context = []): Diagnostic
    {
        $tenant = app(TenantContext::class)->tenantId;
        abort_unless($tenant && $p->tenant_id === $tenant && $a->tenant_id === $tenant, 403);
        app(Planner::class)->validateTarget($p, $target);
        $objective = app(IntentPlanning::class)->context($p, $context);
        // Legacy Profiles keep their step contracts; explicit objectives use V1.
        if (! isset($context['operational_intent']) && ! in_array($p->key, ['MTR_ICMP', 'MTR_TCP', 'CAMINHO_TRACEROUTE', 'CONECTIVIDADE_BASICA', 'PORTA_SERVICO'], true)) {
            unset($objective['intent_planning_version']);
        }
        $context = [...$context, ...$objective];
        abort_unless($p->enabled && $a->status === 'active' && ! $a->revoked_at && ($a->metadata['task_protocol'] ?? 0) === 1, 422, 'Agent ou Profile indisponível para tarefas.');

        return DB::transaction(function () use ($p, $a, $target, $u, $context) {
            $d = new Diagnostic;
            $d->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => app(TenantContext::class)->tenantId, 'profile_id' => $p->id, 'agent_id' => $a->id, 'created_by' => $u->id, 'target' => $target, 'performance' => ['requested_at' => $this->stamp()], 'context' => [...array_intersect_key($context, array_flip(['operational_intent', 'intent_planning_version', 'protocol', 'port', 'path_metrics'])), 'trace_requested' => ($context['trace_requested'] ?? false) === true, 'mtr_requested' => ($context['mtr_requested'] ?? false) === true, 'related_diagnostic_id' => $context['related_diagnostic_id'] ?? null], 'status' => 'PENDING'])->save();
            $this->audit('diagnostic.created', $d, $u);

            return $d;
        });
    }

    public function cancel(Diagnostic $d, User $u): void
    {
        DB::transaction(function () use ($d, $u) {
            $d = Diagnostic::whereKey($d->id)->lockForUpdate()->firstOrFail();
            if (! in_array($d->status, ['PENDING', 'RUNNING']) || $d->cancel_requested_at) {
                return;
            }$d->forceFill(['cancel_requested_at' => $this->clock()])->save();
            $this->audit('diagnostic.cancel_requested', $d, $u);
            $this->advance($d);
        });
    }

    public function tick(): int
    {
        $count = 0;
        foreach (Diagnostic::whereIn('status', ['PENDING', 'RUNNING'])->orderBy('created_at')->limit(100)->pluck('id') as $id) {
            DB::transaction(function () use ($id, &$count) {
                $d = Diagnostic::whereKey($id)->whereIn('status', ['PENDING', 'RUNNING'])->lock('FOR UPDATE SKIP LOCKED')->first();
                if (! $d) {
                    return;
                }
                $entered = $this->stamp();
                $this->advance($d);
                $this->mark($d, ['worker_entered_at' => $entered, 'worker_finished_at' => $this->stamp(), 'worker_ticks' => ($d->performance['worker_ticks'] ?? 0) + 1]);
                $count++;
            });
        }

        return $count;
    }

    private function advance(Diagnostic $d, string $origin = 'worker'): void
    {
        $now = $this->clock();
        if ($d->status === 'PENDING' && ! $d->cancel_requested_at) {
            $this->mark($d, ['planning_started_at' => $this->stamp()]);
            $p = Profile::find($d->profile_id);
            $a = Agent::withTrashed()->find($d->agent_id);
            if (! $p || ! $p->enabled || ! $a || $a->deleted_at || $a->revoked_at || $a->status !== 'active') {
                $d->forceFill(['error_code' => ! $p || ! $p->enabled ? 'profile_unavailable' : 'agent_unavailable'])->save();
            } else {
                $doc = app(Planner::class)->plan($p, $d->target, Protocol::executionCapabilities($a), [...($d->context ?? []), 'agent' => $a, 'agent_version' => $a->version, 'dns_resolvers' => $a->metadata['telemetry']['dns_resolvers'] ?? []]);
                $plan = new DiagnosticPlan;
                $plan->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $d->tenant_id, 'diagnostic_id' => $d->id, 'version' => 1, 'digest' => Digest::hash($doc), 'document' => $doc, 'created_at' => $now])->save();
                foreach ($doc['steps'] as $s) {
                    $step = new DiagnosticStep;
                    $step->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $d->tenant_id, 'diagnostic_id' => $d->id, 'key' => $s['key'], 'logical_group' => $s['logical_group'], 'capability' => $s['capability'], 'wave' => $s['wave'], 'optional' => $s['optional'], 'status' => $s['available'] ? 'PENDING' : ($s['optional'] ? 'SKIPPED' : 'BLOCKED'), 'definition' => $s, 'error_code' => $s['available'] ? null : ($s['blocked_reason'] ?? 'capability_unavailable')])->save();
                }
                $d->forceFill(['status' => 'RUNNING', 'started_at' => $now])->save();
                $this->mark($d, ['planned_at' => $this->stamp()]);
                $this->audit('diagnostic.started', $d);
            }
        }
        if ($d->status === 'RUNNING') {
            $agent = Agent::withTrashed()->find($d->agent_id);
            if (! $agent || $agent->deleted_at || $agent->revoked_at || $agent->status !== 'active') {
                $d->forceFill(['error_code' => 'agent_unavailable'])->save();
                $taskIds = AgentTask::where('diagnostic_id', $d->id)->pluck('id');
                AgentTaskAttempt::whereIn('task_id', $taskIds)->whereIn('status', ['CLAIMED', 'RUNNING'])->update(['status' => 'EXPIRED', 'finished_at' => $now]);
                AgentTask::where('diagnostic_id', $d->id)->whereIn('status', ['QUEUED', 'CLAIMED', 'RUNNING'])->update(['status' => 'EXPIRED', 'finished_at' => $now]);
                DiagnosticStep::where('diagnostic_id', $d->id)->whereIn('status', ['PENDING', 'QUEUED', 'RUNNING'])->update(['status' => 'EXPIRED', 'error_code' => 'agent_unavailable', 'finished_at' => $now]);
            }
        }
        $tasks = AgentTask::where('diagnostic_id', $d->id)->orderBy('id')->lockForUpdate()->get();
        foreach ($tasks as $task) {
            $attempt = AgentTaskAttempt::where('task_id', $task->id)->whereIn('status', ['CLAIMED', 'RUNNING'])->lockForUpdate()->first();
            if ($attempt && $attempt->lease_expires_at->lessThanOrEqualTo($now)) {
                $wasRunning = $attempt->status === 'RUNNING';
                $attempt->forceFill(['status' => 'EXPIRED', 'finished_at' => $now])->save();
                $retry = ! $wasRunning && $task->generation < 2 && ! $d->cancel_requested_at;
                $task->forceFill(['status' => $retry ? 'QUEUED' : 'EXPIRED', 'finished_at' => $retry ? null : $now])->save();
                DiagnosticStep::whereKey($task->step_id)->update(['status' => $retry ? 'QUEUED' : 'EXPIRED', 'error_code' => $retry ? null : 'lease_lost', 'result' => $retry ? null : json_encode(['ok' => false, 'error' => 'lease_lost']), 'finished_at' => $retry ? null : $now]);
            }
            if ($d->cancel_requested_at && in_array($task->status, ['QUEUED', 'CLAIMED', 'RUNNING'])) {
                $task->forceFill(['cancel_requested_at' => $d->cancel_requested_at])->save();
                if ($task->status === 'QUEUED' || $task->status === 'CLAIMED') {
                    if ($attempt && $attempt->status === 'CLAIMED') {
                        $attempt->forceFill(['status' => 'FAILED', 'finished_at' => $now])->save();
                    }
                    $task->forceFill(['status' => 'CANCELLED', 'finished_at' => $now])->save();
                    DiagnosticStep::whereKey($task->step_id)->update(['status' => 'CANCELLED', 'finished_at' => $now]);
                }
            }
        }
        if ($d->cancel_requested_at) {
            DiagnosticStep::where('diagnostic_id', $d->id)->where('status', 'PENDING')->update(['status' => 'CANCELLED', 'finished_at' => $now]);
        }
        // A bounded deadline also closes queued work when the assigned Agent is offline.
        if ($d->created_at->lt($now->subSeconds(300))) {
            AgentTaskAttempt::whereIn('task_id', $tasks->pluck('id'))->whereIn('status', ['CLAIMED', 'RUNNING'])->update(['status' => 'EXPIRED', 'finished_at' => $now]);
            AgentTask::where('diagnostic_id', $d->id)->whereIn('status', ['QUEUED', 'CLAIMED', 'RUNNING'])->update(['status' => 'EXPIRED', 'finished_at' => $now]);
            DiagnosticStep::where('diagnostic_id', $d->id)->whereIn('status', ['PENDING', 'QUEUED', 'RUNNING'])->update(['status' => 'EXPIRED', 'error_code' => 'diagnostic_timeout', 'finished_at' => $now]);
            $d->forceFill(['error_code' => 'diagnostic_timeout'])->save();
        }
        $steps = DiagnosticStep::where('diagnostic_id', $d->id)->orderBy('wave')->orderBy('key')->get();
        $evidence = [];
        foreach ($steps as $s) {
            if ($s->result) {
                $evidence[$s->key] = $s->result;
            }
        }
        $plan = DiagnosticPlan::where('diagnostic_id', $d->id)->first();
        $thresholds = $plan?->document['thresholds'] ?? [];
        if (! $d->cancel_requested_at) {
            foreach ($steps->where('status', 'PENDING') as $step) {
                if ($steps->contains(fn ($s) => $s->wave < $step->wave && in_array($s->status, ['PENDING', 'QUEUED', 'RUNNING']))) {
                    continue;
                }
                $def = $step->definition;
                if (! app(Planner::class)->condition($def['condition'], $evidence, $thresholds)) {
                    $step->forceFill(['status' => $def['condition'] === 'trace_needed' ? 'SKIPPED' : 'BLOCKED', 'error_code' => 'condition_not_met', 'finished_at' => $now])->save();

                    continue;
                }
                $input = $def['input'];
                if ($step->capability !== 'dns_lookup:v1' && ! ($plan->document['target']['literal'] ?? false)) {
                    $ips = [...($evidence['dns_a']['data']['answers'] ?? []), ...($evidence['dns_aaaa']['data']['answers'] ?? [])];
                    if (! $ips) {
                        $step->forceFill(['status' => 'BLOCKED', 'error_code' => 'dns_unavailable', 'finished_at' => $now])->save();

                        continue;
                    }
                    $input['target'] = $ips[0];
                }
                $task = new AgentTask;
                $task->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $d->tenant_id, 'diagnostic_id' => $d->id, 'step_id' => $step->id, 'agent_id' => $d->agent_id, 'capability' => $step->capability, 'input' => $input, 'timeout' => $def['timeout'], 'generation' => 0, 'status' => 'QUEUED'])->save();
                $previous = $steps->where('wave', '<', $step->wave)->pluck('performance')->map(fn ($p) => $p['result_recorded_at'] ?? null)->filter()->sortDesc()->first();
                $this->mark($step, ['enqueued_at' => $this->stamp(), 'previous_result_at' => $previous, 'enqueue_source' => $origin]);
                $step->forceFill(['status' => 'QUEUED'])->save();
            }
        }
        $steps = DiagnosticStep::where('diagnostic_id', $d->id)->get();
        if ($steps->contains(fn ($s) => in_array($s->status, ['PENDING', 'QUEUED', 'RUNNING']))) {
            return;
        }
        $evidence = [];
        foreach ($steps as $s) {
            if ($s->result) {
                $evidence[$s->key] = $s->result;
            }
        }
        $out = app(Classifier::class)->classify($plan?->document['profile'] ?? '', $evidence, $thresholds, (bool) $d->cancel_requested_at, $d->error_code);
        $conclusion = new DiagnosticConclusion;
        $conclusion->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $d->tenant_id, 'diagnostic_id' => $d->id, ...$out, 'created_at' => $now])->save();
        $status = $d->cancel_requested_at ? 'CANCELLED' : ($d->error_code || $out['classification'] === 'ERRO_DE_EXECUCAO' ? 'FAILED' : 'COMPLETED');
        $d->forceFill(['status' => $status, 'finished_at' => $now])->save();
        $this->mark($d, ['completed_at' => $this->stamp()]);
        $this->audit('diagnostic.'.strtolower($status), $d);
    }

    public function claim(Agent $agent, int $capacity = 1): ?array
    {

        $received = $this->stamp();

        return DB::transaction(function () use ($agent, $capacity, $received) {
            $agent = Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $previousPoll = $agent->metadata['diagnostic_poll']['received_at'] ?? null;
            $poll = ['received_at' => $received, 'locked_at' => $this->stamp(), 'previous_received_at' => $previousPoll, 'capacity' => $capacity, 'queued' => AgentTask::where('agent_id', $agent->id)->where('status', 'QUEUED')->count()];
            $agent->forceFill(['last_poll_at' => $this->clock(), 'metadata' => [...$agent->metadata, 'diagnostic_poll' => $poll]])->save();
            if (AgentOperation::where('agent_id', $agent->id)->whereIn('status', ['PRECHECK_REQUESTED', 'PRECHECKING', 'APPROVED', 'RUNNING', 'UNCERTAIN'])->exists()) {
                return null;
            }
            if (! $capacity || $agent->revoked_at || AgentTask::where('agent_id', $agent->id)->whereIn('status', ['CLAIMED', 'RUNNING'])->exists()) {
                return null;
            }
            foreach (AgentTask::where('agent_id', $agent->id)->where('status', 'QUEUED')->orderByRaw('(SELECT last_dispatched_at FROM diagnostics WHERE diagnostics.id = agent_tasks.diagnostic_id AND diagnostics.tenant_id = agent_tasks.tenant_id) ASC NULLS FIRST')->orderBy('created_at')->orderBy('id')->limit(100)->get() as $candidate) {
                $d = Diagnostic::whereKey($candidate->diagnostic_id)->lock('FOR UPDATE SKIP LOCKED')->first();
                if (! $d || $d->status !== 'RUNNING' || $d->cancel_requested_at) {
                    continue;
                }
                $task = AgentTask::whereKey($candidate->id)->lockForUpdate()->first();
                if ($task->status !== 'QUEUED') {
                    continue;
                }
                if (! in_array($task->capability, Protocol::executionCapabilities($agent), true)) {
                    $task->forceFill(['status' => 'FAILED', 'finished_at' => $this->clock()])->save();
                    DiagnosticStep::whereKey($task->step_id)->update(['status' => 'BLOCKED', 'error_code' => 'capability_unavailable', 'finished_at' => $this->clock()]);

                    continue;
                }
                if ($task->capability === 'mtr_path_analysis:v1' || ($d->context['operational_intent'] ?? null) === OperationalIntent::Route->value && $task->capability === 'traceroute:v1') {
                    $host = app(Planner::class)->target($d->target)['host'];
                    $reason = app(PathReadiness::class)->check($agent, $task->capability, $host, $task->input['protocol'] ?? 'icmp');
                    if ($reason) {
                        $task->forceFill(['status' => 'FAILED', 'finished_at' => $this->clock()])->save();
                        DiagnosticStep::whereKey($task->step_id)->update(['status' => 'BLOCKED', 'error_code' => $reason, 'finished_at' => $this->clock()]);

                        continue;
                    }
                }
                if ($task->capability === 'mtr_path_analysis:v1' && AgentTask::where('agent_id', $agent->id)->where('capability', 'mtr_path_analysis:v1')->where('started_at', '>', $this->clock()->subSeconds(10))->exists()) {
                    continue;
                }
                $now = $this->clock();
                $token = bin2hex(random_bytes(32));
                $generation = $task->generation + 1;
                $attempt = new AgentTaskAttempt;
                $attempt->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $task->tenant_id, 'task_id' => $task->id, 'generation' => $generation, 'status' => 'CLAIMED', 'lease_hash' => hash('sha256', $token), 'lease_expires_at' => $now->addSeconds(self::LEASE_SECONDS), 'performance' => ['claim_received_at' => $received, 'claimed_at' => $this->stamp(), 'poll' => $poll]])->save();
                $d->forceFill(['last_dispatched_at' => $this->clock()->format('Y-m-d H:i:s.uP')])->save();
                $this->mark(DiagnosticStep::findOrFail($task->step_id), ['claimed_at' => $this->stamp(), 'generation' => $generation]);
                $task->forceFill(['status' => 'CLAIMED', 'generation' => $generation])->save();

                return ['task_id' => $task->id, 'execution_id' => $attempt->id, 'generation' => $generation, 'lease_token' => $token, 'lease_seconds' => self::LEASE_SECONDS, 'renew_interval' => 10, 'timeout' => $task->timeout, 'capability' => $task->capability, 'input' => $task->input];
            }

            return null;
        });
    }

    public function activeCancellations(Agent $a): array
    {
        return AgentTaskAttempt::whereIn('task_id', AgentTask::where('agent_id', $a->id)->whereNotNull('cancel_requested_at')->select('id'))->whereIn('status', ['CLAIMED', 'RUNNING'])->pluck('id')->all();
    }

    public function operation(Agent $agent, string $action, array $r): array
    {
        $received = $this->stamp();

        return DB::transaction(function () use ($agent, $action, $r, $received) {
            $candidate = AgentTask::whereKey($r['task_id'])->where('agent_id', $agent->id)->first();
            abort_unless($candidate, response()->json(['error' => 'no_active_task', 'retryable' => false], 409));
            $d = Diagnostic::whereKey($candidate->diagnostic_id)->lockForUpdate()->firstOrFail();
            $task = AgentTask::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            $a = AgentTaskAttempt::whereKey($r['execution_id'])->where('task_id', $task->id)->lockForUpdate()->first();
            abort_unless($a && $a->generation === ($r['generation'] ?? null) && $task->generation === $a->generation && hash_equals($a->lease_hash, hash('sha256', $r['lease_token'])), 409, 'stale_attempt');
            $result = null;
            $digest = null;
            if ($action === 'result') {
                $result = app(Normalizer::class)->normalize($task->capability, $r['result'] ?? []);
                if ($result['ok'] && $task->capability === 'dns_lookup:v1') {
                    abort_unless(($result['data']['record_type'] ?? null) === ($task->input['record_type'] ?? null), 422, 'record_type_mismatch');
                    foreach (['transport', 'resolver'] as $field) {
                        if (isset($task->input[$field])) {
                            abort_unless(($result['data'][$field] ?? null) === $task->input[$field], 422, 'dns_transport_mismatch');
                        }
                    }
                }
                if ($result['ok'] && $task->capability === 'http_probe:v1') {
                    abort_unless(@inet_pton($result['data']['resolved_ip']) === @inet_pton($task->input['target']), 422, 'target_mismatch');
                }
                if ($result['ok'] && $task->capability === 'mtr_path_analysis:v1') {
                    foreach (['destination', 'protocol', 'cycles'] as $field) {
                        abort_unless($result['data'][$field] === $task->input[$field], 422, 'mtr_input_mismatch');
                    }
                    abort_unless(@inet_pton($result['data']['resolved_ip']) === @inet_pton($task->input['target']), 422, 'target_mismatch');
                }
                $digest = Digest::hash($result);
                if (in_array($a->status, ['COMPLETED', 'FAILED']) && $a->result_digest) {
                    abort_unless(hash_equals($a->result_digest, $digest), 409, 'result_conflict');

                    return ['status' => 'ok', 'duplicate' => true];
                }
            }
            $now = $this->clock();
            abort_unless(in_array($a->status, ['CLAIMED', 'RUNNING']) && $a->lease_expires_at->greaterThan($now), 409, 'lease_expired');
            if ($d->cancel_requested_at || $task->cancel_requested_at) {
                if ($action === 'result' && ($result['error'] ?? null) === 'cancelled') {
                    $a->forceFill(['status' => 'FAILED', 'finished_at' => $now, 'result_digest' => $digest])->save();
                    $task->forceFill(['status' => 'CANCELLED', 'finished_at' => $now])->save();
                    DiagnosticStep::whereKey($task->step_id)->update(['status' => 'CANCELLED', 'finished_at' => $now]);

                    return ['status' => 'ok', 'cancelled' => true];
                }

                return ['status' => 'cancel_requested', 'cancel' => true];
            }
            if ($action === 'start') {
                if ($task->capability === 'mtr_path_analysis:v1') {
                    $reason = app(PathReadiness::class)->check($agent, $task->capability, $task->input['destination'], $task->input['protocol']);
                    if ($reason) {
                        $a->forceFill(['status' => 'FAILED', 'finished_at' => $now])->save();
                        $task->forceFill(['status' => 'FAILED', 'finished_at' => $now])->save();
                        DiagnosticStep::whereKey($task->step_id)->update(['status' => 'BLOCKED', 'error_code' => $reason, 'finished_at' => $now]);

                        return ['status' => 'blocked', 'reason' => $reason];
                    }
                }
                if ($a->status === 'CLAIMED') {
                    $this->mark($a, ['start_received_at' => $received, 'start_accepted_at' => $this->stamp()]);
                    $this->mark(DiagnosticStep::findOrFail($task->step_id), ['started_at' => $this->stamp()]);
                    $a->forceFill(['status' => 'RUNNING', 'started_at' => $now, 'lease_expires_at' => $now->addSeconds(self::LEASE_SECONDS)])->save();
                    $task->forceFill(['status' => 'RUNNING', 'started_at' => $now])->save();
                    DiagnosticStep::whereKey($task->step_id)->update(['status' => 'RUNNING', 'started_at' => $now]);
                }

                return ['status' => 'ok', 'lease_seconds' => self::LEASE_SECONDS];
            }
            abort_unless($a->status === 'RUNNING', 409, 'not_started');
            if ($action === 'renew') {
                $a->forceFill(['lease_expires_at' => $now->addSeconds(self::LEASE_SECONDS)])->save();

                return ['status' => 'ok', 'lease_seconds' => self::LEASE_SECONDS];
            }
            $this->mark($a, ['result_received_at' => $received, 'result_recorded_at' => $this->stamp(), 'probe_duration_ms' => $result['data']['duration_ms'] ?? $result['data']['total_duration_ms'] ?? null]);
            $this->mark(DiagnosticStep::findOrFail($task->step_id), ['result_received_at' => $received, 'result_recorded_at' => $this->stamp()]);
            $status = $result['ok'] ? 'COMPLETED' : 'FAILED';
            $a->forceFill(['status' => $status, 'result_digest' => $digest, 'finished_at' => $now])->save();
            $task->forceFill(['status' => $status, 'finished_at' => $now])->save();
            DiagnosticStep::whereKey($task->step_id)->update(['status' => $status, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'error_code' => $result['error'] ?? null, 'finished_at' => $now]);
            // Advance only after fenced, normalized, durable result acceptance in this same transaction.
            $this->advance($d, 'accepted_result');

            return ['status' => 'ok'];
        });
    }

    public function reconcile(Agent $agent, array $executions): array
    {
        return array_map(function ($e) use ($agent) {
            $t = AgentTask::whereKey($e['task_id'])->where('agent_id', $agent->id)->first();
            $a = $t ? AgentTaskAttempt::whereKey($e['execution_id'])->where('task_id', $t->id)->first() : null;
            $disposition = 'hold';
            if ($a) {
                if ($a->result_digest) {
                    $disposition = 'acknowledged';
                } elseif ($t->cancel_requested_at) {
                    $disposition = 'cancel';
                } elseif ($a->status === 'EXPIRED' || $a->lease_expires_at->lessThanOrEqualTo($this->clock())) {
                    $disposition = 'expired';
                } elseif ($e['state'] === 'result_pending') {
                    $disposition = 'resend_result';
                }
            }

            return ['execution_id' => $e['execution_id'], 'disposition' => $disposition];
        }, $executions);
    }
}
