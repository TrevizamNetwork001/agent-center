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
            'agent.enroll' => ['code' => ['required', 'string', 'max:110', 'regex:/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\.[a-f0-9]{64}$/D'], 'hostname' => ['required', 'string', 'max:253', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/D'], 'version' => ['required', Rule::in(['0.5.2'])], 'capabilities' => $cap, 'capabilities.*' => ['string', 'distinct', Rule::in(Protocol::CAPABILITIES)]],
            'agent.heartbeat' => ['version' => ['required', Rule::in(['0.5.2'])]],
            'agent.capabilities' => ['capabilities' => ['required', 'array', 'max:14'], 'capabilities.*' => ['string', 'distinct', Rule::in(Protocol::CAPABILITIES)]],
            'agent.claim' => ['capacity' => ['sometimes', 'integer', 'min:0', 'max:1']],
            'agent.reconcile' => ['executions' => ['present', 'array', 'max:100'], 'executions.*.execution_id' => ['required', 'uuid'], 'executions.*.task_id' => ['required', 'uuid'], 'executions.*.state' => ['required', Rule::in(['claimed', 'started', 'executing', 'completed', 'uncertain', 'result_pending'])]],
            default => ['task_id' => ['required', 'uuid'], 'execution_id' => ['required', 'uuid'], 'lease_token' => ['required', 'string', 'min:32', 'max:128'], 'generation' => ['sometimes', 'integer', 'min:1'], 'result' => $this->route()->getName() === 'agent.result' ? ['sometimes', 'array'] : ['prohibited']],
        };
    }
}
