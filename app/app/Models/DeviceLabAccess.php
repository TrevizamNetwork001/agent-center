<?php

namespace App\Models;

use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;

class DeviceLabAccess extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['secret_ciphertext', 'secret_key_id', 'host_public_key'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'revision' => 'integer', 'device_revision' => 'integer', 'protocol_approval' => 'array'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
