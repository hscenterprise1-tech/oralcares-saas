@php
    /**
     * The bill as a roll receipt for a 58 mm or 80 mm thermal printer, printed
     * through the browser's own print dialog (no printer driver or bridge is
     * involved). A receipt is only titled "Receipt" when money was taken; an
     * unpaid bill prints as a bill with its balance, never as a receipt.
     *
     * @var \App\Models\PatientBilling $bill
     * @var ?\App\Models\PatientBillingPayment $payment the payment this print is for, when one was chosen
     * @var int $width 58 or 80
     */
    $issuedOn = \App\Support\ClinicClock::fromUtc($bill->issued_at ?? $bill->created_at);
    $money = fn ($value) => '₹'.number_format((float) $value, 2);
    $branchAddress = $branch ? collect([$branch->address_line1, $branch->address_line2, $branch->city, $branch->state, $branch->pincode])->filter()->implode(', ') : null;
    $cancelled = $bill->status === \App\BillingStatus::Cancelled;
    $payments = $payment ? collect([$payment]) : $bill->payments;
    $isReceipt = $payments->isNotEmpty() && ! $cancelled;
    $printable = $width === 58 ? '48mm' : '72mm';
    $when = $width === 58 ? 'd/m/y h:i A' : 'd M Y, h:i A';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $isReceipt ? 'Receipt' : 'Bill' }} {{ $payment?->receipt_number ?? $bill->billing_number }}</title>
    <style>
        @page { size: {{ $width }}mm auto; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f2f4f7; color: #000; font-family: 'DejaVu Sans Mono', 'Courier New', monospace; font-size: {{ $width === 58 ? '10px' : '11.5px' }}; line-height: 1.35; }
        .roll { width: {{ $printable }}; margin: 12px auto; background: #fff; padding: 3mm {{ $width === 58 ? '2mm' : '3mm' }}; }
        .bar { text-align: center; padding: 10px; font: 13px Helvetica, Arial, sans-serif; }
        .bar a, .bar button { font: 600 13px Helvetica, Arial, sans-serif; padding: 8px 14px; border-radius: 999px; border: 1px solid #101828; background: #fff; color: #101828; cursor: pointer; text-decoration: none; margin: 0 2px; display: inline-block; }
        .bar .primary { background: #101828; color: #fff; }
        .bar .on { background: #e4e7ec; }
        .c { text-align: center; }
        .b { font-weight: 700; }
        .big { font-size: 1.25em; }
        .rule { border-top: 1px dashed #000; margin: 4px 0; }
        .row { display: flex; justify-content: space-between; gap: 6px; }
        .row > :first-child { min-width: 0; overflow-wrap: anywhere; }
        .row > :last-child { white-space: nowrap; text-align: right; }
        .void { text-align: center; font-weight: 700; border: 1px solid #000; padding: 2px; margin: 4px 0; }
        @media print { body { background: #fff; } .roll { margin: 0; } .no-print { display: none; } }
    </style>
</head>
<body>
<div class="bar no-print">
    <button type="button" class="primary" onclick="window.print()">Print</button>
    <a href="{{ request()->fullUrlWithQuery(['width' => 80]) }}" class="{{ $width === 80 ? 'on' : '' }}">80 mm</a>
    <a href="{{ request()->fullUrlWithQuery(['width' => 58]) }}" class="{{ $width === 58 ? 'on' : '' }}">58 mm</a>
    <a href="{{ route('clinic.billing.bills.print', $bill) }}">A4 bill</a>
    <div style="margin-top:6px;color:#475467;font-size:12px">Choose your receipt printer and set margins to “None” in the print dialog.</div>
</div>
<div class="roll">
    <div class="c b big">{{ $clinic->name }}</div>
    @if ($branch && $branch->name !== $clinic->name)<div class="c">{{ $branch->name }}</div>@endif
    @if ($branchAddress)<div class="c">{{ $branchAddress }}</div>@endif
    @if ($branch?->phone || $clinic->contact_phone)<div class="c">Ph: {{ $branch?->phone ?: $clinic->contact_phone }}</div>@endif
    <div class="rule"></div>
    <div class="c b">{{ $isReceipt ? 'PAYMENT RECEIPT' : 'BILL' }}</div>
    @if ($cancelled)<div class="void">CANCELLED</div>@endif
    @if ($payment)
        <div class="row"><span>Receipt</span><span class="b">{{ $payment->receipt_number }}</span></div>
        <div class="row"><span>Date</span><span>{{ \App\Support\ClinicClock::fromUtc($payment->paid_at ?? $payment->created_at)->format($when) }}</span></div>
    @endif
    <div class="row"><span>Bill</span><span>{{ $bill->billing_number }}</span></div>
    <div class="row"><span>Bill date</span><span>{{ $issuedOn->format($when) }}</span></div>
    <div class="rule"></div>
    <div class="b">{{ $patient->full_name }}</div>
    @if ($patient->uhid)<div>{{ $patient->uhid }}</div>@endif
    @if ($bill->doctor?->name)<div>Doctor: {{ $bill->doctor->name }}</div>@endif
    <div class="rule"></div>
    @foreach ($bill->items as $item)
        <div>{{ $item->description }}</div>
        <div class="row"><span>&nbsp;{{ $item->quantity }} x {{ $money($item->unit_price) }}</span><span>{{ $money($item->final_amount) }}</span></div>
    @endforeach
    <div class="rule"></div>
    @if ((float) $bill->discount_amount > 0)<div class="row"><span>Discount</span><span>- {{ $money($bill->discount_amount) }}</span></div>@endif
    @if ((float) $bill->tax_amount > 0)<div class="row"><span>Tax</span><span>{{ $money($bill->tax_amount) }}</span></div>@endif
    <div class="row b"><span>Total</span><span>{{ $money($bill->final_amount) }}</span></div>
    @foreach ($payments as $paid)
        <div class="row"><span>Paid · {{ $paid->payment_method?->label() }}@unless ($payment) · {{ $paid->receipt_number }}@endunless</span><span>{{ $money($paid->amount) }}</span></div>
    @endforeach
    @if ((float) $bill->refunded_amount > 0)<div class="row"><span>Refunded</span><span>{{ $money($bill->refunded_amount) }}</span></div>@endif
    <div class="row b"><span>Balance due</span><span>{{ $money($bill->outstanding_balance) }}</span></div>
    <div class="rule"></div>
    <div class="c">{{ $isReceipt ? 'Thank you.' : 'This is not a receipt.' }}</div>
</div>
</body>
</html>
