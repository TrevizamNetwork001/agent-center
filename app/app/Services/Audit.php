<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Audit
{
    public function record(string $event, ?User $actor = null, ?int $tenantId = null, ?string $entity = null, ?int $entityId = null, array $metadata = []): void
    {
        if (! in_array($event, ['auth.login_success', 'auth.login_failed', 'auth.logout', 'auth.password_changed', 'user.created', 'user.updated', 'user.disabled', 'tenant.created', 'tenant.updated'], true)) {
            throw new InvalidArgumentException('Evento não permitido.');
        }
        $safe = [];
        if (in_array($metadata['reason'] ?? null, ['invalid_credentials', 'throttled'], true)) {
            $safe['reason'] = $metadata['reason'];
        }
        if (isset($metadata['fields']) && is_array($metadata['fields'])) {
            $safe['fields'] = array_values(array_intersect(array_filter($metadata['fields'], 'is_string'), ['name', 'email', 'slug', 'role', 'status']));
        }
        if (in_array($metadata['role'] ?? null, ['platform_admin', 'tenant_admin', 'operator', 'viewer'], true)) {
            $safe['role'] = $metadata['role'];
        }
        if (in_array($metadata['status'] ?? null, ['active', 'disabled'], true)) {
            $safe['status'] = $metadata['status'];
        }
        // Dados de requisição, email, senha, hash, cookie, headers e tokens nunca entram aqui.
        DB::table('audit_events')->insert(['tenant_id' => $tenantId, 'user_id' => $actor?->id, 'event_type' => $event, 'entity_type' => $entity, 'entity_id' => $entityId, 'metadata' => $safe ? json_encode($safe, JSON_THROW_ON_ERROR) : null, 'ip_address' => null, 'created_at' => now()]);
    }
}
