<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default GST Rate
    |--------------------------------------------------------------------------
    |
    | The rate applied to a quotation's taxable amount when it is first
    | created — 18% is the standard GST rate for software/SaaS services in
    | India (HSN/SAC 9983-family, information technology services). Stored on
    | the quotation itself at that moment (`company_quotations.gst_rate`), so
    | changing this later never rewrites a quotation someone has already
    | quoted or invoiced against.
    |
    */
    'default_gst_rate' => env('COMMERCIAL_DEFAULT_GST_RATE', 18.00),

    /*
    |--------------------------------------------------------------------------
    | Proforma / Tax Invoice Notes
    |--------------------------------------------------------------------------
    |
    | Standard legal boilerplate for a Tax Invoice, distinct from a
    | quotation's own negotiable `terms` column. Configurable rather than
    | hardcoded into the PDF template, since it is a compliance statement an
    | operator may need to adjust without a code change.
    |
    */
    'tax_invoice_note' => env(
        'COMMERCIAL_TAX_INVOICE_NOTE',
        'This is a computer-generated tax invoice issued under Section 31 of the CGST Act, 2017. Subject to realisation of payment instrument.'
    ),

    'proforma_note' => env(
        'COMMERCIAL_PROFORMA_NOTE',
        'This is a Proforma Invoice for reference purposes only. It is not a demand for payment under the CGST Act and does not constitute a Tax Invoice.'
    ),

    /*
    |--------------------------------------------------------------------------
    | Discount Approval — Admin Direct Limit
    |--------------------------------------------------------------------------
    |
    | The percentage an admin-tier user (master admin, or anyone holding
    | `crm.quotations.approve`) may discount on their own authority, with no
    | approval step. Employees keep following the existing configurable
    | `crm.quotation_approval_discount_percent` threshold — this is a second,
    | higher tier layered on top of it, not a replacement.
    |
    | A hardcoded business rule for now rather than another env-driven knob:
    | flagged as a Future Settings Candidate for the later Settings Audit
    | rather than given its own config surface prematurely.
    |
    */
    'admin_direct_discount_percent' => 20.00,

    /*
    |--------------------------------------------------------------------------
    | Default Quotation Terms
    |--------------------------------------------------------------------------
    |
    | Starting values `LeadWorkspaceService::createQuotation()` writes onto a
    | new quotation's structured terms columns, the same way it already
    | seeded the single `terms` column before Phase 2B split it into named
    | sections. Content lives here — never hardcoded into a Blade template —
    | so a later Settings module can make these tenant-configurable without
    | touching the document layer at all.
    |
    */
    'default_terms' => [
        'general' => '1. Proposal valid for 30 days. 2. 100% advance payment required for license activation.',
        'payment' => 'Payment due in full prior to activation, via bank transfer or UPI to the account details on this document.',
        'implementation' => 'Setup and onboarding begin within 3 business days of payment confirmation.',
        'support' => 'Includes standard support for the subscription term. Annual Maintenance Contract (AMC) terms, where applicable, are quoted separately.',
        'cancellation' => 'Advance payments are non-refundable once implementation has begun. Cancellation before implementation starts is refundable in full.',
    ],

];
