<?php

namespace App\Agent;

class ReleaseAvailability
{
    public static function newer(array $releases, string $installed, array $components = [], int $sequence = 0): ?array
    {
        if (! preg_match('/^\d+\.\d+\.\d+$/D', $installed)) {
            return null;
        }

        $bootstrapDigests = array_column(array_filter($releases, fn ($release) => isset($release['lifecycle']) && in_array($release['runtime_package_protocol'] ?? null, [2, 3], true)), 'bootstrap_sha256');
        $newer = array_filter($releases, function ($release) use ($installed, $components, $sequence, $bootstrapDigests) {
            if (($release['bootstrap_only'] ?? false) || in_array($release['sha256'] ?? null, $bootstrapDigests, true)) {
                return false;
            }
            if (! preg_match('/^\d+\.\d+\.\d+$/D', $release['version'] ?? '')) {
                return false;
            }
            if (version_compare($release['version'], $installed, '>')) {
                return true;
            }
            if ($release['version'] !== $installed || ($release['sequence'] ?? 0) <= $sequence) {
                return false;
            }
            if (isset($release['lifecycle'], $release['bootstrap_sha256']) && in_array($release['runtime_package_protocol'] ?? null, [2, 3], true)) {
                return true;
            }
            foreach ($release['components'] ?? [] as $required) {
                $present = collect($components)->firstWhere('id', $required['id']);
                if (! $present || version_compare($required['version'], $present['version'], '>')) {
                    return true;
                }
            }

            return false;
        });
        usort($newer, fn ($a, $b) => version_compare($b['version'], $a['version']) ?: (($b['sequence'] ?? 0) <=> ($a['sequence'] ?? 0)));

        return array_values($newer);
    }
}
