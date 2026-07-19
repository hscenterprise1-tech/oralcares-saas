<?php

namespace App\Events;

use App\Models\PatientToothStatusHistory;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ToothStatusUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public PatientToothStatusHistory $history
    ) {}
}
