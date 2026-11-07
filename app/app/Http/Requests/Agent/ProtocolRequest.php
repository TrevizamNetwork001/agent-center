<?php

namespace App\Http\Requests\Agent;

use App\Agent\Protocol;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProtocolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $cap = ['sometimes', 'array', 'max:14'];
        $base = ['task_protocol' => ['sometimes', 'integer', 'in:1'], 'agent_id' => ['prohibited'], 'tenant_id' => ['prohibited'], 'metadata' => ['prohibited']];

        return $base + match ($this->route()->getName()) {
            'agent.enrollment-status' => ['receipt' => ['required', 'string', 'regex:/^[a-f0-9-]{36}\\.[a-f0-9]{64}$/D']],
            'agent.enroll' => ['fingerprint' => ['sometimes', 'string', 'regex:/^[a-f0-9]{64}$/D'], 'code' => ['required', 'string', 'max:110', 'regex:/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\.[a-f0-9]{64}$/D'], 'hostname' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D'], 'version' => ['required', Rule::in(Protocol::VERSIONS)], 'capabilities' => $cap, 'capabilities.*' => ['string', 'distinct', Rule::in(Protocol::CAPABILITIES)]],
            'agent.heartbeat' => ['version' => ['required', 'string', 'regex:/^[0-9]+\\.[0-9]+\\.[0-9]+$/D', 'max:30'],
                'telemetry' => ['sometimes', 'array:os,os_release,kernel,python,cpu_count,process_alive,pid,threads,uptime_seconds,device_operations_frozen,dry_run,memory_total_bytes,rss_bytes,disk_free_bytes,journal_ok,journal_pending,service_status,local_addresses,dns_resolvers,settings_revision'],
                ...collect(['os', 'os_release', 'kernel', 'python'])->mapWithKeys(fn ($k) => ['telemetry.'.$k => ['nullable', 'string', 'max:120', 'regex:/^[a-zA-Z0-9 ._+()~:\\/-]+$/D']])->all(),
                ...collect(['cpu_count', 'pid', 'threads', 'uptime_seconds', 'memory_total_bytes', 'rss_bytes', 'disk_free_bytes', 'journal_pending'])->mapWithKeys(fn ($k) => ['telemetry.'.$k => ['nullable', 'integer', 'min:0', 'max:9223372036854775807']])->all(),
                ...collect(['process_alive', 'device_operations_frozen', 'dry_run', 'journal_ok'])->mapWithKeys(fn ($k) => ['telemetry.'.$k => ['nullable', 'boolean']])->all(),
                'telemetry.local_addresses' => ['sometimes', 'array', 'max:32'], 'telemetry.local_addresses.*' => ['string', 'ip', 'distinct'], 'telemetry.dns_resolvers' => ['sometimes', 'array', 'max:4'], 'telemetry.dns_resolvers.*' => ['string', 'ip', 'distinct'], 'telemetry.settings_revision' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
                'telemetry.service_status' => ['nullable', Rule::in(['active', 'inactive', 'failed'])]],
            'agent.capabilities' => ['capabilities' => ['required', 'array', 'max:14'], 'capabilities.*' => ['string', 'distinct', Rule::in(Protocol::CAPABILITIES)]],
            'agent.claim' => ['capacity' => ['sometimes', 'integer', 'min:0', 'max:1']],
            'agent.reconcile' => ['executions' => ['present', 'array', 'max:100'], 'executions.*.execution_id' => ['required', 'uuid'], 'executions.*.task_id' => ['required', 'uuid'], 'executions.*.state' => ['required', Rule::in(['claimed', 'started', 'executing', 'completed', 'uncertain', 'result_pending'])]],
            default => ['task_id' => ['required', 'uuid'], 'execution_id' => ['required', 'uuid'], 'lease_token' => ['required', 'string', 'min:32', 'max:128'], 'generation' => ['sometimes', 'integer', 'min:1'], 'result' => $this->route()->getName() === 'agent.result' ? ['sometimes', 'array'] : ['prohibited']],
        };
    }
}
