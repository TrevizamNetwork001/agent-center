<?php

namespace App\Models;

use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;

class KnowledgeItem extends Model
{
    protected $table = 'knowledge_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['secret_ciphertext', 'secret_key_id'];

    public $timestamps = true;

    protected function casts(): array
    {
        return ['tags' => 'array', 'config' => 'array', 'expected_output_schema' => 'array', 'next_steps' => 'array', 'provider_snapshot' => 'array', 'enabled' => 'boolean', 'context_limit' => 'integer', 'timeout' => 'integer', 'version_number' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
