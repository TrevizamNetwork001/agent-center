<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContextRequest;
use App\Http\Requests\TenantRequest;
use App\Models\Tenant;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TenantController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', Tenant::class);

        return view('tenants.index', ['tenants' => Tenant::orderBy('name')->paginate(25)]);
    }

    public function store(TenantRequest $request, Audit $audit)
    {
        DB::transaction(function () use ($request, $audit) {
            $tenant = Tenant::create($request->validated());
            $audit->record('tenant.created', $request->user(), $tenant->id, 'tenant', $tenant->id);
        });

        return redirect()->route('tenants.index')->with('success', 'Tenant criado.');
    }

    public function edit(Tenant $tenant)
    {
        Gate::authorize('update', $tenant);

        return view('tenants.edit', compact('tenant'));
    }

    public function update(TenantRequest $request, Tenant $tenant, Audit $audit)
    {
        DB::transaction(function () use ($request, $tenant, $audit) {
            $tenant->fill($request->validated());
            $fields = array_keys($tenant->getDirty());
            $tenant->save();
            if ($tenant->status === 'disabled') {
                DB::table('sessions')->whereIn('user_id', DB::table('users')->select('id')->where('tenant_id', $tenant->id))->delete();
            }
            $audit->record('tenant.updated', $request->user(), $tenant->id, 'tenant', $tenant->id, ['fields' => $fields]);
        });

        return redirect()->route('tenants.index')->with('success', 'Tenant atualizado.');
    }

    public function select(ContextRequest $request)
    {
        $request->session()->put('active_tenant_id', $request->validated('tenant_id'));

        return redirect()->route('dashboard');
    }
}
