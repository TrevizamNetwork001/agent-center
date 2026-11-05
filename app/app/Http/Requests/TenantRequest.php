<?php

namespace App\Http\Requests;

use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('tenant') ? $this->user()->can('update', $this->route('tenant')) : $this->user()->can('create', Tenant::class);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:120'], 'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('tenants')->ignore($this->route('tenant')?->id)], 'status' => ['required', Rule::in(['active', 'disabled'])]];
    }
}
