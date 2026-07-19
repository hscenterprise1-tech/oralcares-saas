<?php

namespace App\Events;

use App\Models\ClinicalEncounter;
use App\Models\ClinicEmployee;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DoctorAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ClinicalEncounter $encounter,
        public ClinicEmployee $doctor
    ) {}
}
