<?php

namespace App\Providers;

use App\Shared\Notification\Channels\InAppChannel;
use App\Shared\Notification\Channels\OmniMailChannel;
use App\Shared\Notification\Channels\SmsChannel;
use App\Shared\Notification\Channels\WhatsAppChannel;
use App\Shared\Notification\Repositories\PreferenceRepository;
use App\Shared\Notification\Services\NotificationEngineService;
use Illuminate\Support\ServiceProvider;

class NotificationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(NotificationEngineService::class, function ($app) {
            $engine = new NotificationEngineService($app->make(PreferenceRepository::class));

            $engine->registerChannel(new InAppChannel);
            $engine->registerChannel(new OmniMailChannel);
            $engine->registerChannel(new WhatsAppChannel);
            $engine->registerChannel(new SmsChannel);

            return $engine;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
