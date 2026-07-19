<?php

namespace App\Events;

use App\Models\Leave;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LeaveRequested
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Leave $leave
    ) {}
}
