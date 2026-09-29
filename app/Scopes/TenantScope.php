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
    /** Bound in the container while a queued job is being processed (see AppServiceProvider). */
    public const IN_QUEUE_JOB = 'tenancy.in_queue_job';

    /**
     * Apply the scope to a given Eloquent query builder.
     *
     *
     * @throws TenantContextMissingException
     */
    public function apply(Builder $builder, Model $model): void
    {
        // 1. Console processes (artisan, scheduler, queue workers, tests).
        if (app()->runningInConsole() && ! app()->bound('enforce_tenant_scope_in_tests')) {
            $context = app()->bound(ClinicContext::class) ? app(ClinicContext::class) : null;

            // Work started through `ClinicContext::runForClinic()` is filtered
            // to that clinic, exactly as a web request would be.
            if ($context?->isSystemScoped() && $context->hasClinic()) {
                $builder->where($model->getTable().'.clinic_id', $context->clinic()->id);

                return;
            }

            // A queued job that reads clinic data with no tenant context would
            // otherwise read every clinic's data. It must either run for a
            // clinic (`RunsForClinic`) or declare cross-clinic intent with
            // `ClinicContext::withoutTenancy()`.
            if (app()->bound(self::IN_QUEUE_JOB) && ! $context?->tenancyBypassed()) {
                throw new TenantContextMissingException(
                    'Clinic-scoped data ('.class_basename($model).') was queried in a queued job with no tenant context.'
                );
            }

            // Artisan commands, migrations, seeders and test fixtures keep
            // their unscoped access.
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
