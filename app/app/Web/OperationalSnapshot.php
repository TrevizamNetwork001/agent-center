<?php

namespace App\Web;

use App\Diagnostics\WorkerHealth;
use App\Jobs\OperationsPulse;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\AgentTaskAttempt;
use App\Models\Diagnostic;
use App\Models\DiagnosticConclusion;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class OperationalSnapshot
{
    public function get(): array
    {
        $v = ['database' => false, 'worker' => WorkerHealth::healthy(), 'agents_total' => null, 'agents_online' => null, 'heartbeat_age' => null, 'poll_age' => null, 'missing_heartbeat' => 0, 'missing_poll' => 0, 'today' => null, 'indications' => null, 'active' => null, 'stale' => null, 'failed' => null, 'state' => 'NO-GO'];
        try {
            DB::select('SELECT 1');
            $v['database'] = true;
            $a = Agent::where('status', 'active')->whereNull('revoked_at')->selectRaw('count(*) total,count(*) FILTER (WHERE last_heartbeat_at > ?) online, min(last_heartbeat_at) heartbeat,min(last_poll_at) poll,count(*) FILTER(WHERE last_heartbeat_at IS NULL) missing_heartbeat,count(*) FILTER(WHERE last_poll_at IS NULL) missing_poll', [now()->subSeconds(60)])->first();
            $v['agents_total'] = (int) $a->total;
            $v['agents_online'] = (int) $a->online;
            $v['missing_heartbeat'] = (int) $a->missing_heartbeat;
            $v['missing_poll'] = (int) $a->missing_poll;
            $v['heartbeat_age'] = $a->heartbeat ? max(0, (int) CarbonImmutable::parse($a->heartbeat)->diffInSeconds(now())) : null;
            $v['poll_age'] = $a->poll ? max(0, (int) CarbonImmutable::parse($a->poll)->diffInSeconds(now())) : null;
            $v['today'] = Diagnostic::where('created_at', '>=', now()->startOfDay())->count();
            $v['indications'] = DiagnosticConclusion::where('classification', 'INDICIO_DE_FALHA')->whereIn('diagnostic_id', Diagnostic::where('created_at', '>=', now()->startOfDay())->select('id'))->count();
            $v['active'] = AgentTask::whereIn('status', ['CLAIMED', 'RUNNING'])->count();
            $v['stale'] = AgentTaskAttempt::whereIn('status', ['CLAIMED', 'RUNNING'])->whereRaw('lease_expires_at <= clock_timestamp()')->count();
            $v['failed'] = AgentTaskAttempt::whereIn('status', ['FAILED', 'EXPIRED'])->where('created_at', '>=', now()->startOfDay())->count();
            $v['state'] = ! $v['worker'] || $v['stale'] > 0 ? 'NO-GO' : ($v['agents_total'] === 0 || $v['agents_online'] < $v['agents_total'] || $v['missing_heartbeat'] || $v['missing_poll'] || ($v['poll_age'] ?? 0) > 30 || $v['indications'] > 0 || $v['failed'] > 0 ? 'ATENÇÃO' : 'VERDE');
        } catch (QueryException) {
            $v['database'] = false;
            $v['state'] = 'NO-GO';
        }

        $now = now()->toIso8601String();
        $queueAge = OperationsPulse::age();
        $checks = [];
        $add = function ($component, $state, $reason, $values, $limit) use (&$checks, $now) {
            $checks[] = ['component' => $component, 'state' => $state, 'reason' => $reason, 'timestamp' => $now, 'values' => $values, 'limit' => $limit];
        };
        $add('laravel_web', 'VERDE', 'authenticated_request_completed', ['request' => true], null);
        $add('postgresql', $v['database'] ? 'VERDE' : 'NO-GO', $v['database'] ? 'select_1_succeeded' : 'database_unavailable', ['select_1' => $v['database']], 1);
        $add('diagnostic_worker', $v['worker'] ? 'VERDE' : 'NO-GO', $v['worker'] ? 'functional_loop_recent' : 'missing_or_stalled_functional_loop', WorkerHealth::evidence(), 15);
        $add('laravel_queue_worker', $queueAge !== null && $queueAge < 90 ? 'VERDE' : 'NO-GO', $queueAge === null ? 'no_completed_health_job' : ($queueAge < 90 ? 'health_job_completed' : 'health_job_stale'), ['completed_job_age_seconds' => $queueAge], 90);
        $add('task_engine', $v['stale'] === 0 && $v['worker'] ? 'VERDE' : 'NO-GO', 'active_expired_leases', ['active' => $v['active'], 'expired' => $v['stale'], 'failures_today' => $v['failed']], 0);
        $add('agents', $v['agents_total'] > 0 && $v['agents_total'] === $v['agents_online'] && ! $v['missing_poll'] && ($v['poll_age'] ?? PHP_INT_MAX) < 30 ? 'VERDE' : 'ATENÇÃO', 'heartbeat_and_poll_coverage', ['total' => $v['agents_total'], 'online' => $v['agents_online'], 'oldest_heartbeat_seconds' => $v['heartbeat_age'], 'oldest_poll_seconds' => $v['poll_age'], 'missing_heartbeat' => $v['missing_heartbeat'], 'missing_poll' => $v['missing_poll']], ['heartbeat_seconds' => 60, 'poll_seconds' => 30]);
        // No service/process access from Laravel; explicitly expose missing coverage.
        foreach (['nginx_external', 'fpm_process', 'agent_api_external', 'http_error_rate'] as $component) {
            $add($component, 'ATENÇÃO', 'independent_probe_unavailable', ['observed' => null], null);
        }
        $observed = json_decode(@file_get_contents(storage_path('framework/operations-probes.json')) ?: 'null', true);
        if (is_array($observed) && isset($observed['collected_at']) && CarbonImmutable::parse($observed['collected_at'])->gt(now()->subSeconds(90))) {
            foreach ($observed['checks'] ?? [] as $check) {
                if (! in_array($check['component'] ?? null, ['nginx_external', 'fpm_process', 'web_https', 'agent_api_external', 'http_error_rate'], true) || ! in_array($check['state'] ?? null, ['VERDE', 'ATENÇÃO', 'NO-GO'], true)) {
                    continue;
                }
                $checks = array_values(array_filter($checks, fn ($old) => $old['component'] !== $check['component']));
                $checks[] = $check;
            }
        }
        try {
            $bad = AgentTaskAttempt::join('agent_tasks as t', 't.id', '=', 'agent_task_attempts.task_id')->whereIn('agent_task_attempts.status', ['CLAIMED', 'RUNNING'])->whereColumn('agent_task_attempts.generation', '<>', 't.generation')->count();
            $add('fencing_runtime', $bad === 0 ? 'VERDE' : 'NO-GO', 'active_attempt_generation_mismatch', ['mismatches' => $bad], 0);
        } catch (QueryException) {
            $add('fencing_runtime', 'NO-GO', 'fencing_probe_failed', ['mismatches' => null], 0);
        }
        $v['checks'] = $checks;
        if (collect($checks)->contains('state', 'NO-GO')) {
            $v['state'] = 'NO-GO';
        } elseif (collect($checks)->contains('state', 'ATENÇÃO')) {
            $v['state'] = 'ATENÇÃO';
        }

        return $v;
    }
}
