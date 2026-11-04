<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_live_does_not_depend_on_database(): void
    {
        DB::shouldReceive('select')->never();
        $this->getJson('/health/live')->assertOk()->assertExactJson(['status' => 'alive']);
    }

    public function test_ready_connects_to_real_postgresql(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->getJson('/health/ready')->assertOk()->assertExactJson(['status' => 'ready']);
    }

    public function test_database_failure_is_generic_and_not_cached(): void
    {
        DB::shouldReceive('select')->once()->with('SELECT 1')
            ->andThrow(new RuntimeException('sensitive-connection-detail'));
        $response = $this->getJson('/health/ready');
        $response->assertStatus(503)->assertExactJson(['status' => 'not_ready']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertDontSee('sensitive-connection-detail');
    }
}
