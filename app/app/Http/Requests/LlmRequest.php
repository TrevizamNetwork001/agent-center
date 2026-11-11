<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class LlmRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-intelligence');
    }

    public function rules(): array
    {
        $base = ['tenant_id' => ['prohibited'], 'config' => ['prohibited'], 'secret_ciphertext' => ['prohibited'], 'secret_key_id' => ['prohibited']];

        return $base + match ($this->route()->getName()) {
            'llm.provider.store','llm.provider.update' => ['name' => ['required', 'string', 'max:120'], 'base_url' => ['required', 'string', 'max:512'], 'timeout' => ['required', 'integer', 'between:1,15'], 'enabled' => ['sometimes', 'boolean'], 'api_key' => ['nullable', 'string', 'min:8', 'max:2048', 'regex:/^[\x21-\x7E]+$/D']],
            'llm.model.store' => ['provider_id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:120'], 'model_identifier' => ['required', 'string', 'max:120', 'regex:/^[a-zA-Z0-9._:\/-]+$/D'], 'enabled' => ['sometimes', 'boolean'], 'token_parameter' => ['nullable', Rule::in(['max_completion_tokens', 'max_tokens'])], 'context_limit' => ['nullable', 'integer', 'between:1024,2000000']],
            default => ['model_id' => ['required', 'uuid'], 'prompt_id' => ['required', 'uuid'], 'purpose' => ['required', Rule::in(['diagnostic_summary', 'diagnostic_explanation', 'knowledge_assistance'])], 'enabled' => ['sometimes', 'boolean'], 'allow_external_context' => ['sometimes', 'boolean'], 'allow_conversation_language' => ['sometimes', 'boolean']]
        };
    }
}
