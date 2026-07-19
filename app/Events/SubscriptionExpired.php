<?php

namespace App\Events;

use App\Models\Clinic;
use App\Models\CompanySubscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionExpired
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Clinic $clinic,
        public CompanySubscription $subscription
    ) {}
}
