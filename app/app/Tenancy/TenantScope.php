<?php

namespace App\Tenancy;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);
        if ($context->tenantId !== null) {
            $builder->where($model->qualifyColumn('tenant_id'), $context->tenantId);
        } elseif ($model instanceof User) {
            $builder->where($model->qualifyColumn('id'), $context->userId ?? 0);
        } elseif ($context->platform) {
            $builder->whereNull($model->qualifyColumn('tenant_id'));
        } else {
            $builder->whereRaw('1 = 0');
        }
    }
}
