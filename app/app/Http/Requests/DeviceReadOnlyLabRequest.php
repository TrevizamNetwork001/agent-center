<?php

namespace App\Http\Requests;

use App\Noc\Analysis\Contracts;
use App\Noc\ReadOnlyLab\Access;
use App\Noc\ReadOnlyLab\Operation;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DeviceReadOnlyLabRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(Access::class)->enabled() && $this->user()->can('simulate', $this->route('nocDevice'));
    }

    protected function prepareForValidation(): void
    {
        if (array_diff(array_keys($this->all()), ['operation', 'request_key', 'device_revision', 'parameters', '_token'])) {
            $this->erase();
            throw ValidationException::withMessages(['analysis' => 'Campo não permitido.']);
        }
    }

    private function erase(): void
    {
        app('request')->replace([]);
        app('request')->query->replace([]);
        $this->replace([]);
    }

    protected function failedValidation(Validator $validator)
    {
        $this->erase();
        parent::failedValidation($validator);
    }

    public function rules(): array
    {
        return ['operation' => ['required', Rule::enum(Operation::class)], 'request_key' => 'required|uuid', 'device_revision' => 'required|integer|min:1', 'parameters' => 'sometimes|array|max:4'];
    }

    public function after(): array
    {
        return [function ($v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }
            try {
                $c = app(Contracts::class)->require($this->route('nocDevice'), $this->input('operation'));
                app(Contracts::class)->parameters($c, $this->input('parameters', []));
            } catch (ValidationException $e) {
                foreach ($e->errors() as $key => $messages) {
                    foreach ($messages as $message) {
                        $v->errors()->add($key, $message);
                    }
                }
            }
        }];
    }
}
