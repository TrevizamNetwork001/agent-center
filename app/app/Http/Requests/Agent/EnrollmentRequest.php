<?php

namespace App\Http\Requests\Agent;

use App\Models\Agent;
use Illuminate\Foundation\Http\FormRequest;

class EnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Agent::class);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120'], 'tenant_id' => ['prohibited'], 'expires_in' => ['sometimes', 'integer', 'min:60', 'max:1800']];
    }
}
