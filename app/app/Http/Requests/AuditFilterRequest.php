<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AuditFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('view-audit');
    }

    public function rules(): array
    {
        return ['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', Rule::when((bool) $this->input('from'), 'after_or_equal:from')], 'event' => ['nullable', 'string', 'max:80', 'regex:/^[a-z_.]+$/D'], 'user_id' => ['nullable', 'integer', 'min:1'], 'entity' => ['nullable', 'string', 'max:40', 'regex:/^[a-z_]+$/D']];
    }
}
