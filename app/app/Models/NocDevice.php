<?php

namespace App\Models;

use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;

class NocDevice extends Model
{
    protected $table = 'noc_devices';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'result' => 'array', 'revision' => 'integer', 'is_simulated' => 'boolean', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
