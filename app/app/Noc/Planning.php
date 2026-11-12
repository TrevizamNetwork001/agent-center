<?php

namespace App\Noc;

use App\Conversation\IntentDetector;
use App\Diagnostics\OperationalIntent;
use App\Models\NocDevice;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class Planning
{
    public const UNAVAILABLE = 'Entendi qual verificação você precisa, mas essa consulta ao equipamento ainda não está habilitada no Agent Center.';

    public function prepare(array $request, string $body, User $user): array
    {
        Gate::forUser($user)->authorize('viewAny', NocDevice::class);
        $intent = OperationalIntent::from($request['intent']);
        $cap = match ($intent) {
            OperationalIntent::DeviceBgp => 'routing.bgp.sessions',OperationalIntent::DeviceRouting => 'routing.table',OperationalIntent::DeviceOptical => 'ftth.optical.signal',OperationalIntent::DeviceInterfaces => 'system.interfaces',OperationalIntent::DevicePppoe => 'bng.sessions',OperationalIntent::DeviceOnu => 'ftth.onu.status',default => null
        };
        abort_unless($cap !== null, 422);
        // Tenant-scoped exact names/hostnames only, never resolve DNS or guess equipment ownership.
        $normalized = Str::lower($body);
        $matches = [];
        foreach (NocDevice::where(function ($q) use ($normalized) {
            $q->whereRaw('strpos(?,lower(name))>0', [$normalized])->orWhereRaw('strpos(?,lower(hostname))>0', [$normalized]);
        })->orderBy('id')->limit(25)->get() as $d) {
            foreach ([$d->name, $d->hostname] as $name) {
                if (preg_match('/(?<![\pL\pN_.-])'.preg_quote(Str::lower($name), '/').'(?![\pL\pN_-]|\.[\pL\pN_-])/u', $normalized)) {
                    $matches[] = $d->id;
                    break;
                }
            }
            if (count($matches) >= 2) {
                break;
            }
        }
        $ambiguous = (bool) $request['ambiguous'];

        return ['schema_version' => 1, 'intent' => $intent->value, 'capability_id' => $cap, 'state' => $ambiguous ? 'NEEDS_CLARIFICATION' : 'UNAVAILABLE', 'authorization' => 'UNKNOWN', 'operational_enabled' => false, 'device_id' => count($matches) === 1 ? $matches[0] : null, 'device_selection' => count($matches) > 1 ? 'AMBIGUOUS' : (count($matches) === 1 ? 'EXACT' : 'NOT_SELECTED'),
            'parameters' => ['destination' => app(IntentDetector::class)->target($body)], 'planned_steps' => [], 'executed_steps' => [], 'classification' => null, 'objective_coverage' => 'NOT_EXECUTED', 'simulation' => false,
            'reply' => $ambiguous ? 'Você quer consultar a tabela de roteamento do equipamento ou analisar o caminho a partir do Agent? Nenhum teste foi executado.' : self::UNAVAILABLE.' Nenhum teste foi executado.'];
    }
}
