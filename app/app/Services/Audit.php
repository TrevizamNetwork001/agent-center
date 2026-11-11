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
        if ($event !== 'llm.identity_registered' && ! in_array($event, ['telegram.configured', 'telegram.webhook_changed', 'telegram.link_code_created', 'telegram.link_claimed', 'telegram.link_verified', 'telegram.link_revoked', 'telegram.tested', 'telegram.processing_failed', 'telegram.delivery_failed', 'telegram.delivery_retry', 'conversation.language_rendered', 'rag.reindexed', 'rag.searched', 'rag.answered', 'agent.lifecycle_bootstrap_authorized', 'agent.lifecycle_bootstrap_completed', 'agent.permission_requested', 'agent.release_quarantined', 'agent.recovery_requested', 'agent.recovery_verified', 'agent.maintenance_saved', 'agent.maintenance_checked', 'agent.maintenance_removed', 'agent.release_published', 'agent.settings_updated', 'backup.settings_updated', 'backup.requested', 'agent.created', 'agent.deleted', 'agent.enrollment_requested', 'agent.enrollment_approved', 'agent.update_requested', 'agent.update_authorized', 'agent.update_approved', 'agent.update_started', 'agent.update_completed', 'agent.update_failed', 'agent.rollback_requested', 'agent.rollback_completed', 'agent.rollback_failed', 'conversation.transitioned', 'conversation.rollout_updated', 'auth.login_success', 'auth.login_failed', 'auth.logout', 'auth.password_changed', 'user.created', 'user.updated', 'user.disabled', 'tenant.created', 'tenant.updated', 'agent.enrollment_created', 'agent.enrolled', 'agent.capabilities_updated', 'agent.revoked', 'diagnostic.created', 'diagnostic.started', 'diagnostic.cancel_requested', 'diagnostic.cancelled', 'diagnostic.completed', 'diagnostic.failed', 'profile.created', 'profile.updated', 'skill.enabled', 'skill.schema_updated', 'skill.disabled', 'knowledge.created', 'knowledge.version_created', 'knowledge.published', 'knowledge.superseded', 'knowledge.rollback_created', 'knowledge.archived', 'prompt.created', 'prompt.version_created', 'prompt.published', 'prompt.superseded', 'prompt.rollback_created', 'prompt.archived', 'llm.provider_created', 'llm.provider_updated', 'llm.secret_updated', 'llm.model_created', 'llm.binding_updated'], true)) {
            throw new InvalidArgumentException('Evento não permitido.');
        }
        $safe = [];
        if ($event === 'llm.identity_registered') {
            foreach (['organization_id' => '/^org-[A-Za-z0-9_-]{1,100}$/D', 'project_id' => '/^proj_[A-Za-z0-9_-]{1,100}$/D', 'tracking_id' => '/^(?!sk-)[A-Za-z0-9_-]{3,160}$/D'] as $field => $pattern) {
                if (is_string($metadata[$field] ?? null) && preg_match($pattern, $metadata[$field])) {
                    $safe[$field] = $metadata[$field];
                }
            }
            if (is_int($metadata['secret_event_id'] ?? null) && $metadata['secret_event_id'] > 0) {
                $safe['secret_event_id'] = $metadata['secret_event_id'];
            }
        }
        if ($event === 'conversation.language_rendered') {
            if (in_array($metadata['language_status'] ?? null, ['ASSISTED', 'FALLBACK'], true)) {
                $safe['language_status'] = $metadata['language_status'];
            }
            foreach (['input_tokens', 'output_tokens', 'total_tokens', 'prompt_tokens', 'completion_tokens'] as $field) {
                if (is_int($metadata['usage'][$field] ?? null) && $metadata['usage'][$field] >= 0) {
                    $safe['usage'][$field] = $metadata['usage'][$field];
                }
            }
            if (is_string($metadata['request_id'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $metadata['request_id'])) {
                $safe['request_id'] = $metadata['request_id'];
            }
        }
        if (str_starts_with($event, 'rag.')) {
            foreach (['duration_ms', 'sources', 'documents'] as $key) {
                if (isset($metadata[$key]) && is_int($metadata[$key])) {
                    $safe[$key] = max(0, min(3600000, $metadata[$key]));
                }
            }
            if (in_array($metadata['rag_status'] ?? null, ['answered', 'no_match', 'index_incomplete', 'not_configured', 'source_revoked', 'rate_limited', 'invalid_payload', 'permission_denied', 'model_or_endpoint_unavailable', 'timeout', 'invalid_output', 'invalid_response', 'invalid_json', 'input_too_large', 'secret_unavailable', 'unauthorized', 'provider_unavailable', 'invalid_sources', 'insufficient_evidence'], true)) {
                $safe['rag_status'] = $metadata['rag_status'];
            }
        }
        if ($event === 'agent.maintenance_saved' && in_array($metadata['access_mode'] ?? null, ['temporary', 'until_revoked'], true)) {
            $safe['access_mode'] = $metadata['access_mode'];
        }
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
        foreach (['release_id', 'operation_id', 'conversation_id', 'agent_id', 'enrollment_id', 'diagnostic_id', 'profile_id', 'skill_id', 'knowledge_id', 'version_id', 'prompt_id', 'provider_id', 'model_id', 'binding_id'] as $key) {
            if (isset($metadata[$key]) && is_string($metadata[$key]) && Str::isUuid($metadata[$key])) {
                $safe[$key] = $metadata[$key];
            }
        }
        // Dados de requisição, email, senha, hash, cookie, headers e tokens nunca entram aqui.
        DB::table('audit_events')->insert(['tenant_id' => $tenantId, 'user_id' => $actor?->id, 'event_type' => $event, 'entity_type' => $entity, 'entity_id' => $entityId, 'metadata' => $safe ? json_encode($safe, JSON_THROW_ON_ERROR) : null, 'ip_address' => null, 'created_at' => now()]);
    }
}
