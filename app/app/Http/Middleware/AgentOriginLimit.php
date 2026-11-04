<?php

namespace App\Http\Middleware;

use App\Agent\AgentContext;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AgentOriginLimit
{
    public function handle(Request $r, Closure $next): mixed
    {
        $key = 'agent-origin:'.hash('sha256', (string) $r->ip());
        if (RateLimiter::tooManyAttempts($key, 1200)) {
            return response()->json(['error' => 'rate_limited'], 429)->header('Retry-After', '60');
        }
        RateLimiter::hit($key, 60);
        try {
            app(TenantContext::class)->apply();
            AgentContext::clear();

            return $next($r);
        } finally {
            AgentContext::clear();
            app(TenantContext::class)->apply();
        }
    }
}
