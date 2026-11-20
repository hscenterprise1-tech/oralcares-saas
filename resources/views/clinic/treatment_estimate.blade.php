@php
    /** @var \App\Models\TreatmentPlan $plan */
    $money = fn ($value) => '₹'.number_format((float) $value, 2);
    $branchAddress = $branch ? collect([$branch->address_line1, $branch->address_line2, $branch->city, $branch->state, $branch->pincode])->filter()->implode(', ') : null;
    $printedOn = \App\Support\ClinicClock::now();
    $priced = $items->filter(fn ($item) => $item->price !== null);
    $unpriced = $items->count() - $priced->count();
    $total = $priced->sum(fn ($item) => (float) $item->price);
    $done = $items->filter(fn ($item) => $item->status === \App\TreatmentStatus::Completed);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Treatment estimate — {{ $patient->full_name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #101828; font-size: 12px; line-height: 1.45; margin: 0; background: #f2f4f7; }
        .page { background: #fff; max-width: 190mm; margin: 16px auto; padding: 22px 24px; }
        .bar { text-align: center; padding: 12px; }
        .bar button { font: 600 13px Helvetica, Arial, sans-serif; padding: 10px 18px; border-radius: 999px; border: 0; background: #101828; color: #fff; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #475467; }
        .right { text-align: right; }
        .rule { border-top: 2px solid #101828; margin: 12px 0 10px; }
        .label { color: #475467; font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em; }
        .lines th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: #475467; border-bottom: 1px solid #d0d5dd; padding: 6px 4px; }
        .lines td { padding: 7px 4px; border-bottom: 1px solid #eaecf0; }
        .note { margin-top: 14px; padding: 10px 12px; border: 1px solid #eaecf0; border-radius: 8px; color: #475467; font-size: 11px; }
        .sign td { padding-top: 38px; font-size: 11px; color: #475467; }
        @media print { body { background: #fff; } .page { margin: 0; max-width: none; } .no-print { display: none; } }
    </style>
</head>
<body>
<div class="bar no-print"><button type="button" onclick="window.print()">Print</button></div>
<div class="page">
    <table>
        <tr>
            <td>
                <div style="font-size:17px;font-weight:700">{{ $clinic->name }}</div>
                @if ($branch && $branch->name !== $clinic->name)<div>{{ $branch->name }}</div>@endif
                @if ($branchAddress)<div class="muted">{{ $branchAddress }}</div>@endif
                @if ($branch?->phone || $clinic->contact_phone)<div class="muted">Phone: {{ $branch?->phone ?: $clinic->contact_phone }}</div>@endif
            </td>
            <td class="right">
                <div style="font-size:15px;font-weight:700">TREATMENT ESTIMATE</div>
                <div class="muted">{{ $printedOn->format('d M Y') }}</div>
            </td>
        </tr>
    </table>
    <div class="rule"></div>
    <table>
        <tr>
            <td><span class="label">Patient</span><br><strong>{{ $patient->full_name }}</strong> <span class="muted">{{ $patient->uhid }}</span></td>
            <td class="right"><span class="label">Plan</span><br>{{ $plan->title }}</td>
        </tr>
    </table>

    <table class="lines" style="margin-top:12px">
        <thead><tr><th>#</th><th>Treatment</th><th>Tooth</th><th class="right">Estimate</th></tr></thead>
        <tbody>
        @foreach ($items as $item)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $item->description }}@if ($item->status === \App\TreatmentStatus::Completed) <span class="muted">(done)</span>@endif</td>
                <td>{{ $item->tooth_number ? $item->tooth_number.($item->surfaces ? ' ('.implode('', $item->surfaces).')' : '') : '—' }}</td>
                <td class="right">{{ $item->price !== null ? $money($item->price) : 'To be confirmed' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table style="margin-top:10px;width:55%;margin-left:45%">
        <tr><td><strong>Estimated total</strong></td><td class="right"><strong>{{ $money($total) }}</strong></td></tr>
        @if ($done->isNotEmpty())<tr><td class="muted">Already done</td><td class="right">{{ $money($done->sum(fn ($item) => (float) $item->price)) }}</td></tr>@endif
    </table>

    <div class="note">
        This is an estimate at today's prices, not a bill. @if ($unpriced > 0){{ $unpriced }} {{ $unpriced === 1 ? 'item has' : 'items have' }} no price yet and {{ $unpriced === 1 ? 'is' : 'are' }} not in the total. @endif
        The final amount depends on what the dentist finds during treatment and is billed when each treatment is done.
    </div>

    <table class="sign"><tr><td>Patient's signature</td><td class="right">Dentist / Clinic</td></tr></table>
</div>
</body>
</html>
