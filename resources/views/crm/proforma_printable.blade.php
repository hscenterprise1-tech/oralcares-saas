<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proforma Invoice {{ $quotation->proforma_number }} - OralCares</title>
    @include('crm.partials.document_styles')
</head>
<body>
    <div class="doc-print-bar doc-no-print">
        <button class="doc-print-button" onclick="window.print()">Print Proforma</button>
    </div>

    <div class="doc-page">
        @include('crm.partials.document_header', [
            'seller' => $seller,
            'docType' => 'PROFORMA INVOICE',
            'metaLines' => array_filter([
                '<strong>#</strong> '.$quotation->proforma_number,
                'Issued: '.($quotation->proforma_issued_at?->format('d M Y') ?? '-'),
                'Quotation Ref: '.$quotation->quotation_number.' (v'.$quotation->version.')',
            ]),
        ])

        <div class="doc-body">
            <div class="doc-banner doc-banner-warn">
                {{ config('commercial.proforma_note') }}
            </div>

            @include('crm.partials.document_parties', [
                'leftEyebrow' => 'Billed To',
                'leftTitle' => $quotation->lead->company_name ?? 'Client Organization',
                'leftLines' => array_filter([
                    $quotation->lead?->contact_name ? 'Contact: '.$quotation->lead->contact_name : null,
                    $quotation->lead?->phone ? 'Phone: '.$quotation->lead->phone : null,
                    $quotation->lead?->email ? 'Email: '.$quotation->lead->email : null,
                    ($quotation->lead->gstin ?? null) ? 'GSTIN: '.$quotation->lead->gstin : null,
                ]),
                'rightEyebrow' => 'Prepared By',
                'rightTitle' => $quotation->preparedBy?->user?->name ?? 'OralCares Sales Team',
                'rightLines' => array_filter([
                    $quotation->preparedBy?->user?->email ? 'Email: '.$quotation->preparedBy->user->email : null,
                ]),
            ])

            @include('crm.partials.document_items', ['items' => $quotation->items])

            @include('crm.partials.document_totals', [
                'subtotal' => (float) $quotation->subtotal,
                'discount' => (float) $quotation->discount,
                'discountLabel' => 'Discount',
                'hasGst' => $quotation->hasGstBreakdown(),
                'gstType' => $quotation->tax_type,
                'gstRate' => (float) $quotation->gst_rate,
                'cgst' => (float) $quotation->cgst_amount,
                'sgst' => (float) $quotation->sgst_amount,
                'igst' => (float) $quotation->igst_amount,
                'taxableAmount' => (float) $quotation->taxable_amount,
                'flatTax' => (float) $quotation->tax,
                'grandTotal' => (float) $quotation->grand_total,
                'grandTotalLabel' => 'Amount Payable',
            ])

            @include('crm.partials.document_terms', ['quotation' => $quotation])

            @include('crm.partials.document_payment', ['seller' => $seller])
        </div>

        @include('crm.partials.document_footer', [
            'seller' => $seller,
            'tagline' => 'Proforma Invoice — not a Tax Invoice.',
        ])
    </div>
</body>
</html>
