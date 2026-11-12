<?php

namespace App\Http\Requests;

use App\Models\NocDevice;
use App\Noc\Catalog;
use App\Noc\SafeText;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NocDeviceRequest extends FormRequest
{
    public const FIELDS = ['name', 'hostname', 'management_ip', 'manufacturer', 'platform', 'model', 'os_version', 'category', 'network_role', 'pop', 'environment', 'description', 'admin_status', 'operational_notes'];

    public function authorize(): bool
    {
        return $this->route('nocDevice') ? $this->user()->can('update', $this->route('nocDevice')) : $this->user()->can('create', NocDevice::class);
    }

    protected function prepareForValidation(): void
    {
        if (array_diff(array_keys($this->all()), [...self::FIELDS, 'revision', '_token', '_method'])) {
            app('request')->replace([]);
            app('request')->query->replace([]);
            $this->replace([]);
            throw ValidationException::withMessages(['inventory' => 'Campo não permitido. Inventário não aceita credenciais ou comandos de execução.']);
        }
        $values = [];
        foreach (self::FIELDS as $key) {
            if (is_string($this->input($key))) {
                $values[$key] = trim($this->input($key));
            }
        }
        if (isset($values['hostname'])) {
            $values['hostname'] = strtolower(rtrim($values['hostname'], '.'));
        }
        if (isset($values['management_ip']) && filter_var($values['management_ip'], FILTER_VALIDATE_IP)) {
            $ip = inet_ntop(inet_pton($values['management_ip']));
            $values['management_ip'] = str_starts_with($ip, '::ffff:') && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? substr($ip, 7) : $ip;
        }
        $this->merge($values);
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
        $c = app(Catalog::class)->all();
        $short = ['required', 'string', 'max:120', new SafeText];

        return ['name' => $short, 'hostname' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/D'],
            'management_ip' => ['required', 'ip'], 'manufacturer' => ['required', Rule::in(array_keys($c['manufacturers']))],
            'platform' => ['required', Rule::in(array_keys(array_filter($c['platforms'], fn ($p) => $p['manufacturer'] === $this->input('manufacturer'))))],
            'model' => $short, 'os_version' => ['nullable', 'string', 'max:120', new SafeText], 'category' => ['required', Rule::in(array_keys($c['categories']))],
            'network_role' => $short, 'pop' => $short, 'environment' => ['required', Rule::in(['production', 'staging', 'lab'])],
            'description' => ['nullable', 'string', 'max:3000', new SafeText], 'operational_notes' => ['nullable', 'string', 'max:3000', new SafeText],
            'admin_status' => ['required', Rule::in(['active', 'disabled', 'planned'])], 'revision' => [$this->route('nocDevice') ? 'required' : 'prohibited', 'integer', 'min:1']];
    }
}
