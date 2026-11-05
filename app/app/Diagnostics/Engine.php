<?php

namespace App\Diagnostics;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Engine
{
    public const LEASE_SECONDS = 45;

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
        app(Planner::class)->target($target);
        abort_unless($p->enabled && $a->status === 'active' && ! $a->revoked_at && ($a->metadata['task_protocol'] ?? 0) === 1, 422, 'Agent ou Profile indisponível para tarefas.');

        return DB::transaction(function () use ($p, $a, $target, $u, $context) {
            $d = new Diagnostic;
            $d->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => app(TenantContext::class)->tenantId, 'profile_id' => $p->id, 'agent_id' => $a->id, 'created_by' => $u->id, 'target' => $target, 'context' => ['trace_requested' => ($context['trace_requested'] ?? false) === true], 'status' => 'PENDING'])->save();
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
                }$this->advance($d);
                $count++;
            });
        }

        return $count;
    }

    private function advance(Diagnostic $d): void
    {
        $now = $this->clock();
        if ($d->status === 'PENDING' && ! $d->cancel_requested_at) {
            $p = Profile::findOrFail($d->profile_id);
            $a = Agent::findOrFail($d->agent_id);
            $doc = app(Planner::class)->plan($p, $d->target, $a->capabilities, $d->context ?? []);
            $plan = new DiagnosticPlan;
            $plan->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $d->tenant_id, 'diagnostic_id' => $d->id, 'version' => 1, 'digest' => Digest::hash($doc), 'document' => $doc, 'created_at' => $now])->save();
            foreach ($doc['steps'] as $s) {
                $step = new DiagnosticStep;
                $step->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $d->tenant_id, 'diagnostic_id' => $d->id, 'key' => $s['key'], 'logical_group' => $s['logical_group'], 'capability' => $s['capability'], 'wave' => $s['wave'], 'optional' => $s['optional'], 'status' => $s['available'] ? 'PENDING' : ($s['optional'] ? 'SKIPPED' : 'BLOCKED'), 'definition' => $s, 'error_code' => $s['available'] ? null : 'capability_unavailable'])->save();
            }
            $d->forceFill(['status' => 'RUNNING', 'started_at' => $now])->save();
            $this->audit('diagnostic.started', $d);
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
        $out = app(Classifier::class)->classify($plan?->document['profile'] ?? '', $evidence, $thresholds, (bool) $d->cancel_requested_at);
        $conclusion = new DiagnosticConclusion;
        $conclusion->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $d->tenant_id, 'diagnostic_id' => $d->id, ...$out, 'created_at' => $now])->save();
        $status = $d->cancel_requested_at ? 'CANCELLED' : ($d->error_code || $out['classification'] === 'ERRO_DE_EXECUCAO' ? 'FAILED' : 'COMPLETED');
        $d->forceFill(['status' => $status, 'finished_at' => $now])->save();
        $this->audit('diagnostic.'.strtolower($status), $d);
    }

    public function claim(Agent $agent, int $capacity = 1): ?array
    {

        return DB::transaction(function () use ($agent, $capacity) {
            $agent = Agent::whereKey($agent->id)->lockForUpdate()->firstOrFail();
            $agent->forceFill(['last_poll_at' => $this->clock()])->save();
            if (AgentOperation::where('agent_id', $agent->id)->whereNotIn('status', ['COMPLETED', 'FAILED'])->exists()) {
                return null;
            }
            if (! $capacity || $agent->revoked_at || AgentTask::where('agent_id', $agent->id)->whereIn('status', ['CLAIMED', 'RUNNING'])->exists()) {
                return null;
            }
            foreach (AgentTask::where('agent_id', $agent->id)->where('status', 'QUEUED')->orderBy('created_at')->limit(20)->get() as $candidate) {
                $d = Diagnostic::whereKey($candidate->diagnostic_id)->lock('FOR UPDATE SKIP LOCKED')->first();
                if (! $d || $d->status !== 'RUNNING' || $d->cancel_requested_at) {
                    continue;
                }
                $task = AgentTask::whereKey($candidate->id)->lockForUpdate()->first();
                if ($task->status !== 'QUEUED') {
                    continue;
                }
                if (! in_array($task->capability, $agent->capabilities, true)) {
                    continue;
                }
                $now = $this->clock();
                $token = bin2hex(random_bytes(32));
                $generation = $task->generation + 1;
                $attempt = new AgentTaskAttempt;
                $attempt->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $task->tenant_id, 'task_id' => $task->id, 'generation' => $generation, 'status' => 'CLAIMED', 'lease_hash' => hash('sha256', $token), 'lease_expires_at' => $now->addSeconds(self::LEASE_SECONDS)])->save();
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
        return DB::transaction(function () use ($agent, $action, $r) {
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
                if ($a->status === 'CLAIMED') {
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
            $status = $result['ok'] ? 'COMPLETED' : 'FAILED';
            $a->forceFill(['status' => $status, 'result_digest' => $digest, 'finished_at' => $now])->save();
            $task->forceFill(['status' => $status, 'finished_at' => $now])->save();
            DiagnosticStep::whereKey($task->step_id)->update(['status' => $status, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'error_code' => $result['error'] ?? null, 'finished_at' => $now]);

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
