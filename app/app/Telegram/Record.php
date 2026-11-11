<?php

namespace App\Telegram;

use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;

abstract class Record extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['token_cipher', 'secret_cipher', 'token_fingerprint', 'code_hash', 'payload_cipher', 'body_cipher'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'tenant_id' => 'integer', 'user_id' => 'integer', 'cursor' => 'integer', 'attempts' => 'integer', 'restrict_members' => 'boolean', 'shared_ack' => 'boolean', 'thread_id' => 'integer', 'requested_by' => 'integer', 'verified_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'message_date' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
