<?php

namespace App\Providers;

use App\Services\ClinicAuthorization;
use App\Services\ClinicContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ClinicContext::class);
        $this->app->scoped(ClinicAuthorization::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
