<?php

namespace App\Http\Middleware;

use App\Agent\AgentContext;
use App\Agent\ManagedSettings;
use App\Models\Agent;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuthenticateAgent
{
    public function handle(Request $r, Closure $next): mixed
    {
        $c = app(TenantContext::class);
        try {
            $c->apply();
            AgentContext::clear();
            $token = $r->bearerToken();
            abort_unless(is_string($token) && preg_match('/^([0-9a-f-]{36})\.([a-f0-9]{64})$/D', $token, $m), 401);
            abort_unless(Str::isUuid($m[1]), 401);
            $hash = hash('sha256', $token);
            DB::select("SELECT set_config('app.agent_id',?,false),set_config('app.agent_token_hash',?,false)", [$m[1], $hash]);
            $agent = Agent::withoutGlobalScopes()->whereKey($m[1])->first();
            abort_unless($agent && hash_equals($agent->token_hash, $hash), 401);
            $c->apply(tenantId: $agent->tenant_id);
            AgentContext::clear();
            abort_if($agent->revoked_at || $agent->status !== 'active', 403);
            abort_unless(Tenant::whereKey($agent->tenant_id)->where('status', 'active')->exists(), 403);
            if (! app(ManagedSettings::class)->allow($agent, $r->ip())) {
                return response()->json(['error' => 'origin_not_allowed', 'message' => 'IP de origem não autorizado para este Agent.'], 403)->header('Cache-Control', 'no-store');
            }
            $agent->refresh();
            $r->attributes->set('agent', $agent);
            $response = $next($r);
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        } finally {
            AgentContext::clear();
            $c->apply();
        }
    }
}
