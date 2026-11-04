<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\IdentityTestCase;

class AuditTimezoneTest extends IdentityTestCase
{
    public function test_sao_paulo_display_and_day_filter_preserve_original_instants(): void
    {
        $this->context()->forUser($this->admin);
        foreach (['2026-11-05T02:59:59Z', '2026-11-05T03:00:00Z'] as $index => $instant) {
            DB::table('audit_events')->insert(['tenant_id' => $this->a->id, 'user_id' => $this->admin->id, 'event_type' => 'user.updated', 'entity_type' => 'user', 'entity_id' => 900 + $index, 'created_at' => $instant]);
        }
        $this->actingAs($this->admin)->get('/audit?from=2026-11-04&until=2026-11-04')->assertOk()->assertSee('America/Sao_Paulo')->assertSee('23:59:59 -03:00')->assertViewHas('events', fn ($events) => $events->count() === 1 && $events->first()->entity_id === 900);
        $this->context()->forUser($this->admin);
        $instant = DB::table('audit_events')->where('entity_id', 900)->value('created_at');
        $this->assertSame('2026-11-05T02:59:59+00:00', CarbonImmutable::parse($instant)->utc()->toIso8601String());
    }
}
