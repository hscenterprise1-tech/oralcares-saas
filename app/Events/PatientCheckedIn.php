<?php

namespace App\Events;

use App\Models\ClinicalEncounter;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PatientCheckedIn
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ClinicalEncounter $encounter
    ) {}
}
