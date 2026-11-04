<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): mixed
    {
        $context = app(TenantContext::class);
        try {
            $context->apply(userId: (int) $request->session()->get(Auth::guard('web')->getName()) ?: null);
            if ($user = Auth::user()) {
                $selected = $request->session()->get('active_tenant_id');
                $context->forUser($user, $selected ? (int) $selected : null);
                if ($user->status !== 'active' || ($user->tenant_id && ! Tenant::whereKey($user->tenant_id)->where('status', 'active')->exists())) {
                    Auth::logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return redirect()->route('login')->withErrors(['email' => 'Acesso indisponível.']);
                }
                if ($context->platform && $context->tenantId && ! Tenant::whereKey($context->tenantId)->where('status', 'active')->exists()) {
                    $request->session()->forget('active_tenant_id');
                    $context->forUser($user);
                }
            }

            $response = $next($request);
            $response->headers->set('Cache-Control', 'no-store, private');
            if ($response->headers->has('X-AC-Fragment')) {
                $response->headers->set('X-AC-Tenant', (string) ($context->tenantId ?? 'platform'));
            }

            return $response;
        } finally {
            $context->apply();
        }
    }
}
