<?php

namespace App\Services;

use App\Models\CompanyEmployee;
use App\Models\CompanyOpportunity;
use App\Models\CompanySalesActivity;

class SalesActivityService
{
    /**
     * Log a sales activity event.
     */
    public function logActivity(
        CompanyOpportunity $opportunity,
        string $event,
        ?string $description = null,
        ?CompanyEmployee $employee = null
    ): CompanySalesActivity {
        return CompanySalesActivity::create([
            'opportunity_id' => $opportunity->id,
            'company_employee_id' => $employee?->id,
            'event' => $event,
            'description' => $description,
        ]);
    }
}
