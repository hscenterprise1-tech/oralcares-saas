<?php

namespace App\Events;

use App\Models\TreatmentPlan;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TreatmentPlanCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public TreatmentPlan $plan
    ) {}
}
