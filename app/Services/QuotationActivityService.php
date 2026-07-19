<?php

namespace App\Services;

use App\Models\CompanyEmployee;
use App\Models\CompanyQuotation;
use App\Models\CompanyQuotationActivity;

class QuotationActivityService
{
    /**
     * Log a new activity for the given quotation.
     */
    public function logActivity(
        CompanyQuotation $quotation,
        string $event,
        ?string $description = null,
        ?CompanyEmployee $employee = null
    ): CompanyQuotationActivity {
        return CompanyQuotationActivity::create([
            'company_quotation_id' => $quotation->id,
            'company_employee_id' => $employee?->id,
            'event' => $event,
            'description' => $description,
        ]);
    }
}
