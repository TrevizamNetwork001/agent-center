<?php

namespace App\Models;

use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;

class AgentRelease extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['artifact_base64'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function descriptor(): array
    {
        return ['id' => $this->id, 'version' => $this->version, 'sha256' => $this->sha256,
            'components' => json_decode($this->manifest, true)['components'] ?? [],
            'platform' => $this->platform, 'sequence' => (int) $this->sequence,
            'status' => 'available', 'device_operations_frozen' => true,
            'compatibility' => 'Debian 13; signed runtime; state/journal schema 1',
            'lifecycle' => ['manifest' => base64_encode($this->manifest), 'signature' => $this->signature]];
    }
}
