<?php

namespace App\Http\Requests;

use App\Models\Diagnostic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class DiagnosticRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', Diagnostic::class);
    }

    public function rules(): array
    {
        return ['mtr_requested' => ['sometimes', 'boolean'], 'trace_requested' => ['sometimes', 'boolean'], 'target' => ['required', 'string', 'max:512'], 'profile_id' => ['required', 'uuid'], 'agent_id' => ['required', 'uuid'], 'tenant_id' => ['prohibited'], 'status' => ['prohibited']];
    }
}
