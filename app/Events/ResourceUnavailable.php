<?php

namespace App\Events;

use App\Models\Resource;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ResourceUnavailable
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Resource $resource,
        public string $startDate,
        public string $endDate
    ) {}
}
