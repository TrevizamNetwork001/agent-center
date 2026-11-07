<?php

namespace App\Diagnostics;

class WorkerHealth
{
    public static function path(): string
    {
        return storage_path('framework/diagnostic-worker.json');
    }

    public static function write(int $iterations, int $progress, ?float $workMs = null, ?string $startedAt = null): void
    {
        $data = ['work_ms' => $workMs, 'started_at' => $startedAt, 'finished_at' => now()->utc()->toISOString(), 'scheduled_wait_ms' => 2000, 'boot_id' => trim(file_get_contents('/proc/sys/kernel/random/boot_id')), 'pid' => getmypid(), 'loop_ns' => hrtime(true), 'iterations' => $iterations, 'progress' => $progress];
        $tmp = self::path().'.'.getmypid().'.tmp';
        file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($tmp, 0600);
        rename($tmp, self::path());
    }

    public static function evidence(): array
    {
        $v = json_decode(@file_get_contents(self::path()) ?: 'null', true);
        $gap = is_array($v) && isset($v['loop_ns']) ? (hrtime(true) - $v['loop_ns']) / 1e9 : null;

        return ['work_ms' => $v['work_ms'] ?? null, 'started_at' => $v['started_at'] ?? null, 'finished_at' => $v['finished_at'] ?? null, 'scheduled_wait_ms' => $v['scheduled_wait_ms'] ?? null, 'loop_age_seconds' => $gap !== null && $gap >= 0 ? $gap : null, 'iterations' => is_int($v['iterations'] ?? null) ? $v['iterations'] : null, 'progress' => is_int($v['progress'] ?? null) ? $v['progress'] : null, 'same_boot' => is_array($v) && ($v['boot_id'] ?? '') === trim(file_get_contents('/proc/sys/kernel/random/boot_id'))];
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
