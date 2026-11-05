<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('user') ? $this->user()->can('update', $this->route('user')) : $this->user()->can('create', User::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : null]);
    }

    public function rules(): array
    {
        $roles = $this->user()->role === Role::PlatformAdmin ? ['tenant_admin', 'operator', 'viewer'] : ['operator', 'viewer'];

        return ['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:254', Rule::unique('users')->ignore($this->route('user')?->id)], 'role' => ['required', Rule::in($roles)], 'status' => ['required', Rule::in(['active', 'disabled'])], 'password' => $this->isMethod('POST') ? ['required', 'string', 'max:128', 'confirmed', Password::min(14)->mixedCase()->numbers()->symbols()] : ['prohibited'], 'tenant_id' => ['prohibited'], 'must_change_password' => ['prohibited'], 'last_login_at' => ['prohibited']];
    }
}
