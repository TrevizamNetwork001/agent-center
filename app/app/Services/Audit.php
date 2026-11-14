<?php

namespace App\Services;

use App\Models\User;
use App\Noc\ReadOnlyLab\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Audit
{
    public function record(string $event, ?User $actor = null, ?int $tenantId = null, ?string $entity = null, ?int $entityId = null, array $metadata = []): void
    {
        if ($event !== 'llm.identity_registered' && ! in_array($event, ['diagnostic.routeros_lab_confirmed', 'noc.routeros_lab_attested', 'noc.lab_access_rotated', 'noc.lab_access_revoked', 'noc.lab_query_failed', 'noc.device_created', 'noc.device_updated', 'noc.device_deactivated', 'noc.simulation_created', 'conversation.diagnostic_presented', 'telegram.link_admin_assigned', 'telegram.group_code_created', 'telegram.group_claimed', 'telegram.group_verified', 'telegram.group_revoked', 'telegram.member_authorized', 'telegram.member_revoked', 'telegram.configured', 'telegram.webhook_changed', 'telegram.link_code_created', 'telegram.link_claimed', 'telegram.link_verified', 'telegram.link_revoked', 'telegram.tested', 'telegram.processing_failed', 'telegram.delivery_failed', 'telegram.delivery_retry', 'conversation.language_rendered', 'rag.reindexed', 'rag.searched', 'rag.answered', 'agent.lifecycle_bootstrap_authorized', 'agent.lifecycle_bootstrap_completed', 'agent.permission_requested', 'agent.release_quarantined', 'agent.recovery_requested', 'agent.recovery_verified', 'agent.maintenance_saved', 'agent.maintenance_checked', 'agent.maintenance_removed', 'agent.release_published', 'agent.settings_updated', 'backup.settings_updated', 'backup.requested', 'agent.created', 'agent.deleted', 'agent.enrollment_requested', 'agent.enrollment_approved', 'agent.update_requested', 'agent.update_authorized', 'agent.update_approved', 'agent.update_started', 'agent.update_completed', 'agent.update_failed', 'agent.rollback_requested', 'agent.rollback_completed', 'agent.rollback_failed', 'conversation.transitioned', 'conversation.rollout_updated', 'auth.login_success', 'auth.login_failed', 'auth.logout', 'auth.password_changed', 'user.created', 'user.updated', 'user.disabled', 'tenant.created', 'tenant.updated', 'agent.enrollment_created', 'agent.enrolled', 'agent.capabilities_updated', 'agent.revoked', 'diagnostic.created', 'diagnostic.started', 'diagnostic.cancel_requested', 'diagnostic.cancelled', 'diagnostic.completed', 'diagnostic.failed', 'profile.created', 'profile.updated', 'skill.enabled', 'skill.schema_updated', 'skill.disabled', 'knowledge.created', 'knowledge.version_created', 'knowledge.published', 'knowledge.superseded', 'knowledge.rollback_created', 'knowledge.archived', 'prompt.created', 'prompt.version_created', 'prompt.published', 'prompt.superseded', 'prompt.rollback_created', 'prompt.archived', 'llm.provider_created', 'llm.provider_updated', 'llm.secret_updated', 'llm.model_created', 'llm.binding_updated'], true)) {
            throw new InvalidArgumentException('Evento não permitido.');
        }
        $safe = [];
        if ($event === 'noc.simulation_created' && in_array($metadata['simulation_scope'] ?? null, ['ADMINISTRATIVE_DEMO', 'LAB_CONVERSATION'], true)) {
            $safe['simulation_scope'] = $metadata['simulation_scope'];
        }
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
        if (in_array($event, ['conversation.language_rendered', 'conversation.diagnostic_presented'], true)) {
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
        if ($event === 'conversation.diagnostic_presented') {
            foreach (['classification' => ['NORMAL', 'INDICIO_DE_FALHA', 'INCONCLUSIVO', 'ERRO_DE_EXECUCAO'], 'result_status' => ['LOCAL', 'ASSISTED', 'FALLBACK'], 'tone' => ['CASUAL', 'FORMAL', 'RELAXED', 'TECHNICAL']] as $field => $allowed) {
                if (in_array($metadata[$field] ?? null, $allowed, true)) {
                    $safe[$field] = $metadata[$field];
                }
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
        foreach (['device_id', 'link_id', 'release_id', 'operation_id', 'conversation_id', 'agent_id', 'enrollment_id', 'diagnostic_id', 'profile_id', 'skill_id', 'knowledge_id', 'version_id', 'prompt_id', 'provider_id', 'model_id', 'binding_id'] as $key) {
            if (isset($metadata[$key]) && is_string($metadata[$key]) && Str::isUuid($metadata[$key])) {
                $safe[$key] = $metadata[$key];
            }
        }
        if (in_array($event, ['diagnostic.routeros_lab_confirmed', 'noc.routeros_lab_attested', 'noc.lab_access_rotated', 'noc.lab_access_revoked', 'noc.lab_query_failed', 'noc.simulation_created'], true)) {
            if (in_array($metadata['contract_id'] ?? null, [...array_column(Operation::cases(), 'value'), ...array_column(\App\Noc\ReadOnlyLab\RouterOs7\Operation::cases(), 'value')], true)) {
                $safe['contract_id'] = $metadata['contract_id'];
            }
            foreach (['revision', 'device_revision', 'access_revision'] as $field) {
                if (is_int($metadata[$field] ?? null) && $metadata[$field] > 0) {
                    $safe[$field] = $metadata[$field];
                }
            }
            if (is_string($metadata['parameters_digest'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $metadata['parameters_digest'])) {
                $safe['parameters_digest'] = $metadata['parameters_digest'];
            }
            if (in_array($metadata['reason'] ?? null, ['ROUTEROS_LAB_DISABLED', 'ROUTEROS_APPROVAL_UNKNOWN_DENIED_OR_STALE', 'AGENT_NOT_AUTHORIZED', 'UNSUPPORTED_ROUTEROS_VERSION', 'UNEXPECTED_RESPONSE_FIELD', 'ROW_LIMIT', 'INVALID_BGP_ROW', 'INVALID_ROUTE_ROW', 'INVALID_INTERFACE_ROW', 'INVALID_NUMBER', 'INVALID_CPU', 'INVALID_UPTIME', 'INVALID_LABEL', 'INVALID_IDENTITY', 'DUPLICATE_ROW', 'INVALID_TABLE', 'IPV6_NOT_HOMOLOGATED', 'LAB_BUSY', 'LAB_ROUTE_NOT_SUPPORTED', 'HOST_IDENTITY_REJECTED', 'AUTHENTICATION_FAILED', 'SSH_UNAVAILABLE_OR_NEGOTIATION_FAILED', 'TIMEOUT', 'OUTPUT_LIMIT', 'INVALID_RESPONSE', 'UNRECOGNIZED_OR_TRUNCATED_OUTPUT', 'INVALID_ROWS', 'INVALID_MEMORY', 'INVALID_EVIDENCE', 'LAB_CREDENTIAL_UNAVAILABLE', 'LAB_ACCESS_NOT_APPROVED'], true)) {
                $safe['reason'] = $metadata['reason'];
            }
        }
        if ($event === 'diagnostic.routeros_lab_confirmed' && is_string($metadata['binding_digest'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $metadata['binding_digest'])) {
            $safe['binding_digest'] = $metadata['binding_digest'];
            $safe['mode'] = 'SIMULATED';
        }
        // Dados de requisição, email, senha, hash, cookie, headers e tokens nunca entram aqui.
        DB::table('audit_events')->insert(['tenant_id' => $tenantId, 'user_id' => $actor?->id, 'event_type' => $event, 'entity_type' => $entity, 'entity_id' => $entityId, 'metadata' => $safe ? json_encode($safe, JSON_THROW_ON_ERROR) : null, 'ip_address' => null, 'created_at' => now()]);
    }
}
