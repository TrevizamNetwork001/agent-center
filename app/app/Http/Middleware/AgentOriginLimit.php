<?php

namespace App\Http\Middleware;

use App\Agent\AgentContext;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class AgentOriginLimit
{
    public function handle(Request $r, Closure $next): mixed
    {
        $started = hrtime(true);
        $received = now()->utc()->toISOString();
        $response = null;
        $key = 'agent-origin:'.hash('sha256', (string) $r->ip());
        if (RateLimiter::tooManyAttempts($key, 1200)) {
            return response()->json(['error' => 'rate_limited'], 429)->header('Retry-After', '60');
        }
        RateLimiter::hit($key, 60);
        try {
            app(TenantContext::class)->apply();
            AgentContext::clear();

            $response = $next($r);

            return $response;
        } finally {
            $name = $r->route()?->getName();
            $agent = $r->attributes->get('agent');
            if ($agent && in_array($name, ['agent.claim', 'agent.start', 'agent.result', 'agent.renew'], true)) {
                try {
                    Log::channel('diagnostic-performance')->info('agent_protocol_timing', [
                        'phase' => $name, 'agent_id' => $agent->id, 'tenant_id' => $agent->tenant_id,
                        'received_at' => $received, 'response_prepared_at' => now()->utc()->toISOString(),
                        'handling_ms' => round((hrtime(true) - $started) / 1e6, 3), 'status' => $response?->getStatusCode(),
                    ]);
                } catch (\Throwable) {
                    error_log('Diagnostic timing log unavailable');
                }
            }

            AgentContext::clear();
            app(TenantContext::class)->apply();
        }
    }
}
