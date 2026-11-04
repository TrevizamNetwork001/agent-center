<?php

namespace App\Http\Controllers;

use App\Diagnostics\WorkerHealth;
use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\AgentTaskAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OperationsController extends Controller
{
    public function __invoke()
    {
        Gate::authorize('manage-diagnostic-catalog');
        DB::select('SELECT 1');

        return view('diagnostics.operations', ['worker' => WorkerHealth::healthy(), 'agents' => Agent::orderBy('name')->get(), 'active' => AgentTask::whereIn('status', ['CLAIMED', 'RUNNING'])->count(), 'stale' => AgentTaskAttempt::whereIn('status', ['CLAIMED', 'RUNNING'])->whereRaw('lease_expires_at <= clock_timestamp()')->count(), 'failed' => AgentTaskAttempt::whereIn('status', ['FAILED', 'EXPIRED'])->count()]);
    }
}
