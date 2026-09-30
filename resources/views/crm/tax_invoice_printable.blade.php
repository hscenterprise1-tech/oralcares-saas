@php($quotation = $invoice->quotation)
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tax Invoice {{ $invoice->invoice_number }} - OralCares</title>
    @include('crm.partials.document_styles')
</head>
<body>
    <div class="doc-print-bar doc-no-print">
        <button class="doc-print-button" onclick="window.print()">Print Tax Invoice</button>
    </div>

    <div class="doc-page">
        @include('crm.partials.document_header', [
            'seller' => $seller,
            'docType' => 'TAX INVOICE',
            'metaLines' => array_filter([
                '<strong>#</strong> '.$invoice->invoice_number,
                'Issued: '.($invoice->issued_at?->format('d M Y') ?? '-'),
                $quotation ? 'Quotation Ref: '.$quotation->quotation_number.' (v'.$quotation->version.')' : null,
            ]),
        ])

        <div class="doc-body">
            <div class="doc-banner doc-banner-good">
                {{ config('commercial.tax_invoice_note') }}
            </div>

            @include('crm.partials.document_parties', [
                'leftEyebrow' => 'Billed To',
                'leftTitle' => $quotation?->lead->company_name ?? 'Client Organization',
                'leftLines' => array_filter([
                    $quotation?->lead->contact_name ? 'Contact: '.$quotation->lead->contact_name : null,
                    $quotation?->lead->phone ? 'Phone: '.$quotation->lead->phone : null,
                    $quotation?->lead->email ? 'Email: '.$quotation->lead->email : null,
                    $invoice->buyer_gstin ? 'GSTIN: '.$invoice->buyer_gstin : null,
                    $invoice->buyer_state ? 'State: '.$invoice->buyer_state : null,
                ]),
                'rightEyebrow' => 'Invoice Status',
                'rightTitle' => strtoupper($invoice->status),
                'rightLines' => array_filter([
                    'Due: '.($invoice->due_date?->format('d M Y') ?? '-'),
                    'Paid: '.($invoice->paid_at?->format('d M Y') ?? 'Not yet paid'),
                ]),
            ])

            @if($quotation)
                @include('crm.partials.document_items', ['items' => $quotation->items])
            @endif

            {{--
                Every figure below comes from the invoice's own snapshotted
                columns, not the live quotation — a tax invoice is a legal
                record of what was true the moment it was issued.
            --}}
            @include('crm.partials.document_totals', [
                'subtotal' => (float) $invoice->amount,
                'discount' => (float) $invoice->discount_amount,
                'discountLabel' => 'Discount',
                'hasGst' => (bool) $invoice->tax_type,
                'gstType' => $invoice->tax_type,
                'gstRate' => (float) $invoice->gst_rate,
                'cgst' => (float) $invoice->cgst_amount,
                'sgst' => (float) $invoice->sgst_amount,
                'igst' => (float) $invoice->igst_amount,
                'taxableAmount' => (float) $invoice->taxable_amount,
                'flatTax' => (float) $invoice->tax_amount,
                'grandTotal' => (float) $invoice->final_amount,
                'grandTotalLabel' => 'Grand Total',
            ])

            @if($quotation)
                @include('crm.partials.document_terms', ['quotation' => $quotation])
            @endif

            @include('crm.partials.document_payment', ['seller' => $seller])
        </div>

        @include('crm.partials.document_footer', [
            'seller' => $seller,
            'tagline' => 'Tax Invoice — GSTIN '.($invoice->seller_gstin ?? 'N/A'),
        ])
    </div>
</body>
</html>
