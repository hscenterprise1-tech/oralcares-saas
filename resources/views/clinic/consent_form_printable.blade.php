@php
    /**
     * A consent form to print and sign: the clinic's own wording of each consent (the version in force today),
     * the patient's identity, and lines for the patient's and a witness's signature. Printing it records nothing;
     * the consent is recorded afterwards (Patient 360 → Docs → Consents).
     *
     * @var \App\Models\Clinic $clinic
     * @var \App\Models\Branch|null $branch
     * @var \App\Models\Patient $patient
     * @var list<array{label: string, version: int, text: string}> $forms
     */
    $age = $patient->date_of_birth ? (int) $patient->date_of_birth->diffInYears(now()) : $patient->age;
    $branchAddress = $branch ? collect([$branch->address_line1, $branch->address_line2, $branch->city, $branch->state, $branch->pincode])->filter()->implode(', ') : null;
    $name = $clinic->display_name ?: $clinic->name;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consent form · {{ $patient->full_name }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #101828; font-size: 12px; line-height: 1.5; margin: 0; background: #f2f4f7; }
        .page { background: #fff; max-width: 190mm; margin: 16px auto; padding: 20px 24px; }
        .bar { text-align: center; padding: 12px; }
        .bar button { font: 600 13px Helvetica, Arial, sans-serif; padding: 10px 18px; border-radius: 999px; border: 0; background: #101828; color: #fff; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; }
        .clinic { font-size: 17px; font-weight: 700; }
        .muted { color: #475467; }
        .right { text-align: right; }
        .rule { border-top: 2px solid #101828; margin: 10px 0 12px; }
        .label { color: #475467; font-size: 10px; text-transform: uppercase; letter-spacing: 0.06em; }
        h1 { font-size: 15px; margin: 0 0 8px; text-transform: uppercase; letter-spacing: 0.06em; }
        .consent { margin: 18px 0 0; padding-top: 12px; border-top: 1px solid #d0d5dd; page-break-inside: avoid; }
        .consent h2 { font-size: 13px; margin: 0 0 4px; }
        .consent p { margin: 0 0 8px; white-space: pre-line; }
        .agree { margin: 8px 0 0; font-weight: 600; }
        .box { display: inline-block; width: 12px; height: 12px; border: 1.5px solid #101828; margin-right: 6px; vertical-align: -2px; }
        .sign { margin-top: 34px; }
        .sign td { width: 50%; padding-right: 24px; }
        .line { border-top: 1px solid #101828; padding-top: 4px; font-size: 10.5px; color: #475467; }
        .foot { margin-top: 18px; font-size: 10px; color: #667085; }
        @media print { body { background: #fff; } .page { margin: 0; max-width: none; padding: 0; } .bar { display: none; } }
    </style>
</head>
<body>
    <div class="bar"><button type="button" onclick="window.print()">Print</button></div>
    <div class="page">
        <table>
            <tr>
                <td>
                    <div class="clinic">{{ $name }}</div>
                    @if ($branchAddress)<div class="muted">{{ $branchAddress }}</div>@endif
                    @if ($branch?->phone)<div class="muted">{{ $branch->phone }}</div>@endif
                </td>
                <td class="right muted">Date: {{ \App\Support\ClinicClock::now()->format('d M Y') }}</td>
            </tr>
        </table>
        <div class="rule"></div>

        <h1>Patient consent</h1>
        <table>
            <tr>
                <td><span class="label">Patient</span><br><strong>{{ $patient->full_name }}</strong></td>
                <td><span class="label">UHID</span><br>{{ $patient->uhid ?? '—' }}</td>
                <td><span class="label">Age / sex</span><br>{{ collect([$age !== null ? $age.' y' : null, $patient->gender ? ucfirst($patient->gender) : null])->filter()->implode(' · ') ?: '—' }}</td>
                <td><span class="label">Mobile</span><br>{{ $patient->phone ?? '—' }}</td>
            </tr>
        </table>

        @foreach ($forms as $form)
            <div class="consent">
                <h2>{{ $form['label'] }}</h2>
                <p>{{ $form['text'] }}</p>
                <div class="agree"><span class="box"></span>I have read this, it has been explained to me, and I agree.</div>
            </div>
        @endforeach

        <table class="sign">
            <tr>
                <td><div class="line">Patient or guardian: signature and name</div></td>
                <td><div class="line">Witness (clinic staff): signature and name</div></td>
            </tr>
        </table>
        <div class="foot">Wording as in force on {{ \App\Support\ClinicClock::now()->format('d M Y') }}. After signing, the consent is recorded in the patient's file.</div>
    </div>
</body>
</html>
