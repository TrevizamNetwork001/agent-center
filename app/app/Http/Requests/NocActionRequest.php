<?php

namespace App\Http\Requests;

use App\Noc\Analysis\Simulation;
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
        $allowed = $this->routeIs('noc.simulate') ? ['fixture', 'request_key', 'result', 'is_simulated', 'parameters', 'device_revision'] : ['revision'];
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

    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->routeIs('noc.simulate')) {
                return;
            }
            try {
                $parameters = $this->input('parameters', []);
                foreach (['cpu_reference_percent', 'memory_reference_percent'] as $key) {
                    if (isset($parameters[$key]) && is_string($parameters[$key]) && preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $parameters[$key])) {
                        $parameters[$key] = (float) $parameters[$key];
                    } elseif (array_key_exists($key, $parameters) && in_array($parameters[$key], ['', null], true)) {
                        unset($parameters[$key]);
                    }
                }
                $this->merge(['parameters' => $parameters]);
                $validator->setValue('parameters', $parameters);
                app(Catalog::class)->fixtureFor($this->route('nocDevice'), $this->string('fixture')->toString(), $parameters);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $key => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($key, $message);
                    }
                }
            }
        }];
    }

    public function rules(): array
    {
        return $this->routeIs('noc.simulate') ? ['fixture' => ['required', Rule::in(array_keys([...app(Catalog::class)->fixtures(), ...app(Simulation::class)->fixtures()]))], 'request_key' => 'required|uuid', 'parameters' => 'sometimes|array|max:4', 'device_revision' => 'required_if:fixture,'.implode(',', array_keys(app(Simulation::class)->fixtures())).'|integer|min:1', 'result' => 'prohibited', 'is_simulated' => 'prohibited'] : ['revision' => 'required|integer|min:1'];
    }
}
