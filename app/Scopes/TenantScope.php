<?php

namespace App\Scopes;

use App\Exceptions\TenantContextMissingException;
use App\Services\ClinicContext;
use App\UserType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     *
     *
     * @throws TenantContextMissingException
     */
    public function apply(Builder $builder, Model $model): void
    {
        // 1. Skip if running in console unless explicitly enforced for testing
        if (app()->runningInConsole() && ! app()->bound('enforce_tenant_scope_in_tests')) {
            return;
        }

        // 2. Skip if the logged-in user is not a ClinicUser (e.g., MasterAdmin, CompanyUser)
        $user = auth()->user();
        if ($user && $user->user_type !== UserType::ClinicUser) {
            return;
        }

        // 3. Resolve context
        if (app()->bound(ClinicContext::class)) {
            $context = app(ClinicContext::class);

            if ($context->hasClinic()) {
                $builder->where($model->getTable().'.clinic_id', $context->clinic()->id);

                return;
            }

            // 4. Handle missing clinic context for authenticated ClinicUsers
            if ($user && $user->user_type === UserType::ClinicUser) {
                throw new TenantContextMissingException;
            }
        }

        // Fallback for guests or other cases: return empty results to prevent leakage
        $builder->whereRaw('1 = 0');
    }
}
