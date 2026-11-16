<?php

namespace App\Agent;

use App\Models\AgentRelease;
use App\Models\AgentReleaseQuarantine;

class Releases
{
    public function all(): array
    {
        $rows = json_decode(file_get_contents(resource_path('agent-releases.json')), true, 32, JSON_THROW_ON_ERROR);

        $quarantined = AgentReleaseQuarantine::pluck('sha256')->all();
        // Keep transition-only packages hidden even when their target is quarantined.
        $bootstrapDigests = AgentRelease::get(['manifest'])->map(fn ($release) => json_decode($release->manifest, true)['bootstrap_sha256'] ?? null)->filter()->all();
        $published = AgentRelease::whereNotIn('sha256', $quarantined)->orderByDesc('sequence')->get()->map(fn ($release) => $release->descriptor())->all();
        $published = array_map(fn ($release) => in_array($release['sha256'], $bootstrapDigests, true) ? [...$release, 'bootstrap_only' => true] : $release, $published);
        $digests = array_column($published, 'sha256');

        return [...$published, ...array_values(array_filter($rows, fn ($r) => ($r['status'] ?? null) === 'available' && preg_match('/^[a-f0-9]{64}$/D', $r['sha256'] ?? '') && ($r['device_operations_frozen'] ?? false) === true && ! in_array($r['sha256'], $digests, true) && ! in_array($r['sha256'], $quarantined, true)))];
    }

    public function find(string $sha): array
    {
        foreach ($this->all() as $r) {
            if (hash_equals($r['sha256'], $sha)) {
                return $r;
            }
        } abort(422, 'Release indisponível.');
    }
}
