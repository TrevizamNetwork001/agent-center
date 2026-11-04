<?php

namespace App\Diagnostics;

class WorkerHealth
{
    public static function path(): string
    {
        return storage_path('framework/diagnostic-worker.json');
    }

    public static function write(int $iterations, int $progress): void
    {
        $data = ['boot_id' => trim(file_get_contents('/proc/sys/kernel/random/boot_id')), 'pid' => getmypid(), 'loop_ns' => hrtime(true), 'iterations' => $iterations, 'progress' => $progress];
        $tmp = self::path().'.'.getmypid().'.tmp';
        file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($tmp, 0600);
        rename($tmp, self::path());
    }

    public static function healthy(bool $local = false): bool
    {
        $v = json_decode(@file_get_contents(self::path()) ?: 'null', true);
        if (! is_array($v)) {
            return false;
        }
        $gap = hrtime(true) - ($v['loop_ns'] ?? 0);

        return ($v['boot_id'] ?? '') === trim(file_get_contents('/proc/sys/kernel/random/boot_id')) && $gap >= 0 && $gap < 15_000_000_000 && ($v['iterations'] ?? 0) > 0 && (! $local || posix_kill((int) ($v['pid'] ?? 0), 0));
    }
}
