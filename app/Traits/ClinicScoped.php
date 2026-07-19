<?php

namespace App\Traits;

use App\Scopes\TenantScope;

trait ClinicScoped
{
    /**
     * Boot the clinic scoped trait for a model.
     */
    public static function bootClinicScoped(): void
    {
        static::addGlobalScope(new TenantScope);
    }
}
