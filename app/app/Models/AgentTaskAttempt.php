<?php

namespace App\Models;

use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;

class AgentTaskAttempt extends Model
{
    protected $table = 'agent_task_attempts';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['lease_hash'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'document' => 'array', 'input' => 'array', 'result' => 'array', 'evidence' => 'array', 'next_steps' => 'array', 'input_schema' => 'array', 'output_schema' => 'array', 'enabled' => 'boolean', 'safe' => 'boolean', 'optional' => 'boolean', 'version' => 'integer', 'generation' => 'integer', 'timeout' => 'integer', 'wave' => 'integer', 'lease_expires_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'cancel_requested_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
