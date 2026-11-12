<?php

namespace App\Http\Controllers\Agent;

use App\Diagnostics\Preflight;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PreflightController
{
    public function __invoke(Request $request, Preflight $preflight)
    {
        abort_if(strlen($request->getContent()) > 4096, 413);
        $data = $request->validate([
            'schema_version' => ['required', 'integer', 'in:1'], 'agent_id' => ['prohibited'], 'tenant_id' => ['prohibited'],
            'result' => ['sometimes', 'array:schema_version,id,state,reason,helper_healthy,agent_version,policy_digest,effective_revision,helper_version'],
            'result.schema_version' => ['required_with:result', 'integer', 'in:1'], 'result.id' => ['required_with:result', 'uuid'],
            'result.state' => ['required_with:result', Rule::in(['ALLOWED', 'DENIED', 'UNKNOWN'])],
            'result.reason' => ['required_with:result', Rule::in(['authorized', 'policy_denied', 'unverified', 'helper_unavailable', 'capability_unavailable'])],
            'result.helper_healthy' => ['present_with:result', 'nullable', 'boolean'],
            'result.agent_version' => ['required_with:result', 'in:0.6.5'],
            'result.policy_digest' => ['required_with:result', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'result.helper_version' => ['sometimes', 'in:0.6.5'],
            'result.effective_revision' => ['sometimes', 'string', 'regex:/^[a-f0-9]{64}$/D'],
        ]);

        return response()->json(['schema_version' => 1, 'request' => $preflight->exchange($request->attributes->get('agent'), $data['result'] ?? null)]);
    }
}
