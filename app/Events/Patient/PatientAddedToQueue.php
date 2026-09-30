<?php

namespace App\Events\Patient;

use App\Models\CaseNumber;
use App\Models\ClinicQueueItem;
use App\Models\PatientVisit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PatientAddedToQueue
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PatientVisit $visit,
        public readonly CaseNumber $caseNumber,
        public readonly ClinicQueueItem $queueItem
    ) {}
}
