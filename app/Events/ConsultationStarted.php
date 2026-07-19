<?php

namespace App\Events;

use App\Models\ClinicalConsultation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConsultationStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ClinicalConsultation $consultation
    ) {}
}
