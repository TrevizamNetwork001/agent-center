<?php

namespace App\Agent;

class ReleaseAvailability
{
    public static function newer(array $releases, string $installed, array $components = [], int $sequence = 0): ?array
    {
        if (! preg_match('/^\d+\.\d+\.\d+$/D', $installed)) {
            return null;
        }

        $newer = array_filter($releases, function ($release) use ($installed, $components, $sequence) {
            if (! preg_match('/^\d+\.\d+\.\d+$/D', $release['version'] ?? '')) {
                return false;
            }
            if (version_compare($release['version'], $installed, '>')) {
                return true;
            }
            if ($release['version'] !== $installed || ($release['sequence'] ?? 0) <= $sequence) {
                return false;
            }
            foreach ($release['components'] ?? [] as $required) {
                $present = collect($components)->firstWhere('id', $required['id']);
                if (! $present || version_compare($required['version'], $present['version'], '>')) {
                    return true;
                }
            }

            return false;
        });
        usort($newer, fn ($a, $b) => version_compare($b['version'], $a['version']));

        return array_values($newer);
    }
}
