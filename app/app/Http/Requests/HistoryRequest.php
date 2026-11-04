<?php

namespace App\Http\Requests;

use App\Models\Diagnostic;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class HistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Diagnostic::class);
    }

    public function rules(): array
    {
        return ['from' => ['nullable', 'date_format:Y-m-d'], 'until' => ['nullable', 'date_format:Y-m-d', Rule::when((bool) $this->input('from'), 'after_or_equal:from')], 'profile_id' => ['nullable', 'uuid'], 'agent_id' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in(['PENDING', 'RUNNING', 'COMPLETED', 'CANCELLED', 'FAILED'])], 'classification' => ['nullable', Rule::in(['NORMAL', 'INDICIO_DE_FALHA', 'INCONCLUSIVO', 'ERRO_DE_EXECUCAO'])], 'target' => ['nullable', 'string', 'max:253']];
    }
}
