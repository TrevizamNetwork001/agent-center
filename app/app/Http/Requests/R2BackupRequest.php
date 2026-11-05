<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class R2BackupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('manage-platform-backups');
    }

    public function rules(): array
    {
        return [
            'r2_account_id' => ['nullable', 'string', 'regex:/^[a-f0-9]{32}$/D'],
            'r2_access_key_id' => ['nullable', 'string', 'min:16', 'max:128', 'regex:/^[A-Za-z0-9]+$/D'],
            'r2_secret_access_key' => ['nullable', 'string', 'min:16', 'max:256', 'regex:/^[\x21-\x7E]+$/D', 'not_regex:/AGE-SECRET-KEY|PRIVATE KEY|-----BEGIN/i'],
            'r2_recipient' => ['nullable', 'string', 'regex:/^age1[a-z0-9]{58}$/D'],
            'r2_offsite_ack' => ['sometimes', 'accepted'],
            'tenant_id' => ['prohibited'],
            'bucket' => ['prohibited'],
            'endpoint' => ['prohibited'],
            'command' => ['prohibited'],
            'private_key' => ['prohibited'],
        ];
    }

    public function attributes(): array
    {
        return ['r2_account_id' => 'Account ID', 'r2_access_key_id' => 'Access Key ID', 'r2_secret_access_key' => 'Secret Access Key', 'r2_recipient' => 'chave pública age', 'r2_offsite_ack' => 'custódia externa'];
    }
}
