<?php

/*
|--------------------------------------------------------------------------
| Clinic document numbering
|--------------------------------------------------------------------------
|
| Every human-facing clinic document number comes from one place
| (`App\Services\Numbering\ClinicDocumentNumbers`), issued from a locked
| per-scope sequence (`NumberingEngine`) — never a random string and never a
| count of existing rows.
|
| `series` decides whose counter a document uses:
|   branch        one series per branch — financial documents, because a
|                 branch is the unit a GST registration and a cash drawer
|                 belong to (invoice, receipt, refund)
|   organization  one series per customer organisation, shared by its
|                 clinics — clinical documents (encounter, prescription,
|                 orders, imaging, lab work)
|
| A number reads `{prefix}{fy}-{number}`, e.g. INV-2627-00001 for the first
| invoice of financial year 2026-27. Every series restarts at 1 when the
| financial year turns. A branch may override its invoice prefix (onboarding
| stores it on the branch's sequence); the defaults below apply otherwise.
|
*/

return [

    /** First month of the financial year (India: April). */
    'financial_year_start_month' => (int) env('CLINIC_FINANCIAL_YEAR_START_MONTH', 4),

    'pattern' => '{prefix}{fy}-{number}',

    'documents' => [
        'invoice' => ['prefix' => 'INV-', 'padding' => 5, 'series' => 'branch'],
        'receipt' => ['prefix' => 'RCT-', 'padding' => 5, 'series' => 'branch'],
        'refund' => ['prefix' => 'RFD-', 'padding' => 5, 'series' => 'branch'],
        'encounter' => ['prefix' => 'ENC-', 'padding' => 6, 'series' => 'organization'],
        'prescription' => ['prefix' => 'RX-', 'padding' => 6, 'series' => 'organization'],
        'clinical_order' => ['prefix' => 'ORD-', 'padding' => 6, 'series' => 'organization'],
        'imaging' => ['prefix' => 'IMG-', 'padding' => 6, 'series' => 'organization'],
        'lab_order' => ['prefix' => 'LAB-', 'padding' => 6, 'series' => 'organization'],
    ],

];
