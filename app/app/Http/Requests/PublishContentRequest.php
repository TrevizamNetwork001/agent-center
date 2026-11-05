<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class PublishContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-intelligence');
    }

    public function rules(): array
    {
        return ['expected_draft_id' => ['required', 'uuid']];
    }
}
