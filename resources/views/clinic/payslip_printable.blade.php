@php
    /** @var \App\Models\ClinicPayrollEntry $entry */
    $money = fn ($value) => '₹'.number_format(abs((float) $value), 2);
    $earnings = $entry->lines->filter(fn ($line) => (float) $line->amount > 0);
    $deductions = $entry->lines->filter(fn ($line) => (float) $line->amount < 0);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslip {{ $entry->payslip_number }}</title>
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
        .lines td { padding: 6px 4px; border-bottom: 1px solid #eaecf0; vertical-align: top; }
        .net td { padding: 8px 4px; font-size: 15px; font-weight: 700; border-top: 2px solid #101828; }
        @media print { body { background: #fff; } .page { margin: 0; max-width: none; } .no-print { display: none; } }
    </style>
</head>
<body>
<div class="bar no-print"><button type="button" onclick="window.print()">Print</button></div>
<div class="page">
    <table>
        <tr>
            <td>
                <div style="font-size:17px;font-weight:700">{{ $clinic->legal_name ?: $clinic->name }}</div>
                <div class="muted">Payslip · {{ $entry->period->period_start->format('F Y') }}</div>
            </td>
            <td class="right">
                <div style="font-size:15px;font-weight:700">PAYSLIP</div>
                <div><strong>{{ $entry->payslip_number ?? 'Draft' }}</strong></div>
                <div class="muted">{{ $entry->paid_at ? 'Paid '.\App\Support\ClinicClock::fromUtc($entry->paid_at)->format('d M Y').($entry->payment_method ? ' · '.ucfirst($entry->payment_method) : '') : 'Not yet paid' }}</div>
            </td>
        </tr>
    </table>
    <div class="rule"></div>
    <p><span class="label">Employee</span><br><strong>{{ $entry->employee?->name }}</strong></p>

    <table style="margin-top:10px">
        <tr>
            <td style="width:50%;vertical-align:top;padding-right:12px">
                <div class="label">Earnings</div>
                <table class="lines">
                    @forelse ($earnings as $line)
                        <tr><td>{{ $line->description }}</td><td class="right">{{ $money($line->amount) }}</td></tr>
                    @empty
                        <tr><td class="muted">None</td><td></td></tr>
                    @endforelse
                </table>
            </td>
            <td style="width:50%;vertical-align:top;padding-left:12px">
                <div class="label">Deductions</div>
                <table class="lines">
                    @forelse ($deductions as $line)
                        <tr><td>{{ $line->description }}</td><td class="right">{{ $money($line->amount) }}</td></tr>
                    @empty
                        <tr><td class="muted">None</td><td></td></tr>
                    @endforelse
                </table>
            </td>
        </tr>
    </table>

    <table style="margin-top:14px">
        <tr><td>Gross</td><td class="right">{{ $money($entry->gross) }}</td></tr>
        <tr><td>Deductions</td><td class="right">{{ $money($entry->deductions) }}</td></tr>
        <tr class="net"><td>Net pay</td><td class="right">{{ $money($entry->net) }}</td></tr>
    </table>
    <p class="muted" style="margin-top:18px;font-size:10px">Internal payslip. Statutory deductions (PF, ESI, TDS, professional tax) appear only where the clinic recorded them.</p>
</div>
</body>
</html>
