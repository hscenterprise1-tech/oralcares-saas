<?php

namespace App\Events;

use App\Models\ClinicQueue;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QueueUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ClinicQueue $queue
    ) {}
}
