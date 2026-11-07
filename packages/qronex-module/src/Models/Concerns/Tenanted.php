<?php

namespace Ametecs\Qronex\Models\Concerns;

use Ametecs\Qronex\Qronex;
use Illuminate\Database\Eloquent\Builder;

/** Scope to the admin request's tenant (set by the admin middleware) and fill tenant_id on create. Phone API code uses withoutGlobalScopes(). */
trait Tenanted
{
    public static function bootTenanted(): void
    {
        static::addGlobalScope('qx_tenant', function (Builder $q) {
            if (($tid = Qronex::tenantId()) !== null) {
                $q->where($q->getModel()->getTable().'.tenant_id', $tid);
            }
        });
        static::creating(function ($m) {
            $m->tenant_id ??= Qronex::tenantId();
        });
    }
}
