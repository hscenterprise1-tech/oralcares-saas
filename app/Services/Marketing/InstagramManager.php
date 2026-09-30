<?php

namespace App\Services\Marketing;

use App\Contracts\InstagramProviderInterface;
use App\Services\Marketing\Providers\MetaInstagramProvider;

class InstagramManager
{
    protected array $providers = [];

    public function __construct()
    {
        $this->providers['meta'] = new MetaInstagramProvider;
    }

    public function driver(?string $name = 'meta'): InstagramProviderInterface
    {
        $driverName = strtolower($name ?? 'meta');

        return $this->providers[$driverName] ?? $this->providers['meta'];
    }
}
