<?php

namespace App\Http\Requests;

use App\Models\Diagnostic;
use Illuminate\Foundation\Http\FormRequest;

class ConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Diagnostic::class) ?? false;
    }

    public function rules(): array
    {
        return ['request_key' => ['required', 'uuid'], 'body' => [$this->routeIs('conversations.send') ? 'required' : 'prohibited', 'string', 'max:2000'], 'agent_id' => [$this->routeIs('conversations.store') ? 'nullable' : 'prohibited', 'uuid'], 'tenant_id' => ['prohibited'], 'user_id' => ['prohibited'], 'state' => ['prohibited'], 'context_json' => ['prohibited']];
    }
}
