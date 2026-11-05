<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class CatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-diagnostic-catalog');
    }

    public function rules(): array
    {
        return ['enabled' => ['required', 'boolean'], 'tenant_id' => ['prohibited'], 'definition' => ['prohibited'], 'capability' => ['prohibited']];
    }
}
