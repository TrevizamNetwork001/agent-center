<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeviceReadOnlyLabRequest;
use App\Models\NocDevice;
use App\Noc\Inventory;
use App\Noc\ReadOnlyLab\Failure;
use Illuminate\Validation\ValidationException;

class DeviceReadOnlyLabController extends Controller
{
    public function store(DeviceReadOnlyLabRequest $request, NocDevice $nocDevice, Inventory $inventory)
    {
        $v = $request->validated();
        try {
            $inventory->simulate($request->user(), $nocDevice, (($v['protocol'] ?? '') === 'routeros7-v1' ? 'ssh-ros7-' : 'ssh-').$v['operation'], $v['request_key'], $v['parameters'] ?? [], $v['device_revision']);
        } catch (Failure $e) {
            throw ValidationException::withMessages(['analysis' => $e->getMessage()]);
        }

        return redirect()->route('noc.show', $nocDevice)->with('success', 'SIMULADO — consulta ao servidor SSH fictício concluída. Nenhum equipamento real foi acessado.');
    }
}
