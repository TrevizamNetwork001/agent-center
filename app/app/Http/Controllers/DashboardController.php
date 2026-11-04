<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TenantContext $context)
    {
        $events = AuditEvent::query();
        if (in_array($request->user()->role, [Role::Operator, Role::Viewer], true)) {
            $events->where('user_id', $request->user()->id);
        }

        return view('dashboard', ['tenant' => $context->tenantId ? Tenant::findOrFail($context->tenantId) : null, 'activeUsers' => $context->tenantId ? User::where('status', 'active')->count() : null, 'events' => $events->latest('id')->limit(10)->get()]);
    }
}
