<?php

namespace App\Events;

use App\Models\Clinic;
use App\Models\CompanySubscription;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SubscriptionRenewed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Clinic $clinic,
        public CompanySubscription $subscription
    ) {}
}
