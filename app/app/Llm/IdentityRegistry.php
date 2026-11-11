<?php

namespace App\Llm;

use App\Models\LlmProvider;
use App\Models\User;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class IdentityRegistry
{
    // This records an operator's administrative attestation, not API verification.
    public function register(User $actor, string $providerId, array $data): array
    {
        Gate::forUser($actor)->authorize('manage-intelligence');
        $data = Validator::make($data, [
            'organization_id' => ['required', 'string', 'regex:/^org-[A-Za-z0-9_-]{1,100}$/D'],
            'project_id' => ['required', 'string', 'regex:/^proj_[A-Za-z0-9_-]{1,100}$/D'],
            'tracking_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{3,160}$/D', 'not_regex:/^sk-/'],
            'secret_event_id' => ['required', 'integer', 'min:1'],
        ])->validate();

        return DB::transaction(function () use ($actor, $providerId, $data) {
            $tenant = app(TenantContext::class)->tenantId;
            $p = LlmProvider::whereKey($providerId)->lockForUpdate()->firstOrFail(['id', 'tenant_id', 'base_url', 'config']);
            abort_unless($p->tenant_id === $tenant && rtrim($p->base_url, '/') === 'https://api.openai.com/v1', 422);
            $event = DB::table('audit_events')->where('tenant_id', $tenant)
                ->where('event_type', 'llm.secret_updated')
                ->whereRaw("metadata->>'provider_id' = ?", [$providerId])
                ->orderByDesc('id')->first(['id', 'created_at']);
            abort_unless($event && (int) $event->id === (int) $data['secret_event_id'], 409, 'O evento deve ser a atualização mais recente desta credencial.');
            $identity = [...$data, 'secret_event_id' => (int) $event->id,
                'secret_updated_at' => $event->created_at, 'provider_id' => $providerId,
                'attested_by' => $actor->id, 'attested_at' => now()->toIso8601String(),
                'verification' => 'operator_attestation'];
            $p->forceFill(['config' => [...($p->config ?? []), 'administrative_identity' => $identity]])->save();
            app(Audit::class)->record('llm.identity_registered', $actor, $tenant, 'provider', metadata: $identity);

            return $identity;
        });
    }
}
