<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class KnowledgeFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('read-knowledge');
    }

    public function rules(): array
    {
        return ['scope' => ['nullable', Rule::in(['global', 'diagnostic', 'profile', 'operations', 'tenant'])], 'status' => ['nullable', Rule::in(['DRAFT', 'PUBLISHED', 'ARCHIVED'])], 'tag' => ['nullable', 'string', 'max:50', 'regex:/^[a-zA-Z0-9_:-]+$/D'], 'profile_id' => ['nullable', 'uuid']];
    }
}
