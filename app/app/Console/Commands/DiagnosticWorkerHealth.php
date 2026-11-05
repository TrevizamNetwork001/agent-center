<?php

namespace App\Console\Commands;

use App\Diagnostics\WorkerHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiagnosticWorkerHealth extends Command
{
    protected $signature = 'agent-center:worker-health';

    protected $description = 'Verify diagnostic loop liveness and PostgreSQL';

    public function handle(): int
    {
        try {
            DB::select('SELECT 1');

            return WorkerHealth::healthy(true) ? 0 : 1;
        } catch (\Throwable) {
            return 1;
        }
    }
}
