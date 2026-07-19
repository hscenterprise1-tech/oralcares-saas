<?php

namespace App\Events;

use App\Models\ResourceAllocation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ResourceAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ResourceAllocation $allocation
    ) {}
}
