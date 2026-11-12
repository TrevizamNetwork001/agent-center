<?php

namespace App\Http\Controllers;

use App\Http\Requests\NocActionRequest;
use App\Http\Requests\NocDeviceRequest;
use App\Models\NocDevice;
use App\Models\NocDeviceRevision;
use App\Models\NocSimulation;
use App\Noc\Analysis\Simulation;
use App\Noc\Catalog;
use App\Noc\Inventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class NocInventoryController extends Controller
{
    public function index(Request $r, Catalog $catalog)
    {
        Gate::authorize('viewAny', NocDevice::class);
        $cat = $catalog->all();
        $filters = $r->validate(['q' => 'nullable|string|max:120', 'category' => ['nullable', Rule::in(array_keys($cat['categories']))], 'manufacturer' => ['nullable', Rule::in(array_keys($cat['manufacturers']))], 'pop' => 'nullable|string|max:120', 'network_role' => 'nullable|string|max:120', 'admin_status' => ['nullable', Rule::in(['active', 'disabled', 'planned'])]]);
        $query = NocDevice::query();
        foreach (['category', 'manufacturer', 'pop', 'network_role', 'admin_status'] as $key) {
            if ($filters[$key] ?? null) {
                $query->where($key, $filters[$key]);
            }
        }
        if ($q = $filters['q'] ?? null) {
            $query->where(function ($b) use ($q) {
                $needle = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
                $b->where('name', 'ilike', $needle)->orWhere('hostname', 'ilike', $needle)->orWhere('model', 'ilike', $needle)->orWhereRaw('host(management_ip) ILIKE ?', [$needle]);
            });
        }

        return view('noc.index', ['items' => $query->orderBy('name')->paginate(20)->withQueryString(), 'catalog' => $cat, 'filters' => $filters, 'totals' => ['total' => NocDevice::count(), 'active' => NocDevice::where('admin_status', 'active')->count(), 'disabled' => NocDevice::where('admin_status', 'disabled')->count()], 'pops' => NocDevice::distinct()->orderBy('pop')->pluck('pop'), 'roles' => NocDevice::distinct()->orderBy('network_role')->pluck('network_role')]);
    }

    public function create(Catalog $catalog)
    {
        Gate::authorize('create', NocDevice::class);

        return view('noc.form', ['nocDevice' => new NocDevice, 'catalog' => $catalog->all()]);
    }

    public function store(NocDeviceRequest $r, Inventory $service)
    {
        $d = $service->save($r->user(), $r->validated());

        return redirect()->route('noc.show', $d)->with('success', 'Equipamento cadastrado. Nenhuma integração operacional foi habilitada.');
    }

    public function edit(NocDevice $nocDevice, Catalog $catalog)
    {
        Gate::authorize('update', $nocDevice);

        return view('noc.form', ['nocDevice' => $nocDevice, 'catalog' => $catalog->all()]);
    }

    public function update(NocDeviceRequest $r, NocDevice $nocDevice, Inventory $service)
    {
        $d = $service->save($r->user(), $r->validated(), $nocDevice, (int) $r->validated('revision'));

        return redirect()->route('noc.show', $d)->with('success', 'Cadastro administrativo atualizado.');
    }

    public function deactivate(NocActionRequest $r, NocDevice $nocDevice, Inventory $service)
    {
        Gate::authorize('update', $nocDevice);
        $v = $r->validated();
        $service->save($r->user(), ['admin_status' => 'disabled'], $nocDevice, (int) $v['revision']);

        return redirect()->route('noc.show', $nocDevice)->with('success', 'Equipamento desativado; histórico preservado.');
    }

    public function show(NocDevice $nocDevice, Catalog $catalog)
    {
        Gate::authorize('view', $nocDevice);

        return view('noc.show', ['nocDevice' => $nocDevice, 'catalog' => $catalog->all(), 'capabilities' => $catalog->forDevice($nocDevice), 'history' => NocDeviceRevision::where('device_id', $nocDevice->id)->orderByDesc('revision')->paginate(10, ['*'], 'history_page'), 'simulations' => NocSimulation::where('device_id', $nocDevice->id)->latest('created_at')->paginate(5, ['*'], 'simulation_page'), 'fixtures' => $catalog->fixtures(), 'analysisContracts' => $catalog->analysisForDevice($nocDevice), 'analysisFixtures' => app(Simulation::class)->fixtures($nocDevice)]);
    }

    public function capabilities(Catalog $catalog)
    {
        Gate::authorize('viewAny', NocDevice::class);

        return view('noc.capabilities', ['catalog' => $catalog->all()]);
    }

    public function simulate(NocActionRequest $r, NocDevice $nocDevice, Inventory $service)
    {
        Gate::authorize('simulate', $nocDevice);
        $v = $r->validated();
        $service->simulate($r->user(), $nocDevice, $v['fixture'], $v['request_key'], $v['parameters'] ?? [], isset($v['device_revision']) ? (int) $v['device_revision'] : null);

        return redirect()->route('noc.show', $nocDevice)->with('success', 'SIMULADO — cenário salvo apenas no histórico demonstrativo deste módulo.');
    }
}
