<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role === Role::PlatformAdmin;
    }

    public function rules(): array
    {
        return ['tenant_id' => ['nullable', 'integer', Rule::exists('tenants', 'id')->where('status', 'active')]];
    }
}
