<?php

namespace App\Traits;

use App\Models\Clinic;
use App\Scopes\TenantScope;
use App\Services\ClinicContext;

trait ClinicScoped
{
    /**
     * Boot the clinic scoped trait for a model.
     */
    public static function bootClinicScoped(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (empty($model->clinic_id) && app()->bound(ClinicContext::class)) {
                $context = app(ClinicContext::class);
                if ($context->hasClinic()) {
                    $model->clinic_id = $context->clinic()->id;
                }
            }

            if (in_array('organization_id', $model->getFillable(), true) && empty($model->organization_id)) {
                if (! empty($model->clinic_id)) {
                    $clinic = Clinic::find($model->clinic_id);
                    if ($clinic && ! empty($clinic->organization_id)) {
                        $model->organization_id = $clinic->organization_id;
                    }
                }
            }
        });
    }
}
