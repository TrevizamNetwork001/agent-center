<?php

namespace App\Noc\Analysis;

use App\Conversation\DiagnosticExperience;
use App\Conversation\Identity;
use App\Models\Conversation;
use App\Models\NocDevice;
use App\Models\NocSimulation;
use App\Noc\Inventory;
use App\Noc\Planning;
use Illuminate\Validation\ValidationException;

/** An explicit LAB action through the existing conversation and inventory transaction. */
class ConversationSimulation
{
    public function request(string $text): bool
    {
        return (bool) preg_match('/^simule\s+[a-z-]+\s+no\s+.{1,120}[.!]?$/iu', trim($text));
    }

    public function run(Conversation $c, Identity $i, string $key, string $text): array
    {
        if (! app(Contracts::class)->labEnabled()) {
            return ['text' => 'A simulação especializada está disponível somente no laboratório autorizado. A análise real do equipamento não está habilitada. Nenhum teste foi executado.', 'metadata' => ['simulation' => false, 'operational_enabled' => false]];
        }
        preg_match('/^simule\s+([a-z-]+)\s+no\s+(.+?)[.!]?$/iu', trim($text), $m);
        $ids = app(Planning::class)->deviceIds($m[2]);
        if (count($ids) !== 1) {
            return ['text' => 'Informe o nome ou hostname exato de um único equipamento do inventário para a simulação de laboratório. Nenhum teste foi executado.', 'metadata' => ['simulation' => false]];
        }
        $d = NocDevice::findOrFail($ids[0]);
        $platform = app(Contracts::class)->platform($d);
        $prefix = match ($platform) {
            'ne8000-vrp8' => 'da-ne8000-','ccr-routeros7' => 'da-ros7-',default => ''
        };
        $fixture = $prefix.strtolower($m[1]);
        $f = app(Simulation::class)->fixtures($d)[$fixture] ?? null;
        if (! $f) {
            return ['text' => 'Cenário ou plataforma não disponível. Consulte as versões compatíveis e os nomes dos cenários no painel autenticado. Nenhum teste foi executado.', 'metadata' => ['simulation' => false]];
        }
        try {
            $params = $f['contract_id'] === 'DEVICE_ROUTE_LOOKUP_READ' ? ['destination' => '8.8.8.8'] : [];
            $sim = app(Inventory::class)->simulate($i->user, $d, $fixture, $key, $params, $d->revision);
        } catch (ValidationException) {
            return ['text' => 'Os dados deste cenário não são compatíveis com o cadastro. Nenhum teste foi executado.', 'metadata' => ['simulation' => false]];
        }
        $c->context_json = [...$c->context_json, 'last_device_simulation_id' => $sim->id];

        return app(DiagnosticExperience::class)->simulationResult($c, $sim, $i);
    }

    public function explain(Conversation $c, Identity $i): array
    {
        $sim = NocSimulation::whereKey($c->context_json['last_device_simulation_id'] ?? null)->where('actor_id', $i->user->id)->first();
        if (! $sim || ! app(Contracts::class)->labEnabled()) {
            return ['text' => 'Não há simulação especializada acessível neste laboratório para explicar. Nenhum teste foi executado.', 'metadata' => []];
        }

        return app(DiagnosticExperience::class)->simulationResult($c, $sim, $i, true);
    }
}
