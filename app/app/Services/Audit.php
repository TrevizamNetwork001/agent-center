<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Audit
{
    public function record(string $event, ?User $actor = null, ?int $tenantId = null, ?string $entity = null, ?int $entityId = null, array $metadata = []): void
    {
        if (! in_array($event, ['conversation.transitioned', 'conversation.rollout_updated', 'auth.login_success', 'auth.login_failed', 'auth.logout', 'auth.password_changed', 'user.created', 'user.updated', 'user.disabled', 'tenant.created', 'tenant.updated', 'agent.enrollment_created', 'agent.enrolled', 'agent.capabilities_updated', 'agent.revoked', 'diagnostic.created', 'diagnostic.started', 'diagnostic.cancel_requested', 'diagnostic.cancelled', 'diagnostic.completed', 'diagnostic.failed', 'profile.created', 'profile.updated', 'skill.enabled', 'skill.disabled', 'knowledge.created', 'knowledge.version_created', 'knowledge.published', 'knowledge.superseded', 'knowledge.rollback_created', 'knowledge.archived', 'prompt.created', 'prompt.version_created', 'prompt.published', 'prompt.superseded', 'prompt.rollback_created', 'prompt.archived', 'llm.provider_created', 'llm.provider_updated', 'llm.secret_updated', 'llm.model_created', 'llm.binding_updated'], true)) {
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
        foreach (['conversation_id', 'agent_id', 'enrollment_id', 'diagnostic_id', 'profile_id', 'skill_id', 'knowledge_id', 'version_id', 'prompt_id', 'provider_id', 'model_id', 'binding_id'] as $key) {
            if (isset($metadata[$key]) && is_string($metadata[$key]) && Str::isUuid($metadata[$key])) {
                $safe[$key] = $metadata[$key];
            }
        }
        // Dados de requisição, email, senha, hash, cookie, headers e tokens nunca entram aqui.
        DB::table('audit_events')->insert(['tenant_id' => $tenantId, 'user_id' => $actor?->id, 'event_type' => $event, 'entity_type' => $entity, 'entity_id' => $entityId, 'metadata' => $safe ? json_encode($safe, JSON_THROW_ON_ERROR) : null, 'ip_address' => null, 'created_at' => now()]);
    }
}
