<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Diagnostic;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Web\OperationalSnapshot;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $context, OperationalSnapshot $snapshot)
    {
        $events = AuditEvent::query();
        if (in_array($request->user()->role, [Role::Operator, Role::Viewer], true)) {
            $events->where('user_id', $request->user()->id);
        }
        $data = ['tenant' => $context->tenantId ? Tenant::findOrFail($context->tenantId) : null, 'activeUsers' => $context->tenantId ? User::where('status', 'active')->count() : null, 'events' => $events->with(['actor', 'tenant'])->latest('id')->limit(5)->get(), 'recent' => Diagnostic::with(['profile', 'agent', 'conclusion'])->latest()->limit(6)->get(), 'health' => $context->tenantId ? $snapshot->get() : null];
        if ($request->boolean('fragment')) {
            return response()->view('dashboard.content', $data)->header('X-AC-Fragment', 'dashboard-panel');
        }

        return view('dashboard', $data);
    }
}
