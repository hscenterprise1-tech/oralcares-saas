<?php

namespace App\Listeners;

use App\Events\CustomerProvisioned;
use App\Mail\PasswordSetupMailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendCustomerWelcomeEmail
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(CustomerProvisioned $event): void
    {
        Mail::to($event->adminUser->email)
            ->send(new PasswordSetupMailable($event->adminUser));

        Log::info("Customer provisioned. Welcome email sent to {$event->adminUser->email} for org {$event->organization->name}");
    }
}
