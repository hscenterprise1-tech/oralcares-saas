<?php

use App\Providers\AppServiceProvider;
use App\Providers\NotificationServiceProvider;
use App\Providers\ObservabilityServiceProvider;
use App\Shared\Search\Providers\SearchServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

return [
    AppServiceProvider::class,
    NotificationServiceProvider::class,
    ObservabilityServiceProvider::class,
    SearchServiceProvider::class,
    PermissionServiceProvider::class,
];
