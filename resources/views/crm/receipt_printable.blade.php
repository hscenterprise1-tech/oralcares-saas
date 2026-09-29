<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt {{ $ledgerEntry->receipt_number }} - OralCares</title>
    @include('crm.partials.document_styles')
    <style>
        .receipt-amount {
            text-align: center; margin: 26px 0; padding: 22px;
            background: var(--doc-navy); border-radius: 12px;
        }
        .receipt-amount .label { color: #B9CEDD; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; }
        .receipt-amount .value { color: #fff; font-size: 30px; font-weight: 800; margin-top: 6px; }
        .receipt-success {
            text-align: center; font-weight: 700; color: var(--doc-green-text);
            background: var(--doc-green-soft); border: 1px solid #A7EEC4; border-radius: 10px;
            padding: 12px; margin-bottom: 22px; font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="doc-print-bar doc-no-print">
        <button class="doc-print-button" onclick="window.print()">Print Receipt</button>
    </div>

    <div class="doc-page">
        @include('crm.partials.document_header', [
            'seller' => $seller,
            'docType' => 'PAYMENT RECEIPT',
            'metaLines' => array_filter([
                '<strong>#</strong> '.$ledgerEntry->receipt_number,
                'Date: '.($ledgerEntry->paid_at?->format('d M Y') ?? '-'),
            ]),
        ])

        <div class="doc-body">
            <div class="receipt-success">Payment Received Successfully</div>

            <div class="doc-card" style="margin-bottom: 22px;">
                <table class="doc-terms-grid">
                    <tr>
                        <td class="doc-term-label">Received From</td>
                        <td class="doc-term-value">{{ $quotation->lead->company_name ?? 'Client Organization' }}</td>
                    </tr>
                    <tr>
                        <td class="doc-term-label">Quotation Reference</td>
                        <td class="doc-term-value">{{ $quotation->quotation_number }} (v{{ $quotation->version }})</td>
                    </tr>
                    @if($ledgerEntry->invoice?->invoice_number)
                        <tr>
                            <td class="doc-term-label">Tax Invoice Reference</td>
                            <td class="doc-term-value">{{ $ledgerEntry->invoice->invoice_number }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td class="doc-term-label">Payment Method</td>
                        <td class="doc-term-value">{{ ucwords(str_replace('_', ' ', $ledgerEntry->payment_method)) }}</td>
                    </tr>
                    @if($ledgerEntry->transaction_reference)
                        <tr>
                            <td class="doc-term-label">Transaction Reference</td>
                            <td class="doc-term-value">{{ $ledgerEntry->transaction_reference }}</td>
                        </tr>
                    @endif
                </table>
            </div>

            <div class="receipt-amount">
                <div class="label">Amount Received</div>
                <div class="value">₹ {{ number_format($ledgerEntry->amount, 2) }}</div>
            </div>
        </div>

        @include('crm.partials.document_footer', [
            'seller' => $seller,
            'tagline' => 'This receipt confirms a successful payment only.',
        ])
    </div>
</body>
</html>
