<?php

namespace App\Events;

use App\Models\TreatmentRecord;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TreatmentPaused
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public TreatmentRecord $treatment
    ) {}
}
