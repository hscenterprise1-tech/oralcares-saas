<?php

namespace App\Services\Marketing;

use App\Contracts\WhatsAppProviderInterface;
use App\Services\Marketing\Providers\MetaWhatsAppProvider;

class WhatsAppManager
{
    protected array $providers = [];

    public function __construct()
    {
        $this->providers['meta'] = new MetaWhatsAppProvider;
    }

    /**
     * Resolve provider driver instance.
     */
    public function driver(?string $name = 'meta'): WhatsAppProviderInterface
    {
        $driverName = strtolower($name ?? 'meta');

        if (! isset($this->providers[$driverName])) {
            return $this->providers['meta'];
        }

        return $this->providers[$driverName];
    }
}
