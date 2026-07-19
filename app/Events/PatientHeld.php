<?php

namespace App\Events;

use App\Models\ClinicQueueItem;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PatientHeld
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ClinicQueueItem $queueItem
    ) {}
}
