<?php

namespace App\Providers;

use App\Models\Clinic;
use App\Models\Lead;
use App\Services\ClinicAuthorization;
use App\Services\ClinicContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ClinicContext::class);
        $this->app->scoped(ClinicAuthorization::class);
    }

    public function boot(): void
    {
        Relation::morphMap([
            'lead' => Lead::class,
            'clinic' => Clinic::class,
        ]);
    }
}
