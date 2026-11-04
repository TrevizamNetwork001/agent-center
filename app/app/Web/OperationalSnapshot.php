<?php

namespace App\Web;

use App\Diagnostics\WorkerHealth;
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

        return $v;
    }
}
