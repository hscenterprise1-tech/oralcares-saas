<?php

namespace App\Events;

use App\Models\Shift;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShiftUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Shift $shift
    ) {}
}
