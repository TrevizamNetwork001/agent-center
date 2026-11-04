<?php

namespace App\Models;

use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    protected $table = 'agents';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['capabilities' => 'array', 'metadata' => 'array', 'enrolled_at' => 'datetime', 'last_heartbeat_at' => 'datetime', 'last_poll_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
