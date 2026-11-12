<?php

namespace App\Http\Requests;

use App\Noc\Catalog;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NocActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->routeIs('noc.simulate') ? 'simulate' : 'update', $this->route('nocDevice'));
    }

    protected function prepareForValidation(): void
    {
        $allowed = $this->routeIs('noc.simulate') ? ['fixture', 'request_key', 'result', 'is_simulated'] : ['revision'];
        if (array_diff(array_keys($this->all()), [...$allowed, '_token', '_method'])) {
            app('request')->replace([]);
            app('request')->query->replace([]);
            $this->replace([]);
            throw ValidationException::withMessages(['inventory' => 'Campo não permitido nesta ação administrativa.']);
        }
    }

    protected function failedValidation(Validator $validator)
    {
        app('request')->replace([]);
        app('request')->query->replace([]);
        $this->replace([]);
        parent::failedValidation($validator);
    }

    public function rules(): array
    {
        return $this->routeIs('noc.simulate') ? ['fixture' => ['required', Rule::in(array_keys(app(Catalog::class)->fixtures()))], 'request_key' => 'required|uuid', 'result' => 'prohibited', 'is_simulated' => 'prohibited'] : ['revision' => 'required|integer|min:1'];
    }
}
