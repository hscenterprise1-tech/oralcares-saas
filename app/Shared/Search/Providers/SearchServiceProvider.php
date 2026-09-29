<?php

namespace App\Shared\Search\Providers;

use App\Shared\Search\Contracts\SearchProviderInterface;
use App\Shared\Search\SearchProviders\BillingSearchProvider;
use App\Shared\Search\SearchProviders\PatientSearchProvider;
use App\Shared\Search\Support\SearchModelObserver;
use Illuminate\Support\ServiceProvider;

class SearchServiceProvider extends ServiceProvider
{
    /**
     * All registered search providers.
     */
    protected array $providers = [
        PatientSearchProvider::class,
        BillingSearchProvider::class,
    ];

    public function register(): void
    {
        // Bind the providers if needed, though app(Class::class) works natively.
    }

    public function boot(): void
    {
        // Automatically attach the observer to each searchable model
        foreach ($this->providers as $providerClass) {
            /** @var SearchProviderInterface $provider */
            $provider = $this->app->make($providerClass);
            $modelClass = $provider->getModelClass();

            if (class_exists($modelClass)) {
                $observer = new SearchModelObserver($provider);

                $modelClass::saved(fn ($model) => $observer->saved($model));
                $modelClass::deleted(fn ($model) => $observer->deleted($model));

                if (method_exists($modelClass, 'restored')) {
                    $modelClass::restored(fn ($model) => $observer->restored($model));
                }
            }
        }
    }
}
