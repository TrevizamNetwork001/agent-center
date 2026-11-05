<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PromptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-intelligence');
    }

    public function rules(): array
    {
        $new = $this->routeIs('prompts.store');

        return ['key' => [$new ? 'required' : 'prohibited', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_-]*$/D'], 'name' => [$new ? 'required' : 'prohibited', 'string', 'max:160'], 'purpose' => [$new ? 'required' : 'prohibited', Rule::in(['diagnostic_summary', 'diagnostic_explanation', 'knowledge_assistance'])], 'system_template' => ['required', 'string', 'max:12000'], 'user_template' => ['nullable', 'string', 'max:12000'], 'change_summary' => ['nullable', 'string', 'max:300'], 'tenant_id' => ['prohibited'], 'status' => ['prohibited'], 'expected_output_schema' => ['prohibited']];
    }
}
