<?php

namespace App\Http\Controllers\Concerns;

use App\Services\ClinicContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Route-bound records must belong to the active clinic: anything else is
 * answered as if it did not exist (404). The tenant scope already does this
 * during binding; this is the same rule, checked again where it matters.
 */
trait BindsToActiveClinic
{
    protected function inActiveClinic(Model ...$models): void
    {
        $clinicId = app(ClinicContext::class)->clinic()?->id;
        foreach ($models as $model) {
            abort_if($clinicId === null || (int) $model->getAttribute('clinic_id') !== $clinicId, 404);
        }
    }
}
