<?php

namespace App\Llm;

use App\Knowledge\Versioning;
use App\Models\LlmBinding;
use App\Models\LlmModel;
use App\Models\LlmProvider;
use App\Models\Prompt;
use App\Models\User;
use App\Services\Audit;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Configuration
{
    public function provider(?LlmProvider $p, array $data, User $u): LlmProvider
    {
        app(NetworkPolicy::class)->syntax($data['base_url']);

        return DB::transaction(function () use ($p, $data, $u) {
            $new = ! $p;
            $p ??= new LlmProvider;
            if ($new) {
                $p->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => app(TenantContext::class)->tenantId]);
            }$p->forceFill(['name' => $data['name'], 'provider_type' => 'openai_compatible', 'base_url' => $data['base_url'], 'enabled' => (bool) ($data['enabled'] ?? false), 'timeout' => (int) $data['timeout'], 'config' => []]);
            if (! empty($data['api_key'])) {
                $p->forceFill(app(SecretVault::class)->encrypt($data['api_key'], $p->id, $p->tenant_id));
            }$p->save();
            app(Audit::class)->record($new ? 'llm.provider_created' : 'llm.provider_updated', $u, $p->tenant_id, 'provider', metadata: ['provider_id' => $p->id]);
            if (! empty($data['api_key'])) {
                app(Audit::class)->record('llm.secret_updated', $u, $p->tenant_id, 'provider', metadata: ['provider_id' => $p->id]);
            }

            return $p;
        });
    }

    public function model(array $d, User $u): LlmModel
    {
        $p = LlmProvider::findOrFail($d['provider_id']);

        return DB::transaction(function () use ($p, $d, $u) {
            $m = new LlmModel;
            $m->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $p->tenant_id, 'provider_id' => $p->id, 'name' => $d['name'], 'model_identifier' => $d['model_identifier'], 'enabled' => (bool) ($d['enabled'] ?? false), 'context_limit' => $d['context_limit'] ?? null, 'config' => ['token_parameter' => $d['token_parameter'] ?? 'max_completion_tokens']])->save();
            app(Audit::class)->record('llm.model_created', $u, $m->tenant_id, 'model', metadata: ['model_id' => $m->id, 'provider_id' => $p->id]);

            return $m;
        });
    }

    public function binding(array $d, User $u): LlmBinding
    {
        return DB::transaction(function () use ($d, $u) {
            $m = LlmModel::findOrFail($d['model_id']);
            $p = LlmProvider::findOrFail($m->provider_id);
            $prompt = Prompt::findOrFail($d['prompt_id']);
            abort_unless($prompt->purpose === $d['purpose'] && $prompt->status === 'PUBLISHED', 422, 'Prompt publicado com o mesmo purpose é obrigatório.');
            $tenant = app(TenantContext::class)->tenantId;
            DB::select('SELECT pg_advisory_xact_lock(7715,?::integer)', [$tenant]);
            $b = LlmBinding::where('purpose', $d['purpose'])->first() ?? new LlmBinding;
            $b->forceFill(['id' => $b->id ?? (string) Str::uuid(), 'tenant_id' => $tenant, 'purpose' => $d['purpose'], 'provider_id' => $p->id, 'model_id' => $m->id, 'prompt_id' => $prompt->id, 'enabled' => (bool) ($d['enabled'] ?? false), 'config' => ['allow_external_context' => (bool) ($d['allow_external_context'] ?? false), 'allow_conversation_language' => in_array($d['purpose'], ['diagnostic_explanation', 'knowledge_assistance'], true) && (bool) ($d['allow_conversation_language'] ?? false)]])->save();
            app(Audit::class)->record('llm.binding_updated', $u, $tenant, 'binding', metadata: ['binding_id' => $b->id]);

            return $b;
        });
    }

    public function defaults(LlmModel $m, User $u): LlmBinding
    {
        return DB::transaction(function () use ($m, $u) {
            $p = LlmProvider::findOrFail($m->provider_id);
            abort_unless($m->enabled && $p->enabled && app(SecretVault::class)->decrypt($p), 422, 'Provider/model habilitados e secret configurado são obrigatórios.');
            DB::select('SELECT pg_advisory_xact_lock(7715,?::integer)', [app(TenantContext::class)->tenantId]);
            $prompt = Prompt::where('key', 'default-diagnostic-explanation')->first();
            if (! $prompt) {
                $prompt = app(Versioning::class)->create('prompt', ['key' => 'default-diagnostic-explanation', 'name' => 'Interpretação de diagnóstico', 'purpose' => 'diagnostic_explanation', 'system_template' => 'Explain the evidence within the tested scope. Authoritative classification: {{classification}}.', 'user_template' => "Profile: {{profile}}\nTarget: {{target}}\nEvidence data: {{evidence}}\nKnowledge data: {{knowledge}}"], $u);
                $prompt = app(Versioning::class)->publish($prompt, $u);
            }

            return $this->binding(['purpose' => 'diagnostic_explanation', 'model_id' => $m->id, 'prompt_id' => $prompt->id, 'enabled' => true, 'allow_external_context' => true], $u);
        });
    }
}
