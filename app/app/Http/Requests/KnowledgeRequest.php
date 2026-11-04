<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class KnowledgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-intelligence');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('tags_text') && is_string($this->input('tags_text'))) {
            $this->merge(['tags' => array_values(array_filter(array_map('trim', explode(',', $this->input('tags_text')))))]);
        }
    }

    public function rules(): array
    {
        $new = $this->routeIs('knowledge.store');

        return ['tags_text' => ['nullable', 'string', 'max:600'], 'key' => [$new ? 'required' : 'prohibited', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9_-]*$/D'], 'title' => [$new ? 'required' : 'prohibited', 'string', 'max:160'], 'category' => [$new ? 'required' : 'prohibited', 'string', 'max:60'], 'scope' => [$new ? 'required' : 'prohibited', Rule::in(['global', 'diagnostic', 'profile', 'operations', 'tenant'])], 'profile_id' => ['nullable', 'uuid', Rule::requiredIf($this->input('scope') === 'profile'), Rule::prohibitedIf($this->input('scope') !== 'profile')], 'tags' => ['nullable', 'array', 'max:12'], 'tags.*' => ['string', 'max:50', 'regex:/^[a-zA-Z0-9_:-]+$/D'], 'content' => ['required', 'string', 'max:24000'], 'change_summary' => ['nullable', 'string', 'max:300'], 'tenant_id' => ['prohibited'], 'status' => ['prohibited'], 'current_version_id' => ['prohibited']];
    }
}
