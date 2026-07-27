<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Global scope that constrains every query on a tenant-owned model to the
 * currently authenticated user's company.
 *
 * Super Admins (SaaS owner) bypass the scope so they can operate cross-tenant.
 * On-prem deployments are single-tenant, so scoping is a no-op safeguard there.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user) {
            return; // unauthenticated context (console, seeders) — no scoping
        }

        if (method_exists($user, 'hasRole') && $user->hasRole('super_admin')) {
            return; // cross-tenant access
        }

        if (! empty($user->company_id)) {
            $builder->where($model->getTable().'.company_id', $user->company_id);
        }
    }
}
