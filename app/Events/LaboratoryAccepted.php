<?php

namespace App\Events;

use App\Models\LabOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LaboratoryAccepted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public LabOrder $labOrder
    ) {}
}
