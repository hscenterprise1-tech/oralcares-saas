@php
    /** @var \App\Models\PatientBilling $bill */
    $issuedOn = \App\Support\ClinicClock::fromUtc($bill->issued_at ?? $bill->created_at);
    $money = fn ($value) => '₹'.number_format((float) $value, 2);
    $branchAddress = $branch ? collect([$branch->address_line1, $branch->address_line2, $branch->city, $branch->state, $branch->pincode])->filter()->implode(', ') : null;
    $cancelled = $bill->status === \App\BillingStatus::Cancelled;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bill {{ $bill->billing_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #101828; font-size: 12px; line-height: 1.45; margin: 0; background: #f2f4f7; }
        .page { background: #fff; max-width: 190mm; margin: 16px auto; padding: 22px 24px; position: relative; }
        .bar { text-align: center; padding: 12px; }
        .bar button { font: 600 13px Helvetica, Arial, sans-serif; padding: 10px 18px; border-radius: 999px; border: 0; background: #101828; color: #fff; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #475467; }
        .right { text-align: right; }
        .rule { border-top: 2px solid #101828; margin: 12px 0 10px; }
        .label { color: #475467; font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em; }
        .lines th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: #475467; border-bottom: 1px solid #d0d5dd; padding: 6px 4px; }
        .lines td { padding: 7px 4px; border-bottom: 1px solid #eaecf0; }
        .totals td { padding: 3px 4px; }
        .void { position: absolute; top: 35%; left: 0; right: 0; text-align: center; font-size: 60px; font-weight: 700; color: rgba(217, 45, 32, 0.16); transform: rotate(-18deg); }
        @media print { body { background: #fff; } .page { margin: 0; max-width: none; } .no-print { display: none; } }
    </style>
</head>
<body>
<div class="bar no-print"><button type="button" onclick="window.print()">Print</button></div>
<div class="page">
    @if ($cancelled)<div class="void">CANCELLED</div>@endif
    <table>
        <tr>
            <td>
                <div style="font-size:17px;font-weight:700">{{ $clinic->name }}</div>
                @if ($branch && $branch->name !== $clinic->name)<div>{{ $branch->name }}</div>@endif
                @if ($branchAddress)<div class="muted">{{ $branchAddress }}</div>@endif
                @if ($branch?->phone || $clinic->contact_phone)<div class="muted">Phone: {{ $branch?->phone ?: $clinic->contact_phone }}</div>@endif
            </td>
            <td class="right">
                <div style="font-size:15px;font-weight:700">BILL</div>
                <div><strong>{{ $bill->billing_number }}</strong></div>
                <div class="muted">{{ $issuedOn->format('d M Y, h:i A') }}</div>
            </td>
        </tr>
    </table>
    <div class="rule"></div>
    <table>
        <tr>
            <td><span class="label">Patient</span><br><strong>{{ $patient->full_name }}</strong> <span class="muted">{{ $patient->uhid }}</span></td>
            <td class="right"><span class="label">Doctor</span><br>{{ $bill->doctor?->name ?? '—' }}</td>
        </tr>
    </table>

    <table class="lines" style="margin-top:12px">
        <thead><tr><th>Item</th><th class="right">Qty</th><th class="right">Rate</th><th class="right">Discount</th>@if ((float) $bill->tax_amount > 0)<th class="right">Tax</th>@endif<th class="right">Amount</th></tr></thead>
        <tbody>
        @foreach ($bill->items as $item)
            <tr>
                <td>{{ $item->description }}</td>
                <td class="right">{{ $item->quantity }}</td>
                <td class="right">{{ $money($item->unit_price) }}</td>
                <td class="right">{{ (float) $item->discount_amount > 0 ? $money($item->discount_amount) : '—' }}</td>
                @if ((float) $bill->tax_amount > 0)<td class="right">{{ $money($item->tax_amount) }}</td>@endif
                <td class="right">{{ $money($item->final_amount) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals" style="margin-top:10px;width:55%;margin-left:45%">
        <tr><td class="muted">Subtotal</td><td class="right">{{ $money($bill->total_amount) }}</td></tr>
        @if ((float) $bill->discount_amount > 0)<tr><td class="muted">Discount</td><td class="right">− {{ $money($bill->discount_amount) }}</td></tr>@endif
        @if ((float) $bill->tax_amount > 0)<tr><td class="muted">Tax</td><td class="right">{{ $money($bill->tax_amount) }}</td></tr>@endif
        <tr><td><strong>Total</strong></td><td class="right"><strong>{{ $money($bill->final_amount) }}</strong></td></tr>
        <tr><td class="muted">Paid</td><td class="right">{{ $money($bill->paid_amount) }}</td></tr>
        @if ((float) $bill->refunded_amount > 0)<tr><td class="muted">Refunded</td><td class="right">{{ $money($bill->refunded_amount) }}</td></tr>@endif
        <tr><td><strong>Balance due</strong></td><td class="right"><strong>{{ $money($bill->outstanding_balance) }}</strong></td></tr>
    </table>

    @if ($bill->payments->isNotEmpty())
        <div style="margin-top:16px"><span class="label">Payments received</span></div>
        <table class="lines">
            @foreach ($bill->payments as $payment)
                <tr>
                    <td>Receipt {{ $payment->receipt_number }}</td>
                    <td>{{ $payment->payment_method?->label() }}</td>
                    <td>{{ \App\Support\ClinicClock::fromUtc($payment->paid_at ?? $payment->created_at)->format('d M Y, h:i A') }}</td>
                    <td class="right">{{ $money($payment->amount) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($bill->refunds->isNotEmpty())
        <div style="margin-top:12px"><span class="label">Refunds</span></div>
        <table class="lines">
            @foreach ($bill->refunds as $refund)
                <tr><td>{{ $refund->refund_number }}</td><td>{{ $refund->reason }}</td><td class="right">{{ $money($refund->amount) }}</td></tr>
            @endforeach
        </table>
    @endif
</div>
</body>
</html>
