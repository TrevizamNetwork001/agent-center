<?php

namespace App\Models;

use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;

class ConversationEvent extends Model
{
    protected $table = 'conversation_events';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['context_json' => 'array', 'metadata' => 'array', 'enabled' => 'boolean', 'started_at' => 'immutable_datetime', 'last_activity_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'sequence' => 'integer'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public $timestamps = false;
}
