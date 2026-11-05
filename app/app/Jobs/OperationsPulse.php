<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class OperationsPulse implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function handle(): void
    {
        DB::select('SELECT 1');
        $path = storage_path('framework/queue-progress.json');
        $tmp = $path.'.'.getmypid().'.tmp';
        file_put_contents($tmp, json_encode(['boot_id' => trim(file_get_contents('/proc/sys/kernel/random/boot_id')), 'completed_ns' => hrtime(true), 'completed_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($tmp, 0600);
        rename($tmp, $path);
    }

    public static function age(): ?float
    {
        $v = json_decode(@file_get_contents(storage_path('framework/queue-progress.json')) ?: 'null', true);
        if (! is_array($v) || ($v['boot_id'] ?? '') !== trim(file_get_contents('/proc/sys/kernel/random/boot_id'))) {
            return null;
        }
        $age = (hrtime(true) - ($v['completed_ns'] ?? 0)) / 1e9;

        return $age >= 0 ? $age : null;
    }
}
