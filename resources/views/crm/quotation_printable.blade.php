<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation {{ $quotation->quotation_number }} (v{{ $quotation->version }}) - OralCares</title>
    @include('crm.partials.document_styles')
</head>
<body>
    <div class="doc-print-bar doc-no-print">
        <button class="doc-print-button" onclick="window.print()">Print Quotation</button>
    </div>

    <div class="doc-page">
        @include('crm.partials.document_header', [
            'seller' => $seller,
            'docType' => 'QUOTATION',
            'metaLines' => array_filter([
                '<strong>#</strong> '.$quotation->quotation_number.' (v'.$quotation->version.')',
                'Date: '.$quotation->created_at->format('d M Y'),
                'Valid until: '.($quotation->valid_until ? $quotation->valid_until->format('d M Y') : 'Not specified'),
                $quotation->sent_at ? 'Sent: '.$quotation->sent_at->format('d M Y') : null,
                $quotation->companyPlan?->name ? 'Package: '.$quotation->companyPlan->name : null,
            ]),
        ])

        <div class="doc-body">
            @include('crm.partials.document_parties', [
                'leftEyebrow' => 'Prepared For',
                'leftTitle' => $quotation->lead->company_name ?? 'Prospective Client',
                'leftLines' => array_filter([
                    $quotation->lead?->contact_name,
                    $quotation->lead?->email,
                    $quotation->lead?->phone,
                    collect([$quotation->lead?->address, $quotation->lead?->city, $quotation->lead?->state, $quotation->lead?->postal_code])->filter()->implode(', ') ?: null,
                    ($quotation->lead->gstin ?? null) ? 'GSTIN: '.$quotation->lead->gstin : null,
                ]),
                'rightEyebrow' => 'Document Details',
                'rightTitle' => 'Status: '.strtoupper($quotation->status->label()),
                'rightLines' => array_filter([
                    'Prepared by: '.($quotation->preparedBy?->user?->name ?? 'OralCares Sales Team'),
                    $quotation->approved_at ? 'Approved: '.$quotation->approved_at->format('d M Y') : null,
                    $quotation->rejected_at ? 'Rejected: '.$quotation->rejected_at->format('d M Y') : null,
                ]),
            ])

            @include('crm.partials.document_items', ['items' => $quotation->items])

            @include('crm.partials.document_totals', [
                'subtotal' => (float) $quotation->subtotal,
                'discount' => (float) $quotation->discount,
                'discountLabel' => 'Discount'.($quotation->discount_type === 'percentage' && $quotation->discount_value ? ' · '.rtrim(rtrim(number_format((float) $quotation->discount_value, 2), '0'), '.').'%' : ''),
                'hasGst' => $quotation->hasGstBreakdown(),
                'gstType' => $quotation->tax_type,
                'gstRate' => (float) $quotation->gst_rate,
                'cgst' => (float) $quotation->cgst_amount,
                'sgst' => (float) $quotation->sgst_amount,
                'igst' => (float) $quotation->igst_amount,
                'taxableAmount' => (float) $quotation->taxable_amount,
                'flatTax' => (float) $quotation->tax,
                'grandTotal' => (float) $quotation->grand_total,
                'grandTotalLabel' => 'Grand Total',
            ])

            @include('crm.partials.document_terms', ['quotation' => $quotation])

            @if($quotation->notes)
                <div class="doc-section">
                    <p class="doc-section-title">Internal Notes</p>
                    <div class="doc-notes-card">{!! nl2br(e($quotation->notes)) !!}</div>
                </div>
            @endif

            @include('crm.partials.document_payment', ['seller' => $seller])
        </div>

        @include('crm.partials.document_footer', [
            'seller' => $seller,
            'tagline' => 'This quotation is confidential and intended solely for the named recipient.',
        ])
    </div>
</body>
</html>
