<?php

namespace App\Events;

use App\Models\ClinicalDiagnosis;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DiagnosisRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ClinicalDiagnosis $diagnosis
    ) {}
}
