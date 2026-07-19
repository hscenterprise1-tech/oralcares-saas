<?php

namespace App\Events;

use App\Models\Clinic;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LimitReached
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Clinic $clinic,
        public string $resourceType,
        public int $limit,
        public int $currentUsage
    ) {}
}
