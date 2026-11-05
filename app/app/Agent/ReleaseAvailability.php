<?php

namespace App\Agent;

class ReleaseAvailability
{
    public static function newer(array $releases, string $installed): ?array
    {
        if (! preg_match('/^\d+\.\d+\.\d+$/D', $installed)) {
            return null;
        }

        $newer = array_filter($releases, fn ($release) => preg_match('/^\d+\.\d+\.\d+$/D', $release['version'] ?? '') && version_compare($release['version'], $installed, '>'));
        usort($newer, fn ($a, $b) => version_compare($b['version'], $a['version']));

        return array_values($newer);
    }
}
