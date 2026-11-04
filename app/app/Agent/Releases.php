<?php

namespace App\Agent;

class Releases
{
    public function all(): array
    {
        $rows = json_decode(file_get_contents(resource_path('agent-releases.json')), true, 32, JSON_THROW_ON_ERROR);

        return array_values(array_filter($rows, fn ($r) => ($r['status'] ?? null) === 'available' && preg_match('/^[a-f0-9]{64}$/D', $r['sha256'] ?? '') && ($r['device_operations_frozen'] ?? false) === true));
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
