<?php

namespace App\Models;

use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;

class AgentMaintenanceAccess extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $guarded = ['*'];

    protected $hidden = ['private_key', 'password'];

    protected function casts(): array
    {
        return ['private_key' => 'encrypted', 'password' => 'encrypted', 'expires_at' => 'datetime', 'checked_at' => 'datetime', 'until_revoked' => 'boolean'];
    }

    public function isExpired(): bool
    {
        return ! $this->until_revoked && (! $this->expires_at || $this->expires_at->isPast());
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
