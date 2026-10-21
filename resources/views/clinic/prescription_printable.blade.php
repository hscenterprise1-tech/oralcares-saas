@php
    /** @var \App\Models\Prescription $rx */
    $age = $patient->date_of_birth ? (int) $patient->date_of_birth->diffInYears(now()) : $patient->age;
    $branchAddress = $branch ? collect([$branch->address_line1, $branch->address_line2, $branch->city, $branch->state, $branch->pincode])->filter()->implode(', ') : null;
    $primary = $diagnoses->firstWhere('is_primary', true) ?? $diagnoses->first();
    $cancelled = $rx->status === \App\Models\Prescription::CANCELLED;
    // Stored in UTC; printed in the clinic's own time.
    $issuedOn = \App\Support\ClinicClock::fromUtc($rx->finalized_at ?? $rx->created_at);
    $finalizedAt = $rx->finalized_at ? \App\Support\ClinicClock::fromUtc($rx->finalized_at) : null;
    $cancelledAt = $rx->cancelled_at ? \App\Support\ClinicClock::fromUtc($rx->cancelled_at) : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescription {{ $rx->prescription_number }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #101828; font-size: 11.5px; line-height: 1.45; margin: 0; background: #f2f4f7; }
        .page { background: #fff; max-width: 148mm; margin: 16px auto; padding: 18px 20px; position: relative; }
        .bar { text-align: center; padding: 12px; }
        .bar button { font: 600 13px Helvetica, Arial, sans-serif; padding: 10px 18px; border-radius: 999px; border: 0; background: #101828; color: #fff; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; }
        .clinic { font-size: 16px; font-weight: 700; }
        .muted { color: #475467; }
        .right { text-align: right; }
        .rule { border-top: 2px solid #101828; margin: 10px 0 8px; }
        .thin { border-top: 1px solid #d0d5dd; margin: 8px 0; }
        .patient td { padding: 2px 0; }
        .label { color: #475467; font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em; }
        .rx { font-size: 22px; font-weight: 700; font-family: Georgia, serif; margin: 6px 0 4px; }
        .lines th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: #475467; border-bottom: 1px solid #d0d5dd; padding: 5px 4px; }
        .lines td { padding: 7px 4px; border-bottom: 1px solid #eaecf0; vertical-align: top; }
        .drug { font-weight: 700; }
        .sign { margin-top: 28px; }
        .stamp { font-size: 9.5px; color: #475467; }
        .void { position: absolute; top: 40%; left: 0; right: 0; text-align: center; font-size: 54px; font-weight: 700; color: rgba(217, 45, 32, 0.18); transform: rotate(-18deg); }
        @media print { body { background: #fff; } .page { margin: 0; max-width: none; padding: 0; } .no-print { display: none; } }
    </style>
</head>
<body>
@unless ($forDownload)
    <div class="bar no-print"><button type="button" onclick="window.print()">Print prescription</button></div>
@endunless

<div class="page">
    @if ($cancelled)
        <div class="void">CANCELLED</div>
    @endif

    <table class="head">
        <tr>
            <td>
                <div class="clinic">{{ $clinic->name }}</div>
                @if ($branch && $branch->name !== $clinic->name)<div>{{ $branch->name }}</div>@endif
                @if ($branchAddress)<div class="muted">{{ $branchAddress }}</div>@endif
                @if ($branch?->phone || $clinic->contact_phone)<div class="muted">Phone: {{ $branch?->phone ?: $clinic->contact_phone }}</div>@endif
            </td>
            <td class="right">
                @if ($doctor)
                    <div style="font-weight:700;font-size:13px">{{ $doctor->name }}</div>
                    @if ($practitioner?->qualifications)<div>{{ $practitioner->qualifications }}</div>@endif
                    @if ($practitioner?->specialty)<div class="muted">{{ $practitioner->specialty }}</div>@endif
                    @if ($practitioner?->registration_number)<div class="muted">Reg. No. {{ $practitioner->registration_number }}@if ($practitioner->registration_authority) ({{ $practitioner->registration_authority }})@endif</div>@endif
                @endif
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    <table class="patient">
        <tr>
            <td><span class="label">Patient</span><br><strong>{{ $patient->full_name }}</strong>
                <span class="muted">{{ collect([$age !== null ? $age.' y' : null, $patient->gender ? ucfirst($patient->gender) : null])->filter()->implode(' / ') }}</span></td>
            <td class="right"><span class="label">Date</span><br>{{ $issuedOn->format('d M Y') }}</td>
        </tr>
        <tr>
            <td><span class="label">Patient ID</span><br>{{ $patient->uhid ?? '—' }}</td>
            <td class="right"><span class="label">Prescription</span><br><strong>{{ $rx->prescription_number }}</strong></td>
        </tr>
    </table>

    @if ($primary)
        <div class="thin"></div>
        <div><span class="label">Diagnosis</span><br>{{ $primary->description }}@if ($primary->code) <span class="muted">({{ $primary->code }})</span>@endif</div>
    @endif

    <div class="rx">&#8478;</div>

    <table class="lines">
        <thead>
            <tr><th style="width:18px">#</th><th>Medicine</th><th>Dose</th><th>How often</th><th>For</th></tr>
        </thead>
        <tbody>
        @foreach ($rx->items as $index => $line)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>
                    <span class="drug">{{ $line->drug_name }}</span>
                    @if ($line->strength || $line->form)<span class="muted"> {{ collect([$line->strength, $line->form])->filter()->implode(' ') }}</span>@endif
                    @php($how = collect([$line->timing ? ($timings[$line->timing] ?? $line->timing) : null, $line->route && $line->route !== 'oral' ? ($routes[$line->route] ?? $line->route) : null, $line->instructions])->filter()->implode(' · '))
                    @if ($how)<div class="muted">{{ $how }}</div>@endif
                </td>
                <td>{{ $line->dosage }}</td>
                <td>{{ $frequencies[$line->frequency] ?? $line->frequency }}</td>
                <td>{{ $line->duration }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @if ($rx->notes)
        <div style="margin-top:12px"><span class="label">Advice</span><div style="white-space:pre-wrap">{{ $rx->notes }}</div></div>
    @endif

    @if ($followUpOn)
        <div style="margin-top:8px"><span class="label">Review on</span><br><strong>{{ $followUpOn->format('d M Y') }}</strong></div>
    @endif

    <table class="sign">
        <tr>
            <td class="stamp">
                @if ($cancelled)
                    Cancelled {{ $cancelledAt?->format('d M Y H:i') }} by {{ $rx->canceller?->name }}: {{ $rx->cancellation_reason }}
                @else
                    Finalised electronically {{ $finalizedAt?->format('d M Y H:i') }} · {{ substr((string) $rx->signature_hash, 0, 12) }}
                @endif
            </td>
            <td class="right" style="width:45%">
                <div style="border-top:1px solid #101828;padding-top:4px">{{ $doctor?->name ?? 'Prescriber' }}</div>
            </td>
        </tr>
    </table>
</div>
</body>
</html>
