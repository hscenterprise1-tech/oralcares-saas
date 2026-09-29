<?php

use App\Providers\AppServiceProvider;
use App\Providers\NotificationServiceProvider;
use App\Shared\Search\Providers\SearchServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

return [
    AppServiceProvider::class,
    NotificationServiceProvider::class,
    SearchServiceProvider::class,
    PermissionServiceProvider::class,
];
