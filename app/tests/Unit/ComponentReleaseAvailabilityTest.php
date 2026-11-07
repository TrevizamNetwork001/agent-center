<?php

namespace Tests\Unit;

use App\Agent\ReleaseAvailability;
use PHPUnit\Framework\TestCase;

class ComponentReleaseAvailabilityTest extends TestCase
{
    public function test_component_install_and_update_do_not_require_a_core_version_bump(): void
    {
        $release = ['version' => '0.6.2', 'sequence' => 2, 'components' => [['id' => 'mtr', 'version' => '1.1.0']]];
        $this->assertCount(1, ReleaseAvailability::newer([$release], '0.6.2'));
        $this->assertCount(1, ReleaseAvailability::newer([$release], '0.6.2', [['id' => 'mtr', 'version' => '1.0.0']], 1));
        $this->assertCount(0, ReleaseAvailability::newer([$release], '0.6.2', [['id' => 'mtr', 'version' => '1.1.0']], 2));
        $this->assertCount(0, ReleaseAvailability::newer([$release], '0.6.2', [['id' => 'mtr', 'version' => '2.0.0']], 1));
    }
}
