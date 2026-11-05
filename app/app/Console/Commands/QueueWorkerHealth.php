<?php

namespace App\Console\Commands;

use App\Jobs\OperationsPulse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class QueueWorkerHealth extends Command
{
    protected $signature = 'agent-center:queue-health';

    protected $description = 'Verify completed Laravel health job and PostgreSQL';

    public function handle(): int
    {
        try {
            DB::select('SELECT 1');
            $age = OperationsPulse::age();

            return $age !== null && $age < 90 ? 0 : 1;
        } catch (\Throwable) {
            return 1;
        }
    }
}
