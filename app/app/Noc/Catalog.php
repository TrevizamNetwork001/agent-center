<?php

namespace App\Noc;

use App\Models\NocDevice;
use Illuminate\Validation\ValidationException;

/** Administrative metadata only. No entry grants an operational capability. */
class Catalog
{
    public function all(): array
    {
        return json_decode(file_get_contents(resource_path('noc-catalog.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function fixtures(): array
    {
        return json_decode(file_get_contents(resource_path('noc-fixtures.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public function forDevice(NocDevice $device): array
    {
        $items = [];
        foreach ($this->all()['capabilities'] as $c) {
            if (! in_array($device->category, $c['device_categories'], true)) {
                continue;
            }
            $items[] = [...$c, 'compatibility' => in_array($device->platform, $c['platforms'], true) ? 'REQUIRES_VALIDATION' : 'NOT_DOCUMENTED', 'operational_enabled' => false,
                'device_validation' => 'Modelo '.$device->model.'; versão '.($device->os_version ?: 'não informada').'. Compatibilidade não homologada.'];
        }

        return $items;
    }

    public function fixtureFor(NocDevice $device, string $key): array
    {
        $fixture = $this->fixtures()[$key] ?? null;
        if (! $fixture) {
            throw ValidationException::withMessages(['fixture' => 'Cenário de simulação inválido.']);
        }
        if ($fixture['capability_id'] !== null && ! in_array($fixture['capability_id'], array_column($this->forDevice($device), 'id'), true)) {
            throw ValidationException::withMessages(['fixture' => 'Cenário não corresponde à categoria administrativa do equipamento.']);
        }

        // Simulations deliberately do not validate or assert platform compatibility.
        return [...$fixture, 'device_id' => $device->id, 'device_revision' => $device->revision, 'observed_at' => null, 'generated_at' => now()->toIso8601String(), 'operational_enabled' => false,
            'limitations' => ['SIMULADO: nenhum dado foi coletado deste equipamento.', 'Não utilizar como alarme, diagnóstico real ou autorização de acesso.']];
    }
}
