<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ConversationScheduleTest extends TestCase
{
    public function test_idle_and_telegram_recovery_are_scheduled_every_minute_with_overlap_protection(): void
    {
        config(['telegram.tenants' => ['1'], 'conversation.idle_enabled' => true, 'conversation.idle_tenants' => [1]]);
        $schedule = new Schedule('Europe/Berlin');
        app()->instance(Schedule::class, $schedule);
        require base_path('routes/console.php');
        $events = collect($schedule->events());
        foreach (['telegram:drain 1', 'conversations:close-idle 1'] as $command) {
            $event = $events->filter(fn ($e) => str_contains($e->command ?? '', $command))->sole();
            $this->assertSame('* * * * *', $event->expression);
            $this->assertTrue($event->withoutOverlapping);
        }
    }
}
