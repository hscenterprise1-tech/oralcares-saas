<?php

namespace App\Providers;

use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Lead;
use App\Models\User;
use App\PermissionKey;
use App\Services\ClinicAuthorization;
use App\Services\ClinicContext;
use App\Services\CompanyAuthorization;
use App\UserType;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ClinicContext::class);
        $this->app->scoped(ClinicAuthorization::class);
        $this->app->scoped(CompanyAuthorization::class);
    }

    public function boot(): void
    {
        Relation::morphMap([
            'lead' => Lead::class,
            'clinic' => Clinic::class,
        ]);

        Gate::before(function (User $user, string $ability, array $arguments) {
            if ($user->isMasterAdmin()) {
                return true;
            }

            // 1. Clinic Permission Check
            if (PermissionKey::isValid($ability)) {
                $clinicAuth = app(ClinicAuthorization::class);
                $branch = null;
                if (isset($arguments[0]) && $arguments[0] instanceof Branch) {
                    $branch = $arguments[0];
                }

                return $clinicAuth->can($ability, $branch);
            }

            // 2. Company Permission Check
            if ($user->user_type === UserType::CompanyUser) {
                $employee = $user->companyEmployee;
                if ($employee) {
                    $companyAuth = app(CompanyAuthorization::class);
                    if ($companyAuth->isPermissionKey($ability)) {
                        return $companyAuth->hasPermission($employee, $ability);
                    }
                }
            }

            return null; // Fall through
        });
    }
}
