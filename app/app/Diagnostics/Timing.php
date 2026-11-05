<?php

namespace App\Diagnostics;

use App\Models\AgentTask;
use App\Models\AgentTaskAttempt;
use App\Models\Diagnostic;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class Timing
{
    public static function milliseconds(mixed $a, mixed $b): ?float
    {
        if (! $a || ! $b) {
            return null;
        }
        try {
            $n = CarbonImmutable::parse($a)->diffInMilliseconds(CarbonImmutable::parse($b), false);

            return $n >= 0 ? round($n, 3) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function format(?float $ms): string
    {
        if ($ms === null) {
            return 'Não medido';
        }

        return number_format($ms / 1000, 2, ',', '.').' s';
    }

    public static function probe(float $ms): string
    {
        return $ms < 1000 ? number_format($ms, 2, ',', '.').' ms' : self::format($ms);
    }

    public static function attempts(string $diagnosticId): array
    {
        $tasks = AgentTask::where('diagnostic_id', $diagnosticId)->get(['id', 'step_id'])->keyBy('id');

        return AgentTaskAttempt::whereIn('task_id', $tasks->keys())->orderBy('created_at')->get(['task_id', 'generation', 'status', 'performance'])->map(fn ($a) => ['step_id' => $tasks[$a->task_id]->step_id, 'generation' => $a->generation, 'status' => $a->status, 'performance' => $a->performance ?? []])->all();
    }

    public static function summary(Diagnostic $d, Collection $steps): array
    {
        $ms = 0;
        $count = 0;
        $expected = 0;
        foreach ($steps as $step) {
            if ($step->result !== null) {
                $expected++;
            }
            $n = $step->result['data']['duration_ms'] ?? $step->result['data']['total_duration_ms'] ?? null;
            if (is_numeric($n) && is_finite((float) $n) && $n >= 0) {
                $ms += (float) $n;
                $count++;
            }
        }
        $p = $d->performance ?? [];
        $total = self::milliseconds($p['requested_at'] ?? $d->created_at ?? $d->started_at, $p['completed_at'] ?? $d->finished_at ?? now());

        return ['total_ms' => $total, 'probe_ms' => $count ? round($ms, 3) : null, 'partial' => $expected > $count, 'measured' => $count, 'queue_ms' => $steps->sum(fn ($s) => self::milliseconds($s->performance['enqueued_at'] ?? null, $s->performance['claimed_at'] ?? null) ?? 0), 'instrumented' => $p !== []];
    }
}
